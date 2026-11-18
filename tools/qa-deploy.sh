#!/usr/bin/env bash
# QA test-site deploys: one owner at a time, and every deploy records its commit.
#
# Usage:
#   qa-deploy.sh deploy <clone-dir>   copy <clone-dir>/titan-zero-trades-front-desk into the shared site,
#                                     reactivate it, write the record, and print the record
#   qa-deploy.sh show                 print the recorded deploy and whether the installed folder still matches it
#
# The deploy takes the lock itself. Do not wrap it in flock: the inner lock would wait on the outer one.
# The clone must have no uncommitted changes in the plugin folder, so the record names the exact build.
# One named deploy owner (issue #195). The record names the owner, and a deploy with no owner is refused.
# Env overrides: QA_SITE (default /tmp/wpsite/wordpress), QA_LOCK (default /tmp/wpsite/deploy.lock),
#                QA_WP_CLI (default /tmp/wpsite/wp-cli.phar), QA_DEPLOY_OWNER (default Jason; set it empty to refuse).
set -euo pipefail

PLUGIN=titan-zero-trades-front-desk
SITE="${QA_SITE:-/tmp/wpsite/wordpress}"
LOCK="${QA_LOCK:-/tmp/wpsite/deploy.lock}"
WP_CLI="${QA_WP_CLI:-/tmp/wpsite/wp-cli.phar}"
# ${VAR-default} (no colon): only an unset variable takes the default, so an explicit empty value is refused.
DEPLOY_OWNER="${QA_DEPLOY_OWNER-Jason}"
DEST="$SITE/wp-content/plugins/$PLUGIN"
RECORD="$SITE/wp-content/qa-deployed.txt"   # outside the plugin folder, so a deploy never removes it
here="$(cd "$(dirname "$0")" && pwd)"

usage() { sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }
wpcli() { php "$WP_CLI" --allow-root --path="$SITE" "$@"; }
tree_hash() { "$here/release/manifest.sh" "$1" | sha256sum | cut -d' ' -f1; }
take_lock() {
  exec 9>"$LOCK"
  flock -w 900 9 || { echo "qa-deploy: timed out waiting for $LOCK" >&2; exit 1; }
}

deploy() {
  local src sha branch tree now
  [[ -n "$DEPLOY_OWNER" ]] || { echo "qa-deploy: refusing, no deploy owner (set QA_DEPLOY_OWNER)" >&2; exit 1; }
  src="$(realpath "${1:?usage: qa-deploy.sh deploy <clone-dir>}")"
  [[ -d "$src/$PLUGIN" ]] || { echo "qa-deploy: no $PLUGIN folder in $src" >&2; exit 1; }
  if [[ -n "$(git -C "$src" status --porcelain -- "$PLUGIN")" ]]; then
    echo "qa-deploy: refusing, $src has uncommitted changes in $PLUGIN" >&2; exit 1
  fi
  sha="$(git -C "$src" rev-parse HEAD)"
  branch="$(git -C "$src" rev-parse --abbrev-ref HEAD)"
  tree="$(tree_hash "$src/$PLUGIN")"

  take_lock
  wpcli plugin deactivate "$PLUGIN" >/dev/null 2>&1 || true
  rm -rf "$DEST"
  cp -a "$src/$PLUGIN" "$DEST"
  wpcli plugin activate "$PLUGIN" >/dev/null

  now="$(now_utc)"
  printf 'plugin=%s\nowner=%s\ncommit=%s\nbranch=%s\ndeployed_at=%s\nsource=%s\ntree_sha256=%s\n' \
    "$PLUGIN" "$DEPLOY_OWNER" "$sha" "$branch" "$now" "$src" "$tree" > "$RECORD.tmp"
  mv "$RECORD.tmp" "$RECORD"
  show_record
}

now_utc() { date -u +%Y-%m-%dT%H:%M:%SZ; }

show_record() {
  [[ -f "$RECORD" ]] || { echo "qa-deploy: no deploy record at $RECORD" >&2; exit 1; }
  cat "$RECORD"
}

show() {
  take_lock
  show_record
  if [[ ! -d "$DEST" ]]; then
    echo "folder check: plugin folder missing"
    exit 1
  fi
  local want now
  want="$(sed -n 's/^tree_sha256=//p' "$RECORD")"
  now="$(tree_hash "$DEST")"
  if [[ "$want" == "$now" ]]; then
    echo "folder check: MATCH (installed folder equals the recorded commit)"
  else
    echo "folder check: DRIFT (installed folder no longer equals the recorded commit)"
    exit 1
  fi
}

case "${1:-}" in
  deploy) shift; deploy "$@" ;;
  show) show ;;
  *) usage ;;
esac
