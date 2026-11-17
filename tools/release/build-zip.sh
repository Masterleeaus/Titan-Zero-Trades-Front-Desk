#!/usr/bin/env bash
# Usage: build-zip.sh <plugin-dir> <out.zip>
# Deterministic ZIP: sorted file order, fixed mtimes (SOURCE_DATE_EPOCH), no extra attributes.
# Copies into a staging dir outside the repo first, so the source tree is never modified.
set -euo pipefail
src="$(realpath "${1:?plugin dir}")"; out="$(realpath -m "${2:?output zip}")"
epoch="${SOURCE_DATE_EPOCH:-1767225600}"   # 2026-01-01T00:00:00Z by default
name="$(basename "$src")"
stage="$(mktemp -d)"; trap 'rm -rf "$stage"' EXIT
mkdir "$stage/$name"
tar -C "$src" -cf - . | tar -C "$stage/$name" -xf -
find "$stage/$name" -exec touch -h -d "@$epoch" {} +
rm -f "$out"
(cd "$stage" && LC_ALL=C find "$name" -print | LC_ALL=C sort | zip -X -D -q -@ "$out")
sha256sum "$out"
