"""Read text aloud into an audio file with a locally pulled Hugging Face transformers model.

Compiled into a standalone binary with PyInstaller, so it runs without a Python
installation. Designed to be driven by the PHP wrapper:

  * stdout: one JSON object on success, e.g. {"output": "/abs/path/speech.wav", "seconds": 2.6}
  * stderr: progress and error messages for humans
  * exit code: 0 success, 1 generation failed, 2 invalid arguments

Works with transformers speech synthesis models that need no extra input, e.g. VITS and MMS
(facebook/mms-tts-eng, kakao-enterprise/vits-ljs) or Bark (suno/bark-small).

The audio is encoded with PyAV, which bundles FFmpeg's libraries: the output file's extension picks
the format (.wav, .mp3, .m4a, .flac, .ogg) without FFmpeg being installed.
"""

import argparse
import json
import multiprocessing
import os
import sys
import traceback

EXIT_OK = 0
EXIT_FAILED = 1

# Output file extension -> encoder. Opus only encodes at 48 kHz, so its audio is resampled.
CODECS = {
    "wav": "pcm_s16le",
    "mp3": "libmp3lame",
    "m4a": "aac",
    "flac": "flac",
    "ogg": "libopus",
    "opus": "libopus",
}


class UserError(Exception):
    """A problem the user can fix, reported without a stack trace."""


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="text-to-speech",
        description="Read text aloud into an audio file with a locally pulled transformers model.",
    )
    parser.add_argument("--model", required=True, help="Directory of a pulled transformers model (contains config.json)")
    parser.add_argument("--text", required=True, help="Text to read aloud")
    parser.add_argument("--output", required=True, help="Audio file to write; its extension picks the format, e.g. speech.wav")
    parser.add_argument("--voice", help="Voice of models that have several, e.g. a Bark preset like v2/en_speaker_6, or a speaker number")
    parser.add_argument("--speed", type=float, help="Speaking rate of VITS-style models, e.g. 0.8 slower, 1.2 faster (default: the model's own)")
    parser.add_argument("--seed", type=int, help="Random seed, for reproducible audio")
    parser.add_argument("--device", help="Torch device, e.g. cpu, cuda, mps (default: the best available)")
    return parser.parse_args(argv)


def log(message: str) -> None:
    print(message, file=sys.stderr, flush=True)


def write_audio(path: str, samples, sampling_rate: int) -> None:
    """Encodes mono float32 samples into path, in the format its extension asks for."""
    import av
    import numpy as np

    extension = os.path.splitext(path)[1].lower().lstrip(".")
    codec = CODECS.get(extension)
    if codec is None:
        raise UserError(f"cannot write .{extension} files; use one of: " + ", ".join(f".{name}" for name in CODECS))

    os.makedirs(os.path.dirname(os.path.abspath(path)) or ".", exist_ok=True)

    with av.open(path, mode="w") as container:
        rate = 48000 if codec == "libopus" else sampling_rate
        stream = container.add_stream(codec, rate=rate)
        stream.layout = "mono"

        frame = av.AudioFrame.from_ndarray(samples.reshape(1, -1).astype(np.float32), format="flt", layout="mono")
        frame.sample_rate = sampling_rate

        resampler = av.AudioResampler(format=stream.format, layout="mono", rate=rate)
        for resampled in [*resampler.resample(frame), *resampler.resample(None)]:
            for packet in stream.encode(resampled):
                container.mux(packet)

        for packet in stream.encode(None):
            container.mux(packet)


def main(argv: list[str]) -> int:
    args = parse_args(argv)

    # Models are loaded from disk only; never reach out to the Hub.
    os.environ["HF_HUB_OFFLINE"] = "1"

    # Imported after argument parsing so --help and usage errors stay fast.
    import numpy as np
    import torch
    from transformers import AutoModelForTextToWaveform, AutoProcessor, AutoTokenizer
    from transformers.utils import logging as transformers_logging

    # Progress bars redraw with carriage returns, which garbles pipes and log files.
    if not sys.stderr.isatty():
        transformers_logging.disable_progress_bar()

    try:
        device = args.device or best_device(torch)
        dtype = torch.float16 if device.startswith("cuda") else torch.float32

        log(f"[text-to-speech] Loading {args.model} on {device}...")
        # transformers' text-to-speech pipeline is not used: it fails on VITS models in current versions.
        model = AutoModelForTextToWaveform.from_pretrained(args.model, dtype=dtype, local_files_only=True).to(device)
        model.eval()

        try:
            processor = AutoProcessor.from_pretrained(args.model, local_files_only=True)
        except (OSError, ValueError):
            processor = AutoTokenizer.from_pretrained(args.model, local_files_only=True)

        if args.speed is not None:
            if not hasattr(model, "speaking_rate"):
                raise UserError("this model has no speaking rate to change; leave the speed out")
            model.speaking_rate = args.speed

        # Bark picks a voice while preparing the text ("v2/en_speaker_6"); VITS-style models by speaker number.
        text_options, speak_options = {}, {}
        if args.voice is not None:
            if model.config.model_type == "bark":
                text_options["voice_preset"] = args.voice
            elif args.voice.isdigit():
                speak_options["speaker_id"] = int(args.voice)
            else:
                raise UserError(f"this model names its voices by number, so '{args.voice}' cannot be used as a voice")

        inputs = processor(text=args.text, return_tensors="pt", **text_options)
        inputs = {name: tensor.to(device) for name, tensor in inputs.items() if hasattr(tensor, "to")}

        if args.seed is not None:
            torch.manual_seed(args.seed)

        log("[text-to-speech] Generating speech...")
        with torch.no_grad():
            if model.can_generate():
                waveform = model.generate(**inputs, **speak_options)
            else:
                spoken = model(**inputs, **speak_options)
                waveform = spoken.waveform if hasattr(spoken, "waveform") else spoken[0]

        audio = waveform.to(torch.float32).cpu().numpy().reshape(-1)
        sampling_rate = int(
            getattr(model.config, "sampling_rate", 0)
            or getattr(model.generation_config, "sample_rate", 0)
            or 16000
        )
        if audio.size == 0:
            raise UserError("the model produced no audio for this text")

        output = os.path.abspath(args.output)
        write_audio(output, audio, sampling_rate)
        seconds = audio.size / sampling_rate
    except UserError as e:
        log(f"[text-to-speech] Error: {e}")
        return EXIT_FAILED
    except Exception as e:
        traceback.print_exc()
        log(f"[text-to-speech] Error: {type(e).__name__}: {e}")
        return EXIT_FAILED

    print(json.dumps({"output": output, "seconds": round(seconds, 3)}), flush=True)
    log(f"[text-to-speech] Saved {output} ({seconds:.1f} seconds).")
    return EXIT_OK


def best_device(torch) -> str:
    if torch.cuda.is_available():
        return "cuda"
    if torch.backends.mps.is_available():
        return "mps"
    return "cpu"


if __name__ == "__main__":
    # torch starts multiprocessing helpers by re-running sys.executable, which in the frozen binary is this
    # script; freeze_support() lets those helper invocations run instead of reaching the argument parser.
    multiprocessing.freeze_support()
    sys.exit(main(sys.argv[1:]))
