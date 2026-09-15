#!/usr/bin/env bash
# Builds the standalone image-to-text runner for the current platform.
# Output: python/runners/image-to-text/dist/image-to-text-<os>-<arch>/image-to-text-<os>-<arch>[.exe]
# Environment:
#   PYTHON           interpreter used to create the virtualenv (default: python3)
#   TORCH_INDEX_URL  package index to install torch and torchvision from, e.g. https://download.pytorch.org/whl/cpu
#                    for CPU-only Linux builds (the default Linux wheels bundle GBs of CUDA libraries)
set -euo pipefail

cd "$(dirname "$0")"

name="image-to-text-$(../../platform.sh)"

"${PYTHON:-python3}" -m venv .venv
bin=".venv/bin"
[ -d .venv/Scripts ] && bin=".venv/Scripts" # Windows

"$bin/python" -m pip install --quiet --upgrade pip
if [ -n "${TORCH_INDEX_URL:-}" ]; then
    # torchvision must come from the same index as torch, or pip replaces torch with the default (CUDA) build.
    "$bin/python" -m pip install --quiet torch torchvision --index-url "$TORCH_INDEX_URL"
fi
"$bin/python" -m pip install --quiet -r requirements.txt "pyinstaller>=6,<7"

# Packages whose installed version transformers verifies through importlib.metadata at import time;
# PyInstaller does not bundle that metadata unless asked.
metadata=(
    accelerate filelock huggingface-hub jinja2 numpy packaging Pillow pyyaml regex safetensors
    sentencepiece tokenizers torch torchvision tqdm transformers
)

# torch weighs hundreds of MB: --onedir keeps it unpacked on disk instead of extracting it to a temp dir
# on every run. transformers imports model classes dynamically, so its whole package is collected; torchvision
# loads its native operators (_C) from its package directory at runtime, which PyInstaller cannot detect.
"$bin/pyinstaller" --noconfirm --clean --onedir \
    --name "$name" \
    --distpath dist \
    --workpath build \
    --specpath build \
    --collect-all transformers \
    --collect-all torchvision \
    "${metadata[@]/#/--copy-metadata=}" \
    image_to_text.py

echo "Built: $(pwd)/dist/${name}/"
