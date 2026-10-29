#!/usr/bin/env bash
# Runner side of the deploy-staging workflow (docs/STAGING.md §3). Never touches production: it refuses unless every
# destination proves, before any store file changes, that it is a password-protected staging store at the expected URL.
#
#   ci-staging.sh check-commit         the tagged commit is on main and its "tests" run on main succeeded
#   ci-staging.sh release              deploy this commit's plugin and theme to every store in $STORES
#   ci-staging.sh mode <cc> <target>   switch one store to development (order tests) or back to staging (gate check)
#
# Environment (from the "staging" GitHub environment; values never printed):
#   HOST PORT USER_ ROOT   SSH destination; ROOT = the staging document root holding eg/ and iq/ (must contain "staging")
#   BASE_URL               e.g. https://staging.yawmiyati.shop (variable): the store URLs must be BASE_URL/<cc>/
#   HTTP_AUTH              user:password of the host's URL protection (secret), for the authenticated health checks
#   STORES                 default "eg iq"           SHA  the commit being deployed (GITHUB_SHA)
#   YM_SSH                 override of the ssh command (tests)    SUMMARY  file for the run summary (GITHUB_STEP_SUMMARY)
#
# Exit codes: 0 deployed or switched (open launch items are reported, not failures); 1 refused or failed (a store whose
# new release failed a check has been rolled back; the message says so); 2 the rollback itself failed: run
# `staging-host.sh recover` on that store before anything else.
set -euo pipefail
REPO="$(cd "$(dirname "$0")/.." && pwd)"
SUMMARY="${SUMMARY:-${GITHUB_STEP_SUMMARY:-/dev/null}}"
STORES="${STORES:-eg iq}"
SHA="${SHA:-${GITHUB_SHA:-}}"
PORT="${PORT:-18765}"
RDH='ym-deploy'                      # remote deploy home, relative to the SSH user's home (outside the web root)
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

say() { echo "$*"; echo "$*" >> "$SUMMARY"; }
err() { echo "::error::$*" >&2; echo "**ERROR** $*" >> "$SUMMARY"; }
die() { err "$*"; exit 1; }
q() { printf '%q' "$1"; }

ssh_cmd() {
  if [ -n "${YM_SSH:-}" ]; then echo "$YM_SSH"; return; fi
  echo "ssh -i $HOME/.ssh/id_staging -p $PORT -o StrictHostKeyChecking=yes -o BatchMode=yes -o ConnectTimeout=20 -o ServerAliveInterval=15 $USER_@$HOST"
}
rsh() { $(ssh_cmd) "$1"; }
host_script() { # args… -> runs the release's own host script with its deploy home
  local a="" x; for x in "$@"; do a+=" $(q "$x")"; done
  rsh "YM_DEPLOY_HOME=\"\$HOME/$RDH\" bash \"\$HOME/$RDH/releases/$SHA/deploy/staging-host.sh\"$a"
}
store_dir() { echo "$ROOT/$1"; }
store_url() { echo "${BASE_URL%/}/$1/"; }

http() { # url [auth] -> prints status code (000 when unreachable); body in $TMP/body
  local args=(-sS -o "$TMP/body" -w '%{http_code}' --max-time 30)
  [ -n "${2:-}" ] && args+=(-u "$2")
  curl "${args[@]}" "$1" 2>/dev/null || true
}

