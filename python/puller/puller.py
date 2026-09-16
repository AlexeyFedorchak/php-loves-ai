"""Pull models from the Hugging Face Hub and save them locally.

Compiled into a standalone binary with PyInstaller, so it runs without a Python
installation. Designed to be driven by the PHP wrapper:

  * stdout: one JSON object per model, e.g.
            {"model": "openai-community/gpt2", "path": "/abs/models/openai-community/gpt2"}  pulled
            {"model": "meta-llama/Llama-3.2-1B", "error": "gated"}                          no access
    where "error" is one of the ERROR_* constants below
  * stderr: progress and error messages for humans
  * environment: HUGGING_FACE_API_KEY, optional; public models are pulled without it
  * exit code: see the EXIT_* constants below
"""

import argparse
import json
import os
import re
import sys

from huggingface_hub import HfApi, snapshot_download
from huggingface_hub.utils import disable_progress_bars
from huggingface_hub.errors import (
    GatedRepoError,
    HfHubHTTPError,
    RepositoryNotFoundError,
    RevisionNotFoundError,
)

API_KEY_ENV = "HUGGING_FACE_API_KEY"

EXIT_OK = 0
EXIT_PULL_FAILED = 1
EXIT_USAGE = 2  # argparse's own exit code for invalid arguments
# Exit code 3 was used by older pullers that required an API key for every model.

# Must stay in sync with PhpLovesAi\Exception\ModelAccessDeniedException.
ERROR_GATED = "gated"  # the model needs a key whose account accepted its terms
ERROR_NOT_FOUND = "not_found"  # the model does not exist, or is private and the key (if any) has no access

# Weights for frameworks the runners do not bundle, and leftovers from training: never usable here.
UNUSABLE_SUFFIXES = (
    ".h5", ".msgpack", ".onnx", ".onnx_data", ".tflite", ".pb", ".ot", ".pdparams", ".gguf", ".ckpt", ".mlmodel",
    ".png", ".jpg", ".jpeg", ".gif", ".webp", ".mp4", ".mov", ".avi",
)
UNUSABLE_NAMES = ("optimizer.pt", "optimizer.bin", "scheduler.pt", "training_args.bin", "trainer_state.json")

# PyTorch weights, in the order the runners prefer them; a file is skipped when a preferred twin exists.
DUPLICATE_SUFFIXES = (".bin", ".pt", ".pth")

# Alternative versions of the same weights, which the runners never ask for.
VARIANT_MARKERS = (".fp16.", ".fp32.", ".non_ema.", ".ema.")

# "name" or "namespace/name"; each part starts with an alphanumeric character.
MODEL_ID_PATTERN = re.compile(r"^(?:[A-Za-z0-9][\w.-]*/)?[A-Za-z0-9][\w.-]*$")


def model_id(value: str) -> str:
    if not MODEL_ID_PATTERN.match(value) or ".." in value:
        raise argparse.ArgumentTypeError(
            f"invalid Hugging Face model id: '{value}' (expected 'name' or 'namespace/name')"
        )
    return value


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="puller",
        description=f"Pull models from the Hugging Face Hub. Private and gated models need an API key in {API_KEY_ENV}.",
    )
    parser.add_argument(
        "models",
        nargs="+",
        type=model_id,
        metavar="MODEL",
        help="Hugging Face model id, e.g. openai-community/gpt2",
    )
    parser.add_argument(
        "--dir",
        required=True,
        help="Directory to save models into; each model goes to <dir>/<model id>",
    )
    parser.add_argument(
        "--revision",
        default=None,
        help="Branch, tag or commit hash to pull (defaults to the repository's main branch)",
    )
    parser.add_argument(
        "--all",
        action="store_true",
        help="Download every file, including weights for other frameworks the runners cannot read",
    )
    return parser.parse_args(argv)


def log(message: str) -> None:
    print(message, file=sys.stderr, flush=True)


def is_unusable(name: str) -> bool:
    """Whether a repository file is of no use to the runners: another framework, training leftovers, or media."""
    base = name.rsplit("/", 1)[-1]

    return (
        name.lower().endswith(UNUSABLE_SUFFIXES)
        or base in UNUSABLE_NAMES
        or name.startswith("checkpoint-")
        or "/checkpoint-" in name
        or base.startswith("openvino")
        or base.startswith("rng_state")
    )


