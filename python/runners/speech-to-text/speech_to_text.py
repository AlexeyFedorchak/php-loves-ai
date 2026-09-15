"""Transcribe speech in an audio (or video) file with a locally pulled Hugging Face transformers model.

Compiled into a standalone binary with PyInstaller, so it runs without a Python
installation. Designed to be driven by the PHP wrapper:

  * stdout: one JSON object on success, e.g. {"text": "he hoped there would be stew for dinner"},
            plus "segments": [{"start": 0.0, "end": 2.5, "text": "..."}, ...] with --timestamps
  * stderr: progress and error messages for humans
  * exit code: 0 success, 1 transcription failed, 2 invalid arguments

Both kinds of speech recognition models are supported:
  * sequence-to-sequence models, e.g. Whisper; multilingual ones can be told the spoken language
    and can translate the speech into English
  * CTC models, e.g. Wav2Vec2, HuBERT; usually for a single language

Audio is decoded with PyAV, which bundles FFmpeg's libraries: WAV, MP3, M4A/AAC, FLAC, OGG/Opus
and the audio track of video files work without FFmpeg being installed.
"""

import argparse
import json
import multiprocessing
import os
import sys
import traceback

EXIT_OK = 0
EXIT_FAILED = 1

# Whisper reads 30-second windows; longer audio is transcribed window by window.
CHUNK_SECONDS = 30


class UserError(Exception):
    """A problem the user can fix, reported without a stack trace."""


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="speech-to-text",
        description="Transcribe speech in an audio or video file with a locally pulled transformers model.",
    )
    parser.add_argument("--model", required=True, help="Directory of a pulled transformers model (contains config.json)")
    parser.add_argument("--audio", required=True, help="Path of the audio or video file, e.g. interview.mp3")
    parser.add_argument("--language", help="Spoken language for multilingual models such as Whisper, e.g. en, french (default: detected)")
    parser.add_argument("--translate", action="store_true", help="Translate the speech into English (Whisper-style models)")
    parser.add_argument("--timestamps", action="store_true", help="Also report when each segment is spoken")
    parser.add_argument("--device", help="Torch device, e.g. cpu, cuda, mps (default: the best available)")
    return parser.parse_args(argv)


def log(message: str) -> None:
    print(message, file=sys.stderr, flush=True)


def load_audio(path: str, sampling_rate: int):
    """Decodes the first audio track of any FFmpeg-readable file into mono float32 samples at sampling_rate."""
    import av
    import numpy as np

    try:
        container = av.open(path)
    except av.error.FileNotFoundError as e:
        raise UserError(f"{path} does not exist") from e
    except av.error.InvalidDataError as e:
        raise UserError(f"{path} is not an audio or video file in a format FFmpeg can read") from e

    with container:
        stream = next((s for s in container.streams if s.type == "audio"), None)
        if stream is None:
            raise UserError(f"{path} has no audio track")

        resampler = av.AudioResampler(format="flt", layout="mono", rate=sampling_rate)
        chunks = []
        for frame in container.decode(stream):
            for resampled in resampler.resample(frame):
                chunks.append(resampled.to_ndarray().reshape(-1))
        for resampled in resampler.resample(None):
            chunks.append(resampled.to_ndarray().reshape(-1))

    if not chunks:
        raise UserError(f"{path} contains no audio samples")

    return np.concatenate(chunks).astype(np.float32)


def main(argv: list[str]) -> int:
    args = parse_args(argv)

    # Models are loaded from disk only; never reach out to the Hub.
    os.environ["HF_HUB_OFFLINE"] = "1"

    # Imported after argument parsing so --help and usage errors stay fast.
    import torch
    from transformers import pipeline
    from transformers.utils import logging as transformers_logging

    # Progress bars redraw with carriage returns, which garbles pipes and log files.
    if not sys.stderr.isatty():
        transformers_logging.disable_progress_bar()

    try:
        device = args.device or best_device(torch)
        dtype = torch.float16 if device.startswith("cuda") else torch.float32

        log(f"[speech-to-text] Loading {args.model} on {device}...")
        asr = pipeline("automatic-speech-recognition", model=args.model, device=device, dtype=dtype)

        sampling_rate = asr.feature_extractor.sampling_rate
        audio = load_audio(args.audio, sampling_rate)
        seconds = len(audio) / sampling_rate

        options = {}
        if asr.model.config.is_encoder_decoder:
            generate_kwargs = {}
            if args.language or args.translate:
                if not getattr(asr.model.generation_config, "lang_to_id", None):
                    raise UserError("this model cannot be told a language or translate; it only transcribes the language it was trained on")
                if args.language:
                    generate_kwargs["language"] = args.language
                if args.translate:
                    generate_kwargs["task"] = "translate"
            options["generate_kwargs"] = generate_kwargs
            # Whisper transcribes audio longer than one window sequentially, which needs segment timestamps.
            if args.timestamps or seconds > CHUNK_SECONDS:
                options["return_timestamps"] = True
        else:
            if args.language or args.translate:
                raise UserError("this model cannot be told a language or translate; it only transcribes the language it was trained on")
            if seconds > CHUNK_SECONDS:
                options["chunk_length_s"] = CHUNK_SECONDS
            if args.timestamps:
                options["return_timestamps"] = "word"

        log(f"[speech-to-text] Transcribing {seconds:.1f} seconds of audio...")
        output = asr({"raw": audio, "sampling_rate": sampling_rate}, **options)
    except UserError as e:
        log(f"[speech-to-text] Error: {e}")
        return EXIT_FAILED
    except Exception as e:
        traceback.print_exc()
        log(f"[speech-to-text] Error: {type(e).__name__}: {e}")
        return EXIT_FAILED

    result = {"text": output["text"].strip()}
    if args.timestamps:
        result["segments"] = [
            {"start": chunk["timestamp"][0], "end": chunk["timestamp"][1], "text": chunk["text"].strip()}
            for chunk in output.get("chunks", [])
        ]

    print(json.dumps(result), flush=True)
    log("[speech-to-text] Done.")
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