check_config() {
  [[ "$SHA" =~ ^[0-9a-f]{40}$ ]] || die "no commit to deploy"
  [ -n "${HOST:-}" ] && [ -n "${USER_:-}" ] && [ -n "${ROOT:-}" ] || die "staging SSH settings incomplete"
  [ -n "${HTTP_AUTH:-}" ] || die "STAGING_HTTP_AUTH missing: the health checks behind the URL protection cannot run"
  [[ "${BASE_URL:-}" =~ ^https://staging\.[a-z0-9-]+(\.[a-z0-9-]+)+$ ]] || die "BASE_URL must be https://staging.<domain> with no path (got '${BASE_URL:-}'): refusing anything that could be production"
  [[ "$ROOT" == *staging* && "$ROOT" != *..* ]] || die "STAGING_ROOT must be the staging document root (contains 'staging', no '..'): refusing"
  local cc; for cc in $STORES; do [[ "$cc" =~ ^(eg|iq|sa|ae|om)$ ]] || die "bad store '$cc'"; done
}

# ---------- 1. the release must be an approved main commit ----------
check_commit() {
  [[ "$SHA" =~ ^[0-9a-f]{40}$ ]] || die "no commit"
  # The workflow checks out full history (origin/main included, also once the repo is private and no credentials are
  # kept); fetch only when the ref is missing.
  git -C "$REPO" rev-parse -q --verify origin/main >/dev/null \
    || git -C "$REPO" fetch --quiet --no-tags origin "+refs/heads/main:refs/remotes/origin/main" \
    || die "cannot read main to check the commit"
  git -C "$REPO" merge-base --is-ancestor "$SHA" origin/main || die "commit ${SHA::7} is not on main: only main commits are released (docs/STAGING.md §3)"
  local n
  n="$(gh api "repos/${GITHUB_REPOSITORY}/actions/workflows/test.yml/runs?head_sha=${SHA}&branch=main&event=push&status=success&per_page=1" --jq '.total_count')"
  [ "${n:-0}" -ge 1 ] || die "no successful 'tests' run on main for ${SHA::7} (a pull-request or branch run does not count)"
  say "commit ${SHA::7}: on main, tests green on main"
}

# ---------- 2. upload the release to the deploy area (outside every web root; no store changes) ----------
upload() {
  local rel="$TMP/rel"
  mkdir -p "$rel/plugin" "$rel/theme" "$rel/deploy"
  cp -a "$REPO/plugin/yawmiyati-commerce/." "$rel/plugin/"
  cp -a "$REPO/theme/yawmiyati/." "$rel/theme/"
  cp -a "$REPO/deploy/staging-host.sh" "$REPO/deploy/backup.sh" "$REPO/deploy/restore.sh" "$rel/deploy/"
  tar -czf "$TMP/release.tgz" -C "$rel" .
  local sum; sum="$(sha256sum "$TMP/release.tgz" | cut -d' ' -f1)"
  rsh "mkdir -p $RDH/incoming $RDH/releases && cat > $RDH/incoming/$SHA.tgz.part" < "$TMP/release.tgz" || die "upload failed (SSH)"
  rsh "cd $RDH && echo '$sum  incoming/$SHA.tgz.part' | sha256sum -c --quiet - && rm -rf releases/$SHA.tmp && mkdir -p releases/$SHA.tmp && tar -xzf incoming/$SHA.tgz.part -C releases/$SHA.tmp && touch releases/$SHA.tmp/.complete && rm -rf releases/$SHA && mv releases/$SHA.tmp releases/$SHA && rm -f incoming/$SHA.tgz.part" \
    || die "upload could not be verified or unpacked on the host (checksum, disk or tar); no store was changed"
  say "release ${SHA::7} uploaded and checksum-verified"
}

# ---------- 3. destination checks, before any store file changes ----------
IDENTITY=""
identity() { # cc -> sets IDENTITY (JSON) or fails
  IDENTITY="$(host_script identity "$(store_dir "$1")" | tail -1)" || die "$1: cannot read WordPress at $(store_dir "$1") (SSH, PHP or WordPress error)"
  echo "$IDENTITY" | jq -e 'has("home") and has("env")' >/dev/null 2>&1 || die "$1: unexpected answer from WordPress: ${IDENTITY:0:200}"
}
protected() { # cc -> fails unless the store, its login and its REST API all demand the password
  local u; u="$(store_url "$1")"; local path code
  for path in "" "wp-login.php" "?rest_route=/ym/v1/health"; do
    code="$(http "$u$path")"
    [ "$code" = 401 ] || { [ "$code" = 000 ] && die "$1: $u$path unreachable"; die "$1: $u$path answered $code without a password: the store is not protected; refusing (Site Tools > Security > Protected URLs)"; }
  done
}
preflight() { # cc [allowed envs]
  local cc="$1" allowed="${2:-staging development}" env home site want
  want="$(store_url "$cc")"
  protected "$cc"
  identity "$cc"
  env="$(echo "$IDENTITY" | jq -r .env)"; home="$(echo "$IDENTITY" | jq -r .home)"; site="$(echo "$IDENTITY" | jq -r .site)"
  [ "$home" = "$want" ] && [ "$site" = "$want" ] || die "$cc: WordPress at $(store_dir "$cc") says home=$home site=$site, expected $want: wrong destination, refusing"
  [ "$env" != production ] || die "$cc: WP_ENVIRONMENT_TYPE=production: refusing (this workflow never deploys to production)"
  [[ " $allowed " == *" $env "* ]] || die "$cc: WP_ENVIRONMENT_TYPE=$env, expected one of: $allowed; refusing"
  say "$cc: $want protected, WordPress URL matches, environment $env"
}

# ---------- 4. install, verify over HTTP, commit or roll back ----------
rollback_or_die() { # cc reason
  err "$1: $2; rolling back"
  if host_script rollback "$1" "$(store_dir "$1")"; then die "$1: release ${SHA::7} rolled back after: $2"; fi
  err "$1: ROLLBACK FAILED: run 'staging-host.sh recover $1 <store>' on the host before anything else"; exit 2
}
verify_store() { # cc plugin_active
  local cc="$1" active="$2" u code body
  u="$(store_url "$cc")"
  code="$(http "$u")"; [ "$code" = 401 ] || rollback_or_die "$cc" "store answered $code without a password after the swap"
  code="$(http "$u" "$HTTP_AUTH")"; [ "$code" = 200 ] || rollback_or_die "$cc" "home page answered $code"
  if [ "$active" != true ]; then
    say "$cc: plugin not active yet (first release): activate the plugin and theme, then \`wp ym setup\` (docs/STAGING.md §2.3)"
    return
  fi
  grep -qi 'noindex' "$TMP/body" || rollback_or_die "$cc" "home page has no noindex: a staging store must never be indexable"
  code="$(http "${u}?rest_route=/ym/v1/health" "$HTTP_AUTH")"; body="$(cat "$TMP/body")"
  echo "$body" | jq -e --arg c "${cc^^}" '.country == $c and has("jobs")' >/dev/null 2>&1 || rollback_or_die "$cc" "health endpoint answered $code: ${body:0:200}"
  case "$code" in
    200) say "$cc: health 200, every scheduled job on time" ;;
    503) say "$cc: health 503 (expected until cron runs every job): stale $(echo "$body" | jq -r '[.jobs|to_entries[]|select(.value.stale)|.key]|join(", ")')" ;;
    *) rollback_or_die "$cc" "health endpoint answered $code" ;;
  esac
  local lc; lc="$(host_script launch-check "$(store_dir "$cc")" | tail -1)" || rollback_or_die "$cc" "wp ym launch-check could not run"
  echo "$lc" | jq -e '.open|numbers' >/dev/null 2>&1 || rollback_or_die "$cc" "wp ym launch-check gave no result: ${lc:0:200}"
  echo "$lc" > "$TMP/launch-$cc.json"
}
release() {
  check_config
  upload
  local cc
  for cc in $STORES; do preflight "$cc"; done          # every destination proven before the first store changes
  for cc in $STORES; do
    identity "$cc"; local active; active="$(echo "$IDENTITY" | jq -r .plugin_active)"
    local rc=0; host_script install "$cc" "$(store_dir "$cc")" "$SHA" || rc=$?
    if [ "$rc" -ne 0 ]; then
      # The host script rolls back by itself when WordPress fails to load. An SSH drop (255) may leave the swap
      # unverified: recover rolls it back.
      host_script recover "$cc" "$(store_dir "$cc")" || { err "$cc: install failed and recover failed: check the host"; exit 2; }
      die "$cc: install of ${SHA::7} failed (exit $rc); the store is on its previous release"
    fi
    verify_store "$cc" "$active"
    host_script commit "$cc" "$(store_dir "$cc")" "$SHA" || die "$cc: commit step failed after verification; the next run recovers it"
    say "$cc: release ${GITHUB_REF_NAME:-${SHA::7}} (${SHA::7}) live"
  done
  for cc in $STORES; do
    [ -f "$TMP/launch-$cc.json" ] || continue
    say ""; say "### $cc launch-check: $(jq -r .open "$TMP/launch-$cc.json") open item(s) (expected while staging is prepared)"
    jq -r '.rows[]|select(.ok=="NO")|"- \(.area): \(.detail)"' "$TMP/launch-$cc.json" | tee -a "$SUMMARY"
  done
}

