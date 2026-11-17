#!/usr/bin/env bash
# Usage: compare-manifests.sh <old.sha256> <new.sha256>
# Lists files REMOVED (only in old), ADDED (only in new) and CHANGED (same path, different hash).
set -euo pipefail
old="${1:?old manifest}"; new="${2:?new manifest}"
awk '{print $2"\t"$1}' "$old" | LC_ALL=C sort > "${TMPDIR:-/tmp}/cmp-old.$$"
awk '{print $2"\t"$1}' "$new" | LC_ALL=C sort > "${TMPDIR:-/tmp}/cmp-new.$$"
awk -F'\t' 'NR==FNR{o[$1]=$2; next} {if(!($1 in o)) print "ADDED\t" $1; else if(o[$1]!=$2) print "CHANGED\t" $1; delete o[$1]} END{for(k in o) print "REMOVED\t" k}' \
  "${TMPDIR:-/tmp}/cmp-old.$$" "${TMPDIR:-/tmp}/cmp-new.$$" | LC_ALL=C sort
rm -f "${TMPDIR:-/tmp}/cmp-old.$$" "${TMPDIR:-/tmp}/cmp-new.$$"