def twins(name: str) -> list[str]:
    """The safetensors files that would hold the same weights as name, if the repository has them."""
    stem = name[: name.rindex(".")]
    candidates = {f"{stem}.safetensors"}

    # pytorch_model.bin is published as model.safetensors, and the same for sharded files.
    base = stem.rsplit("/", 1)[-1]
    if base.startswith("pytorch_model"):
        candidates.add(f"{stem[: len(stem) - len(base)]}{base.replace('pytorch_model', 'model', 1)}.safetensors")

    return sorted(candidates)


def files_to_skip(names: list[str]) -> set[str]:
    """Picks the files of a repository the runners cannot or need not read."""
    available = set(names)
    skip = {name for name in names if is_unusable(name)}

    for name in names:
        if name in skip:
            continue

        # The list of shards of weights published as safetensors too, whose shards are skipped below.
        if name.endswith(".bin.index.json") and name.replace("pytorch_model.bin", "model.safetensors") in available:
            skip.add(name)
            continue

        # A second copy of weights the repository also publishes as safetensors.
        if name.lower().endswith(DUPLICATE_SUFFIXES) and any(twin in available for twin in twins(name)):
            skip.add(name)
            continue

        # fp16 and non-EMA versions of a file that is also published plainly.
        for marker in VARIANT_MARKERS:
            if marker in name and name.replace(marker, ".", 1) in available:
                skip.add(name)
                break

    # A diffusers pipeline keeps its weights in subfolders (unet/, vae/...), so a single file next to
    # model_index.json is the whole pipeline over again, in the format other tools read.
    in_subfolders = any("/" in name and name.endswith((".safetensors", ".bin")) and name not in skip for name in names)
    if "model_index.json" in available and in_subfolders:
        skip.update(name for name in names if "/" not in name and name.endswith(".safetensors"))

    return skip


def report_error(model: str, error: str) -> None:
    print(json.dumps({"model": model, "error": error}), flush=True)


def unusable_files(api, model: str, revision: str | None, token) -> tuple[list[str], int]:
    """Lists the repository's files the runners cannot read, with the bytes not downloading them saves."""
    info = api.model_info(repo_id=model, revision=revision, files_metadata=True, token=token)
    sizes = {file.rfilename: file.size or 0 for file in info.siblings or []}

    skip = sorted(files_to_skip(list(sizes)))

    return skip, sum(sizes[name] for name in skip)


def megabytes(size: int) -> str:
    return f"{size / 1048576:.1f} MB" if size < 1073741824 else f"{size / 1073741824:.1f} GB"


def main(argv: list[str]) -> int:
    args = parse_args(argv)

    # Progress bars redraw with carriage returns, which garbles pipes and log files.
    if not sys.stderr.isatty():
        disable_progress_bars()

    # False (not None) keeps huggingface_hub from falling back to a token saved elsewhere on the machine.
    token = os.environ.get(API_KEY_ENV, "").strip() or False

    models_dir = os.path.abspath(args.dir)
    api = HfApi()
    failed = False

    for model in args.models:
        destination = os.path.join(models_dir, *model.split("/"))
        log(f"[puller] Pulling {model} into {destination}...")

        try:
            skip, saved = ([], 0) if args.all else unusable_files(api, model, args.revision, token)
            if skip:
                log(f"[puller] Skipping {len(skip)} file(s) the runners cannot read ({megabytes(saved)}).")

            path = snapshot_download(
                repo_id=model,
                revision=args.revision,
                local_dir=destination,
                token=token,
                ignore_patterns=skip or None,
            )
        except GatedRepoError:
            log(f"[puller] Error: {model} is gated; it needs an API key whose account accepted its terms on huggingface.co.")
            report_error(model, ERROR_GATED)
        except RepositoryNotFoundError:
            log(f"[puller] Error: {model} was not found; it does not exist, or it is private and needs an API key with access.")
            report_error(model, ERROR_NOT_FOUND)
        except RevisionNotFoundError:
            log(f"[puller] Error: revision '{args.revision}' does not exist for {model}.")
        except (HfHubHTTPError, OSError) as e:
            log(f"[puller] Error: failed to pull {model}: {e}")
        else:
            print(json.dumps({
                "model": model,
                "path": os.path.abspath(path),
                "skipped_files": len(skip),
                "skipped_bytes": saved,
            }), flush=True)
            log(f"[puller] Pulled {model}.")
            continue

        failed = True

    return EXIT_PULL_FAILED if failed else EXIT_OK


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
