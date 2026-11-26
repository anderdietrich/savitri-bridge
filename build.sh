#!/usr/bin/env bash
# Gera o zip de instalação do plugin, com a pasta savitri-file-bridge/ na raiz.
set -euo pipefail

cd "$(dirname "$0")"
root="$PWD"
out="$(cd .. && pwd)/savitri-file-bridge.zip"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

mkdir -p "$stage/savitri-file-bridge"
cp -r "$root"/savitri-file-bridge.php "$root"/readme.txt "$root"/README.md "$root"/includes "$root"/assets "$root"/tests "$stage/savitri-file-bridge/"

rm -f "$out"
( cd "$stage" && zip -qr "$out" savitri-file-bridge -x "*.DS_Store" )

echo "gerado: $out"
unzip -l "$out" | tail -2
