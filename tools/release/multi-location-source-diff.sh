#!/usr/bin/env bash
# Source diff of the multi-location revision against the v3.0.128 release (issue #369, T3).
# The base is the release ZIP committed at BASE_SHA, read with git show and extracted into a new empty folder.
# The plugin files are compared with diff -uN; test files are not in the release ZIP, so they are listed with SHA-256.
# Usage: tools/release/multi-location-source-diff.sh [output-file]   (default: stdout)
# Env:   BASE_SHA (default 22dc106^), BASE_ZIP (default titan-zero-trades-front-desk-3.0.128-r16.zip)
# Exit:  0 written; 1 the release ZIP cannot be read or unpacked.
set -eu

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
BASE_SHA="${BASE_SHA:-22dc106^}"
BASE_ZIP="${BASE_ZIP:-titan-zero-trades-front-desk-3.0.128-r16.zip}"
PLUGIN=titan-zero-trades-front-desk
OUT="${1:-}"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/base-x"
git -C "$REPO" show "$BASE_SHA:$BASE_ZIP" > "$WORK/base.zip" 2>/dev/null || { echo "cannot read $BASE_ZIP at $BASE_SHA" >&2; exit 1; }
unzip -q "$WORK/base.zip" -d "$WORK/base-x" || { echo "cannot unzip $BASE_ZIP" >&2; exit 1; }

# Plugin files in the multi-location revision (paths relative to the repository root).
PLUGIN_FILES=(
  "$PLUGIN/includes/class-tzfd-multi-location.php"
  "$PLUGIN/includes/class-tzfd-location-registry.php"
  "$PLUGIN/includes/class-tzfd-location-store.php"
  "$PLUGIN/includes/class-tzfd-location-policy.php"
  "$PLUGIN/includes/class-tzfd-location-admin.php"
  "$PLUGIN/includes/class-tzfd-staff-assignment.php"
)
TEST_FILES=(
  tests/unit/test-multi-location.php
  tests/unit/test-location-registry.php
  tests/unit/test-location-policy.php
  tests/unit/test-retention-downgrade-multisite.php
  tests/runtime/upgrade-from-3.0.128.sh
  tests/runtime/upgrade-seed-verify.php
)

emit() {
  echo "# Multi-location source diff: $BASE_ZIP ($BASE_SHA) -> repository working tree"
  echo "# Plugin files: diff -uN (an absent side is empty). Test files are not in the release ZIP: SHA-256 only."
  echo "# The evaluator $PLUGIN/includes/class-tzfd-multi-location.php is compared like the other files; an empty diff means unchanged."
  echo
  for f in "${PLUGIN_FILES[@]}"; do
    local base="$WORK/base-x/$f" head="$REPO/$f"
    [ -f "$base" ] || base=/dev/null
    [ -f "$head" ] || head=/dev/null
    diff -uN --label "a/$f" --label "b/$f" "$base" "$head" || true
  done
  echo
  echo "# Test files (SHA-256 in the working tree; absent from the release ZIP)"
  for f in "${TEST_FILES[@]}"; do
    if [ -f "$REPO/$f" ]; then
      echo "$(sha256sum "$REPO/$f" | cut -d' ' -f1)  $f"
    else
      echo "MISSING  $f"
    fi
  done
}

if [ -n "$OUT" ]; then emit > "$OUT"; else emit; fi
