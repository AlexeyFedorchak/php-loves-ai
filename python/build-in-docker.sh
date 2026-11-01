#!/usr/bin/env bash
# Builds and packs the Linux binaries inside Docker, from any host that runs Docker (macOS or Linux).
#
# The base image has an old glibc (2.31), and PyInstaller binaries only run on systems with the same or a newer
# glibc, so the result runs on most distributions: Debian 11+, Ubuntu 20.04+, RHEL/Rocky 9+.
#
# Usage: python/build-in-docker.sh <linux/amd64|linux/arm64> [output dir] [target ...]
#   output dir  where the packed assets go (default: python/release)
#   target      which binaries to build: "puller" or a runner name such as "face" (default: all of them).
#               Naming one or two is the quick way to check a single runner without waiting for the
#               torch-based ones, which take hours.
#
# Examples:
#   python/build-in-docker.sh linux/arm64                    # everything
#   python/build-in-docker.sh linux/arm64 release face       # just the face runner
set -euo pipefail

cd "$(dirname "$0")"

platform="${1:?Usage: $0 <linux/amd64|linux/arm64> [output dir] [target ...]}"
out="${2:-release}"
shift $(($# > 2 ? 2 : $#))

targets=("$@")
if [ ${#targets[@]} -eq 0 ]; then
    targets=(puller)
    for dir in runners/*/; do
        targets+=("$(basename "$dir")")
    done
fi

# Fail before starting Docker rather than inside it.
for target in "${targets[@]}"; do
    script="runners/${target}/build.sh"
    [ "$target" = puller ] && script="puller/build.sh"
    [ -x "$script" ] || { echo "Unknown target '${target}': ${script} is not there." >&2; exit 2; }
done

mkdir -p "$out"
out="$(cd "$out" && pwd)"

echo "Building for ${platform}: ${targets[*]}"

docker run --rm --platform "$platform" \
    --volume "$(pwd):/src:ro" \
    --volume "$out:/out" \
    --env PYTHON=python3 \
    --env TORCH_INDEX_URL=https://download.pytorch.org/whl/cpu \
    --env "TARGETS=${targets[*]}" \
    python:3.12-slim-bullseye \
    bash -euo pipefail -c '
        apt-get update -qq
        apt-get install -y -qq binutils > /dev/null   # PyInstaller inspects shared libraries with objdump

        # Copy the sources only: host virtualenvs and build output are platform-specific. The excludes
        # match any directory of that name, so adding a runner needs no change here.
        mkdir /work
        tar -C /src --exclude=.venv --exclude=build --exclude=dist --exclude=__pycache__ \
            --exclude=./release -cf - . | tar -C /work -xf -

        for target in $TARGETS; do
            if [ "$target" = puller ]; then
                /work/puller/build.sh
            else
                "/work/runners/${target}/build.sh"
            fi
        done

        # Packs whatever was built and skips the rest.
        /work/package.sh /out
    '
