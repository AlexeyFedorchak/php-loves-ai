"""Answer a question by choosing one of its options, with a probability for each, using a locally pulled JEV decision
classifier such as akhilaaa3/Jev-Omni.

Compiled into a standalone binary with PyInstaller, so it runs without a Python
installation. Designed to be driven by the PHP wrapper:

  * stdout: one JSON object on success, e.g.
            {"prediction": "No", "index": 1, "confidence": 0.97, "probabilities": [0.03, 0.97]}
            where "probabilities" follows the order of --options
  * stderr: progress and error messages for humans
  * exit code: 0 success, 1 classification failed, 2 invalid arguments

A JEV model is a transformers multimodal checkpoint plus a decision head: head.pt holds one linear layer that turns the
model's last hidden state into a logit per option slot, and decision_config.json its size. The Python loader shipped
in the model repository (jev_omni.py) is never run; the head is rebuilt here from its weights alone.

The question can be about text only (the state), or about an image, an audio file (its first 30 seconds) or a video
(evenly spaced frames). Media is decoded with Pillow and PyAV, which bundles FFmpeg's libraries, so no FFmpeg
installation is needed.
"""

import argparse
import json
import multiprocessing
import os
import sys
import traceback

EXIT_OK = 0
EXIT_FAILED = 1

# Audio longer than this is cut, as the model was trained on at most 30 seconds.
AUDIO_SECONDS = 30

# Frames sampled from a video, as in the model's own loader.
DEFAULT_FRAMES = 16

MODALITY_SUFFIXES = {
    "image": (".jpg", ".jpeg", ".png", ".webp", ".gif", ".bmp", ".tif", ".tiff"),
    "audio": (".wav", ".mp3", ".m4a", ".aac", ".flac", ".ogg", ".oga", ".opus", ".wma"),
    "video": (".mp4", ".m4v", ".mov", ".webm", ".mkv", ".avi", ".mpeg", ".mpg"),
}


class UserError(Exception):
    """A problem the user can fix, reported without a stack trace."""


def option_list(value: str) -> list[str]:
    try:
        options = json.loads(value)
    except json.JSONDecodeError as e:
        raise argparse.ArgumentTypeError(f"--options must be a JSON list of strings: {e}") from e

    if not isinstance(options, list) or not all(isinstance(option, str) for option in options):
        raise argparse.ArgumentTypeError("--options must be a JSON list of strings")
    if len(options) < 2:
        raise argparse.ArgumentTypeError("at least two options are needed")

    return options


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="jev",
        description="Answer a question by choosing one of its options, with a locally pulled JEV decision classifier.",
    )
    parser.add_argument("--model", required=True, help="Directory of a pulled JEV model (contains config.json and head.pt)")
    parser.add_argument("--question", required=True, help="The question, e.g. 'Has the meeting started?'")
    parser.add_argument("--options", required=True, type=option_list, help='The answers to choose from, as a JSON list, e.g. \'["Yes", "No"]\'')
    parser.add_argument("--state", default="", help="What the question is about, as text, e.g. 'The meeting starts at 10 AM. It is now 9 AM.'")
    parser.add_argument("--media", help="Image, audio or video file the question is about")
    parser.add_argument("--modality", choices=sorted(MODALITY_SUFFIXES), help="What --media is (default: told by its extension)")
    parser.add_argument("--frames", type=int, default=DEFAULT_FRAMES, help=f"Frames sampled from a video (default: {DEFAULT_FRAMES})")
    parser.add_argument("--device", help="Torch device, e.g. cpu, cuda, mps (default: the best available)")
    return parser.parse_args(argv)


def log(message: str) -> None:
    print(message, file=sys.stderr, flush=True)


def prompt(state: str, question: str, options: list[str]) -> str:
    """The prompt the model was trained on; it must stay exactly as it is."""
    choices = "\n".join(f"{i + 1}. {value}" for i, value in enumerate(options))
    return (f"{state}\n\n---\n\nQUESTION: {question}\n\nOPTIONS:\n{choices}\n\n"
            f"Reply with only the number of the correct option (1-{len(options)}).\n"
            "Output a single number and nothing else.")


