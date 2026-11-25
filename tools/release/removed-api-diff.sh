#!/usr/bin/env bash
# Usage: removed-api-diff.sh [base-rev] [head-rev]
# Lists the public-surface names (hooks and AJAX actions, classes, functions and methods, constants)
# and the plugin files that exist at base-rev (default 22dc106 = Trades Front Desk 3.0.128)
# but not at head-rev (default HEAD). Used for issue #434: removals must appear in the changelog.
# Output lines are "KIND name" (KIND = FILE, CLASS, FUNC, HOOK or CONST), sorted and unique.
set -uo pipefail
export LC_ALL=C
base="${1:-22dc106}"; head="${2:-HEAD}"
root="$(git rev-parse --show-toplevel)"
plugin='titan-zero-trades-front-desk'
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

names() {
  local rev="$1" out="$2" f
  : > "$out"
  git -C "$root" ls-tree -r --name-only "$rev" -- "$plugin" | sed "s#^$plugin/##; s/^/FILE /" >> "$out"
  git -C "$root" ls-tree -r --name-only "$rev" -- "$plugin" | grep '\.php$' | while read -r f; do
    git -C "$root" show "$rev:$f" 2>/dev/null > "$work/src.php"
    grep -a -o -E "(apply_filters(_ref_array)?|do_action(_ref_array)?|add_action|add_filter|apply_filters_deprecated|do_action_deprecated)\s*\(\s*['\"][^'\"]+['\"]" "$work/src.php" | sed -E "s/.*['\"]([^'\"]+)['\"]/HOOK \1/" >> "$out"
    grep -a -o -E "^\s*(public |protected |private |static |final |abstract )*function\s+&?\w+" "$work/src.php" | sed -E 's/.*function\s+&?//; s/^/FUNC /' >> "$out"
    grep -a -o -E "^\s*(final |abstract )?class\s+\w+" "$work/src.php" | sed -E 's/.*class\s+//; s/^/CLASS /' >> "$out"
    grep -a -o -E "define\s*\(\s*['\"][A-Za-z_][A-Za-z0-9_]*['\"]" "$work/src.php" | sed -E "s/.*['\"]([^'\"]+)['\"]/CONST \1/" >> "$out"
  done
  sort -u -o "$out" "$out"
}

names "$base" "$work/base.txt"
names "$head" "$work/head.txt"
comm -23 "$work/base.txt" "$work/head.txt"
