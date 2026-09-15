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

from huggingface_hub import snapshot_download
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
    return parser.parse_args(argv)


def log(message: str) -> None:
    print(message, file=sys.stderr, flush=True)


def report_error(model: str, error: str) -> None:
    print(json.dumps({"model": model, "error": error}), flush=True)


def main(argv: list[str]) -> int:
    args = parse_args(argv)

    # Progress bars redraw with carriage returns, which garbles pipes and log files.
    if not sys.stderr.isatty():
        disable_progress_bars()

    # False (not None) keeps huggingface_hub from falling back to a token saved elsewhere on the machine.
    token = os.environ.get(API_KEY_ENV, "").strip() or False

    models_dir = os.path.abspath(args.dir)
    failed = False

    for model in args.models:
        destination = os.path.join(models_dir, *model.split("/"))
        log(f"[puller] Pulling {model} into {destination}...")

        try:
            path = snapshot_download(
                repo_id=model,
                revision=args.revision,
                local_dir=destination,
                token=token,
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
            print(json.dumps({"model": model, "path": os.path.abspath(path)}), flush=True)
            log(f"[puller] Pulled {model}.")
            continue

        failed = True

    return EXIT_PULL_FAILED if failed else EXIT_OK


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
