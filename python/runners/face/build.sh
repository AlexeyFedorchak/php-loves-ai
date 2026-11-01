#!/usr/bin/env bash
# Builds the standalone face runner for the current platform.
# Output: python/runners/face/dist/face-<os>-<arch>/face-<os>-<arch>[.exe]
# Environment:
#   PYTHON  interpreter used to create the virtualenv (default: python3)
#
# Unlike the other runners this one needs no torch, so there is no TORCH_INDEX_URL and the
# result is tens of megabytes rather than hundreds.
set -euo pipefail

cd "$(dirname "$0")"

name="face-$(../../platform.sh)"

"${PYTHON:-python3}" -m venv .venv
bin=".venv/bin"
[ -d .venv/Scripts ] && bin=".venv/Scripts" # Windows

"$bin/python" -m pip install --quiet --upgrade pip
"$bin/python" -m pip install --quiet -r requirements.txt "pyinstaller>=6,<7"

# onnxruntime reads its own installed version through importlib.metadata at import time;
# PyInstaller does not bundle that metadata unless asked.
metadata=(numpy onnxruntime Pillow)

# onnxruntime loads its inference engine from native libraries next to its Python package, which
# PyInstaller only picks up via --collect-all. --onedir keeps them unpacked on disk instead of
# extracting them to a temp dir on every run, matching the other runners.
"$bin/pyinstaller" --noconfirm --clean --onedir \
    --name "$name" \
    --distpath dist \
    --workpath build \
    --specpath build \
    --collect-all onnxruntime \
    "${metadata[@]/#/--copy-metadata=}" \
    face.py

echo "Built: $(pwd)/dist/${name}/"
