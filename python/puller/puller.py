"""Pull models from the Hugging Face Hub and save them locally.

Compiled into a standalone binary with PyInstaller, so it runs without a Python
installation. Designed to be driven by the PHP wrapper:

  * stdout: one JSON object per successfully pulled model, e.g.
            {"model": "openai-community/gpt2", "path": "/abs/models/openai-community/gpt2"}
  * stderr: progress and error messages for humans
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
EXIT_MISSING_API_KEY = 3

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
        description=f"Pull models from the Hugging Face Hub. Requires the {API_KEY_ENV} environment variable.",
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


def main(argv: list[str]) -> int:
    args = parse_args(argv)

    # Progress bars redraw with carriage returns, which garbles pipes and log files.
    if not sys.stderr.isatty():
        disable_progress_bars()

    token = os.environ.get(API_KEY_ENV, "").strip()
    if not token:
        log(f"[puller] Error: environment variable {API_KEY_ENV} is not set.")
        return EXIT_MISSING_API_KEY

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
            log(f"[puller] Error: {model} is gated; accept its license on huggingface.co first.")
        except RepositoryNotFoundError:
            log(f"[puller] Error: {model} was not found, or the API key has no access to it.")
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
