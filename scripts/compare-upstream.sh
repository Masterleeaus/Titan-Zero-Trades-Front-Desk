#!/usr/bin/env bash
# Compare the plugin tree with an extracted upstream package, by relative path.
# Usage: compare-upstream.sh <extracted-upstream-dir> <plugin-dir> [report-dir]
# Prints counts. With report-dir, writes the path lists into it.
# Upstream ZIPs contain one top-level folder; pass that folder, not the ZIP.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
UP="$(cd "${1:?upstream dir}" && pwd)"
PL="$(cd "${2:?plugin dir}" && pwd)"
REPORT="${3:-}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

"$SCRIPT_DIR/source-manifest.sh" "$UP" | sed -E 's/^([0-9a-f]+)  (.*)$/\2\t\1/' | LC_ALL=C sort > "$TMP/up.tsv"
"$SCRIPT_DIR/source-manifest.sh" "$PL" | sed -E 's/^([0-9a-f]+)  (.*)$/\2\t\1/' | LC_ALL=C sort > "$TMP/pl.tsv"
# Upstream is not filtered by the plugin excludes; apply the same rule set to both sides by reading all files.
cut -f1 "$TMP/up.tsv" | LC_ALL=C sort > "$TMP/up.paths"
cut -f1 "$TMP/pl.tsv" | LC_ALL=C sort > "$TMP/pl.paths"

LC_ALL=C join -t $'\t' "$TMP/up.tsv" "$TMP/pl.tsv" > "$TMP/both.tsv"
identical=$(awk -F'\t' '$2==$3' "$TMP/both.tsv" | wc -l)
differs=$(awk -F'\t' '$2!=$3' "$TMP/both.tsv" | wc -l)
absent=$(LC_ALL=C comm -23 "$TMP/up.paths" "$TMP/pl.paths" | wc -l)
plugin_only=$(LC_ALL=C comm -13 "$TMP/up.paths" "$TMP/pl.paths" | wc -l)

echo "upstream files:            $(wc -l < "$TMP/up.paths")"
echo "plugin files:              $(wc -l < "$TMP/pl.paths")"
echo "same path, identical:      $identical"
echo "same path, content differs: $differs"
echo "upstream path not present: $absent"
echo "plugin-only path:          $plugin_only"

if [ -n "$REPORT" ]; then
    mkdir -p "$REPORT"
    awk -F'\t' '$2==$3{print $1}' "$TMP/both.tsv" > "$REPORT/identical-paths.txt"
    awk -F'\t' '$2!=$3{print $1}' "$TMP/both.tsv" > "$REPORT/differs-paths.txt"
    LC_ALL=C comm -23 "$TMP/up.paths" "$TMP/pl.paths" > "$REPORT/upstream-absent-paths.txt"
    LC_ALL=C comm -13 "$TMP/up.paths" "$TMP/pl.paths" > "$REPORT/plugin-only-paths.txt"
fi
