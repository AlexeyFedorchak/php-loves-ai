#!/usr/bin/env bash
# Packs the built binaries into release assets, as downloaded by `vendor/bin/setup`:
#   <tool>-<os>-<arch>.tar.gz  holding the binary (puller) or its --onedir directory (runners)
#   <tool>-<os>-<arch>.tar.gz.sha256
# Usage: python/package.sh [output dir]   (default: python/release)
# Must stay in sync with PhpLovesAi\Binary\Tool.
set -euo pipefail

cd "$(dirname "$0")"

platform="$(./platform.sh)"
out="${1:-release}"
mkdir -p "$out"
out="$(cd "$out" && pwd)"

checksum() {
    if command -v sha256sum > /dev/null; then sha256sum "$1"; else shasum -a 256 "$1"; fi
}

# pack <tool> <dir containing the entry> <entry>
pack() {
    local asset="$1-${platform}.tar.gz"
    if [ ! -e "$2/$3" ]; then
        echo "Skipping $1: $2/$3 not built" >&2
        return
    fi
    tar -czf "$out/$asset" -C "$2" "$3"
    (cd "$out" && checksum "$asset" > "$asset.sha256")
    echo "Packed: $out/$asset"
}

puller="puller-${platform}"
[ -e "puller/dist/${puller}.exe" ] && puller="${puller}.exe"

pack puller puller/dist "$puller"
pack text-to-image runners/text-to-image/dist "text-to-image-${platform}"
pack text-to-text runners/text-to-text/dist "text-to-text-${platform}"
pack image-to-text runners/image-to-text/dist "image-to-text-${platform}"
pack speech-to-text runners/speech-to-text/dist "speech-to-text-${platform}"
pack text-to-speech runners/text-to-speech/dist "text-to-speech-${platform}"
pack image-to-image runners/image-to-image/dist "image-to-image-${platform}"
pack text-to-video runners/text-to-video/dist "text-to-video-${platform}"