def modality_of(path: str) -> str:
    suffix = os.path.splitext(path)[1].lower()
    for modality, suffixes in MODALITY_SUFFIXES.items():
        if suffix in suffixes:
            return modality

    raise UserError(f"cannot tell whether {path} is an image, audio or video from its extension; pass --modality")


def open_media(path: str):
    import av

    try:
        return av.open(path)
    except av.error.FileNotFoundError as e:
        raise UserError(f"{path} does not exist") from e
    except av.error.InvalidDataError as e:
        raise UserError(f"{path} is not a file in a format FFmpeg can read") from e


def load_image(path: str):
    from PIL import Image, UnidentifiedImageError

    try:
        return Image.open(path).convert("RGB")
    except FileNotFoundError as e:
        raise UserError(f"{path} does not exist") from e
    except UnidentifiedImageError as e:
        raise UserError(f"{path} is not an image") from e


def load_audio(path: str, sampling_rate: int):
    """Decodes the first AUDIO_SECONDS of the first audio track into mono float32 samples at sampling_rate."""
    import av
    import numpy as np

    limit = AUDIO_SECONDS * sampling_rate
    with open_media(path) as container:
        stream = next((s for s in container.streams if s.type == "audio"), None)
        if stream is None:
            raise UserError(f"{path} has no audio track")

        resampler = av.AudioResampler(format="flt", layout="mono", rate=sampling_rate)
        chunks, samples = [], 0
        for frame in container.decode(stream):
            for resampled in resampler.resample(frame):
                chunks.append(resampled.to_ndarray().reshape(-1))
                samples += chunks[-1].size
            if samples >= limit:
                break
        else:
            for resampled in resampler.resample(None):
                chunks.append(resampled.to_ndarray().reshape(-1))

    if not chunks:
        raise UserError(f"{path} contains no audio samples")

    return np.concatenate(chunks)[:limit].astype(np.float32)


def load_video_frames(path: str, count: int) -> list:
    """Picks count frames spread evenly over the video, the middle of each of count equal parts."""
    if count < 1:
        raise UserError("--frames must be at least 1")

    with open_media(path) as container:
        stream = next((s for s in container.streams if s.type == "video"), None)
        if stream is None:
            raise UserError(f"{path} has no video track")
        total = stream.frames or sum(1 for _ in container.decode(stream))

    if total < 1:
        raise UserError(f"{path} contains no video frames")

    wanted = {int(round((total - 1) * (k + 0.5) / count)) for k in range(count)}
    frames = []
    with open_media(path) as container:
        stream = next(s for s in container.streams if s.type == "video")
        for index, frame in enumerate(container.decode(stream)):
            if index in wanted:
                frames.append(frame.to_image().convert("RGB"))
                if len(frames) == len(wanted):
                    break

    if not frames:
        raise UserError(f"could not decode frames from {path}")

    return frames


def find_text_decoder(model):
    """The text backbone, whose last hidden state the decision head reads."""
    for path in ("model.language_model", "language_model.model", "model.text_model", "model"):
        node = model
        for part in path.split("."):
            node = getattr(node, part, None)
            if node is None:
                break
        if node is not None and hasattr(node, "layers"):
            return node

    raise UserError("this model has no text backbone the decision head can read; it is not a JEV classifier")


def load_head(torch, model_dir: str, device: str):
    """Rebuilds the decision head: normalize the last hidden state, then one logit per option slot."""
    with open(os.path.join(model_dir, "decision_config.json"), encoding="utf-8") as file:
        decision = json.load(file)
    hidden, classes = int(decision["hidden_size"]), int(decision.get("output_classes", 256))

    class DecisionHead(torch.nn.Module):
        def __init__(self):
            super().__init__()
            self.register_buffer("mu", torch.zeros(1, hidden))
            self.register_buffer("sd", torch.ones(1, hidden))
            self.linear = torch.nn.Linear(hidden, classes, dtype=torch.float32)

        def forward(self, features):
            return self.linear((features.float() - self.mu) / self.sd)

    head = DecisionHead()
    # weights_only: the file is read as plain tensors, so it cannot run code.
    head.load_state_dict(torch.load(os.path.join(model_dir, "head.pt"), map_location="cpu", weights_only=True))

    return head.to(device).eval(), classes


