#!/usr/bin/env bash
# Print "SHA256  relative/path" for every shipped file of the plugin folder, sorted.
# Usage: source-manifest.sh <plugin-dir>
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="${1:?usage: source-manifest.sh <plugin-dir>}"
PLUGIN_DIR="$(cd "$PLUGIN_DIR" && pwd)"

cd "$PLUGIN_DIR"
find . -type f | sed 's#^\./##' | LC_ALL=C sort | while IFS= read -r rel; do
    skip=0
    while IFS= read -r pattern; do
        case "$pattern" in ''|'#'*) continue ;; esac
        # Match the pattern against the full path and against each path component.
        # shellcheck disable=SC2254
        case "$rel" in
            $pattern|$pattern/*|*/$pattern|*/$pattern/*) skip=1; break ;;
        esac
    done < "$SCRIPT_DIR/package-excludes.txt"
    [ "$skip" -eq 0 ] || continue
    printf '%s  %s\n' "$(sha256sum "$rel" | cut -d' ' -f1)" "$rel"
done
