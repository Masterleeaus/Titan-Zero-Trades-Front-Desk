#!/usr/bin/env bash
# Usage: preservation-check.sh <plugin-dir> [markers-file]
# For each marker line, checks the file exists and contains the fixed string.
# Exit status is non-zero if any marker is missing. Woo/payment hooks are NOT listed:
# no WooCommerce or payment hook exists in the current main tree (grep -ri woo finds nothing),
# so they cannot be checked here; see the readiness report for the v3.0.128 comparison.
set -uo pipefail
dir="${1:?plugin dir}"; markers="${2:-$(dirname "$0")/preservation-markers.txt}"
fail=0; n=0
while IFS=$'\t' read -r path needle; do
  [[ -z "$path" || "$path" == \#* ]] && continue
  n=$((n+1))
  if [[ -f "$dir/$path" ]] && grep -qF -- "$needle" "$dir/$path"; then
    echo "PASS  $path  [$needle]"
  else
    echo "FAIL  $path  [$needle]"; fail=$((fail+1))
  fi
done < "$markers"
echo "markers: $n, failed: $fail"
exit $(( fail > 0 ))
