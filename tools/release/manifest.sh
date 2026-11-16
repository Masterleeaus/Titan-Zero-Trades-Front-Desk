#!/usr/bin/env bash
# Usage: manifest.sh <plugin-dir>
# Prints "<sha256>  <relative path>" for every regular file under <plugin-dir>,
# sorted by byte order so two runs on identical trees produce identical output.
set -euo pipefail
dir="${1:?usage: manifest.sh <plugin-dir>}"
cd "$dir"
LC_ALL=C find . -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum | sed 's# \./# #'
