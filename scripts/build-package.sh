#!/usr/bin/env bash
# Build the installable Free plugin ZIP reproducibly.
#
# Output (default dist/, git-ignored):
#   dist/titan-zero-trades-front-desk-<version>.zip
#   dist/titan-zero-trades-front-desk-<version>.zip.sha256
#   dist/SHA256SUMS.txt          (per-file manifest of the packaged tree)
#
# Reproducibility: entries are sorted with LC_ALL=C, zip is run with -X -D (no extra
# attributes, no directory entries), and every mtime is set to SOURCE_DATE_EPOCH, which
# defaults to the commit time of HEAD.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
SLUG="titan-zero-trades-front-desk"
PLUGIN_DIR="$ROOT/$SLUG"
OUT_DIR="${1:-$ROOT/dist}"

for tool in zip sha256sum tar find; do
    command -v "$tool" >/dev/null 2>&1 || { echo "missing tool: $tool" >&2; exit 1; }
done

VERSION="$(sed -n 's/^Version:[[:space:]]*//p' "$PLUGIN_DIR/titan-zero-front-desk.php" | head -n 1 | tr -d '\r')"
[ -n "$VERSION" ] || { echo "could not read Version from plugin header" >&2; exit 1; }

SDE="${SOURCE_DATE_EPOCH:-$(git -C "$ROOT" log -1 --format=%ct 2>/dev/null || echo 0)}"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG" "$OUT_DIR"

# Copy the shipped tree, honouring package-excludes.txt.
exclude_args=()
while IFS= read -r pattern; do
    case "$pattern" in ''|'#'*) continue ;; esac
    exclude_args+=(--exclude="$pattern")
done < "$SCRIPT_DIR/package-excludes.txt"
tar -C "$PLUGIN_DIR" "${exclude_args[@]}" -cf - . | tar -C "$STAGE/$SLUG" -xf -

# Syntax gate on the staged tree.
if command -v php >/dev/null 2>&1; then
    while IFS= read -r f; do
        php -l "$f" >/dev/null || { echo "php -l failed: $f" >&2; exit 1; }
    done < <(find "$STAGE/$SLUG" -type f -name '*.php')
fi

# Fixed timestamps so the archive does not depend on checkout time.
find "$STAGE/$SLUG" -exec touch -h -d "@$SDE" {} +

ZIP="$OUT_DIR/$SLUG-$VERSION.zip"
rm -f "$ZIP"
(
    cd "$STAGE"
    find "$SLUG" -type f | LC_ALL=C sort | zip -X -D -q -9 "$ZIP" -@
)

# Manifest of the packaged files, and the archive checksum.
(cd "$STAGE" && find "$SLUG" -type f | LC_ALL=C sort | while IFS= read -r f; do
    printf '%s  %s\n' "$(sha256sum "$f" | cut -d' ' -f1)" "$f"
done) > "$OUT_DIR/SHA256SUMS.txt"
(cd "$OUT_DIR" && sha256sum "$(basename "$ZIP")" > "$(basename "$ZIP").sha256")

echo "built $ZIP"
echo "sha256 $(cut -d' ' -f1 "$ZIP.sha256")"
echo "files $(unzip -Z1 "$ZIP" | wc -l)"
