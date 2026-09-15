#!/usr/bin/env bash
# Prints the platform suffix used in binary names, e.g. "darwin-arm64".
# Must stay in sync with PhpLovesAi\Binary\Platform.
set -euo pipefail

os="$(uname -s | tr '[:upper:]' '[:lower:]')"
case "$os" in
    mingw* | msys* | cygwin*) os="windows" ;;
esac

arch="$(uname -m)"
case "$arch" in
    x86_64 | amd64) arch="x86_64" ;;
    arm64 | aarch64) arch="arm64" ;;
esac

echo "${os}-${arch}"
