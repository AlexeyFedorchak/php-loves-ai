#!/usr/bin/env bash
# Builds the standalone puller binary for the current platform.
# Output: python/puller/dist/puller-<os>-<arch>[.exe]
# Environment: PYTHON  interpreter used to create the virtualenv (default: python3)
set -euo pipefail

cd "$(dirname "$0")"

name="puller-$(../platform.sh)"

"${PYTHON:-python3}" -m venv .venv
bin=".venv/bin"
[ -d .venv/Scripts ] && bin=".venv/Scripts" # Windows

"$bin/python" -m pip install --quiet --upgrade pip
"$bin/python" -m pip install --quiet -r requirements.txt "pyinstaller>=6,<7"

# The puller has no heavy native dependencies, so a single file is fine here.
"$bin/pyinstaller" --noconfirm --clean --onefile \
    --name "$name" \
    --distpath dist \
    --workpath build \
    --specpath build \
    puller.py

echo "Built: $(pwd)/dist/${name}"
