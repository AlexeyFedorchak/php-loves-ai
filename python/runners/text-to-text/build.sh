#!/usr/bin/env bash
# Builds the standalone text-to-text runner for the current platform.
# Output: python/runners/text-to-text/dist/text-to-text-<os>-<arch>/text-to-text-<os>-<arch>[.exe]
# Environment:
#   PYTHON           interpreter used to create the virtualenv (default: python3)
#   TORCH_INDEX_URL  package index to install torch from, e.g. https://download.pytorch.org/whl/cpu
#                    for CPU-only Linux builds (the default Linux wheels bundle GBs of CUDA libraries)
set -euo pipefail

cd "$(dirname "$0")"

name="text-to-text-$(../../platform.sh)"

"${PYTHON:-python3}" -m venv .venv
bin=".venv/bin"
[ -d .venv/Scripts ] && bin=".venv/Scripts" # Windows

"$bin/python" -m pip install --quiet --upgrade pip
if [ -n "${TORCH_INDEX_URL:-}" ]; then
    "$bin/python" -m pip install --quiet torch --index-url "$TORCH_INDEX_URL"
fi
"$bin/python" -m pip install --quiet -r requirements.txt "pyinstaller>=6,<7"

# Packages whose installed version transformers verifies through importlib.metadata at import time;
# PyInstaller does not bundle that metadata unless asked.
metadata=(
    accelerate filelock huggingface-hub jinja2 numpy packaging pyyaml regex safetensors
    sentencepiece tokenizers torch tqdm transformers
)

# torch weighs hundreds of MB: --onedir keeps it unpacked on disk instead of extracting it to a temp dir
# on every run. transformers imports model classes dynamically, so its whole package is collected.
"$bin/pyinstaller" --noconfirm --clean --onedir \
    --name "$name" \
    --distpath dist \
    --workpath build \
    --specpath build \
    --collect-all transformers \
    "${metadata[@]/#/--copy-metadata=}" \
    text_to_text.py

echo "Built: $(pwd)/dist/${name}/"