# ---------- 5. order-test window: development only behind the password, back to staging afterwards ----------
mode() {
  local cc="$1" target="$2" env g
  STORES="$cc"; check_config
  [[ "$target" == development || "$target" == staging ]] || die "target must be development or staging"
  upload
  preflight "$cc"
  [ "$(echo "$IDENTITY" | jq -r .plugin_active)" = true ] || die "$cc: plugin not active: finish docs/STAGING.md §2 first"
  if [ "$target" = development ]; then
    host_script cron-fresh "$(store_dir "$cc")" || die "$cc: install the server cron and let it run before order tests (docs/STAGING.md §2 step 4)"
  fi
  host_script mode "$(store_dir "$cc")" "$target" || die "$cc: could not set WP_ENVIRONMENT_TYPE=$target"
  identity "$cc"; env="$(echo "$IDENTITY" | jq -r .env)"
  if [ "$env" != "$target" ]; then
    [ "$target" = development ] && host_script mode "$(store_dir "$cc")" staging || true
    die "$cc: environment reads '$env' after setting $target (an environment variable or another wp-config line overrides it)"
  fi
  if ! ( protected "$cc" ); then
    host_script mode "$(store_dir "$cc")" staging || true
    die "$cc: password protection did not hold after the switch; switched back to staging"
  fi
  if [ "$target" = development ]; then
    say "$cc: development (order tests). Password protection verified; orders now reach Taager and are marked _ym_test. Switch back with a staging-mode-$cc-staging-* tag when the tests are done."
    return
  fi
  g="$(host_script gate "$(store_dir "$cc")" | tail -1)" || die "$cc: gate check could not run"
  echo "$g" | jq -e '.enforced == true and (.leaks|length) == 0' >/dev/null 2>&1 || die "$cc: catalog gate not enforced after the switch: $g"
  say "$cc: staging restored: catalog gate enforced, no unverified product purchasable, checkout $(echo "$g" | jq -r 'if .accepts_orders then "open (market gate on)" else "closed (market gate off)" end')"
  local open; open="$(echo "$g" | jq -r '.open_test_orders|map(tostring)|join(", ")')"
  [ -z "$open" ] || say "$cc: test orders still without an outcome: $open: record each with \`wp ym outcome <id> cancelled|delivered … --evidence=…\`"
}

case "${1:-}" in
  check-commit) check_commit ;;
  release) release ;;
  mode) mode "${2:-}" "${3:-}" ;;
  *) echo "usage: ci-staging.sh check-commit|release|mode <cc> <development|staging>" >&2; exit 1 ;;
esac
