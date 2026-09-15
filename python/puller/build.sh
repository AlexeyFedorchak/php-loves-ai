#!/usr/bin/env bash
# Builds the standalone puller binary for the current platform.
# Output: python/puller/dist/puller-<os>-<arch>
set -euo pipefail

cd "$(dirname "$0")"

os="$(uname -s | tr '[:upper:]' '[:lower:]')"
arch="$(uname -m)"
case "$arch" in
    x86_64 | amd64) arch="x86_64" ;;
    arm64 | aarch64) arch="arm64" ;;
esac
name="puller-${os}-${arch}"

python3 -m venv .venv
.venv/bin/pip install --quiet --upgrade pip
.venv/bin/pip install --quiet -r requirements.txt "pyinstaller>=6,<7"

# The puller has no heavy native dependencies, so a single file is fine here.
.venv/bin/pyinstaller --noconfirm --clean --onefile \
    --name "$name" \
    --distpath dist \
    --workpath build \
    --specpath build \
    puller.py

echo "Built: $(pwd)/dist/${name}"
