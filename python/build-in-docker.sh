#!/usr/bin/env bash
# Builds and packs the Linux binaries inside Docker, from any host that runs Docker (macOS or Linux).
#
# The base image has an old glibc (2.31), and PyInstaller binaries only run on systems with the same or a newer
# glibc, so the result runs on most distributions: Debian 11+, Ubuntu 20.04+, RHEL/Rocky 9+.
#
# Usage: python/build-in-docker.sh <linux/amd64|linux/arm64> [output dir]   (default output: python/release)
set -euo pipefail

cd "$(dirname "$0")"

platform="${1:?Usage: $0 <linux/amd64|linux/arm64> [output dir]}"
out="${2:-release}"
mkdir -p "$out"
out="$(cd "$out" && pwd)"

docker run --rm --platform "$platform" \
    --volume "$(pwd):/src:ro" \
    --volume "$out:/out" \
    --env PYTHON=python3 \
    --env TORCH_INDEX_URL=https://download.pytorch.org/whl/cpu \
    python:3.12-slim-bullseye \
    bash -euo pipefail -c '
        apt-get update -qq
        apt-get install -y -qq binutils > /dev/null   # PyInstaller inspects shared libraries with objdump

        # Copy the sources only: host virtualenvs and build output are platform-specific.
        mkdir /work
        tar -C /src --exclude=.venv --exclude=./puller/build --exclude=./puller/dist \
            --exclude=./runners/text-to-image/build --exclude=./runners/text-to-image/dist \
            --exclude=./runners/text-to-text/build --exclude=./runners/text-to-text/dist \
            --exclude=./runners/image-to-text/build --exclude=./runners/image-to-text/dist \
            --exclude=./runners/speech-to-text/build --exclude=./runners/speech-to-text/dist \
            --exclude=./runners/text-to-speech/build --exclude=./runners/text-to-speech/dist \
            --exclude=./runners/image-to-image/build --exclude=./runners/image-to-image/dist \
            --exclude=./runners/text-to-video/build --exclude=./runners/text-to-video/dist \
            --exclude=./runners/image-to-video/build --exclude=./runners/image-to-video/dist --exclude=./release \
            -cf - . | tar -C /work -xf -

        /work/puller/build.sh
        /work/runners/text-to-image/build.sh
        /work/runners/text-to-text/build.sh
        /work/runners/image-to-text/build.sh
        /work/runners/speech-to-text/build.sh
        /work/runners/text-to-speech/build.sh
        /work/runners/image-to-image/build.sh
        /work/runners/text-to-video/build.sh
        /work/runners/image-to-video/build.sh
        /work/package.sh /out
    '