def main(argv: list[str]) -> int:
    args = parse_args(argv)

    # Models are loaded from disk only; never reach out to the Hub.
    os.environ["HF_HUB_OFFLINE"] = "1"

    # Imported after argument parsing so --help and usage errors stay fast.
    import inspect

    import torch
    import transformers
    from transformers import AutoConfig, AutoProcessor
    from transformers.utils import logging as transformers_logging

    # Progress bars redraw with carriage returns, which garbles pipes and log files.
    if not sys.stderr.isatty():
        transformers_logging.disable_progress_bar()

    try:
        device = args.device or best_device(torch)
        modality = "text" if args.media is None else (args.modality or modality_of(args.media))

        content = []
        if modality == "image":
            content.append({"type": "image", "image": load_image(args.media)})
        elif modality == "video":
            # The model reads a video as a sequence of still frames.
            frames = load_video_frames(args.media, args.frames)
            log(f"[jev] Read {len(frames)} frames from {args.media}.")
            content.extend({"type": "image", "image": frame} for frame in frames)

        log(f"[jev] Loading {args.model} on {device}...")
        config = AutoConfig.from_pretrained(args.model, local_files_only=True)
        architecture = (getattr(config, "architectures", None) or [None])[0]
        model_class = getattr(transformers, architecture, None) if architecture else None
        if model_class is None:
            raise UserError(f"this runner's transformers {transformers.__version__} does not know the model's architecture ({architecture})")

        processor = AutoProcessor.from_pretrained(args.model, local_files_only=True)
        model = model_class.from_pretrained(args.model, dtype=torch.bfloat16, device_map=device, local_files_only=True).eval()
        head, classes = load_head(torch, args.model, device)
        if len(args.options) > classes:
            raise UserError(f"this model chooses among at most {classes} options, {len(args.options)} were given")

        if modality == "audio":
            sampling_rate = getattr(getattr(processor, "feature_extractor", None), "sampling_rate", None) or 16000
            content.append({"type": "audio", "audio": load_audio(args.media, sampling_rate)})
        content.append({"type": "text", "text": prompt(args.state, args.question, args.options)})

        inputs = processor.apply_chat_template(
            [{"role": "user", "content": content}],
            add_generation_prompt=True,
            tokenize=True,
            return_dict=True,
            return_tensors="pt",
            enable_thinking=False,
        )
        inputs = {
            name: tensor.to(device, dtype=torch.bfloat16) if torch.is_floating_point(tensor) else tensor.to(device)
            for name, tensor in inputs.items()
        }

        captured = {}

        def capture(_module, _inputs, output):
            hidden = output.last_hidden_state if hasattr(output, "last_hidden_state") else output[0]
            captured["hidden"] = hidden[:, -1].float()

        find_text_decoder(model).register_forward_hook(capture)
        # Only the hidden state is needed, not logits over the whole vocabulary.
        extra = {"logits_to_keep": 1} if "logits_to_keep" in inspect.signature(model.forward).parameters else {}

        log(f"[jev] Weighing {len(args.options)} options ({modality})...")
        with torch.inference_mode(), torch.autocast(device.split(":")[0], dtype=torch.bfloat16, enabled=device.startswith("cuda")):
            model(**inputs, use_cache=False, **extra)
            probabilities = head(captured["hidden"])[0, : len(args.options)].softmax(-1).float().cpu().tolist()
    except UserError as e:
        log(f"[jev] Error: {e}")
        return EXIT_FAILED
    except Exception as e:
        traceback.print_exc()
        log(f"[jev] Error: {type(e).__name__}: {e}")
        return EXIT_FAILED

    best = max(range(len(probabilities)), key=probabilities.__getitem__)
    print(json.dumps({
        "prediction": args.options[best],
        "index": best,
        "confidence": probabilities[best],
        "probabilities": probabilities,
    }), flush=True)
    log("[jev] Done.")
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
