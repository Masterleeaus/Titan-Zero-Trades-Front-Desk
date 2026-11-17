#!/usr/bin/env bash
# Issue #3 gate: the free plugin ZIP is reproducible and carries no development files.
# Builds the package twice with tools/release/build-zip.sh and compares SHA-256.
# Run from anywhere: bash tests/release/reproducible-build-check.sh
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
plugin="$root/titan-zero-trades-front-desk"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

bash "$root/tools/release/build-zip.sh" "$plugin" "$work/first.zip" >/dev/null
bash "$root/tools/release/build-zip.sh" "$plugin" "$work/second.zip" >/dev/null

first="$(sha256sum "$work/first.zip" | cut -d' ' -f1)"
second="$(sha256sum "$work/second.zip" | cut -d' ' -f1)"
if [ "$first" != "$second" ]; then
	echo "FAIL two builds differ: $first vs $second"
	exit 1
fi
echo "PASS two builds identical: $first"

leaks="$(unzip -Z1 "$work/first.zip" | grep -E '(^|/)(\.git|\.github|tests|node_modules|\.DS_Store|__MACOSX)(/|$)' || true)"
if [ -n "$leaks" ]; then
	echo "FAIL development files in package:"
	echo "$leaks"
	exit 1
fi
echo "PASS no development files in package"
