#!/usr/bin/env bash
# Server side of staging releases and mode switches. Runs ON the staging host; called over SSH by
# deploy/ci-staging.sh from the deploy-staging workflow (docs/STAGING.md §3). Never used for production.
#
#   staging-host.sh identity     <store>                 JSON: home and site URL, environment, plugin active (no plugin code loaded)
#   staging-host.sh install      <cc> <store> <sha>      recover, back up, swap plugin+theme under maintenance, check WordPress loads
#   staging-host.sh rollback     <cc> <store>            put the previous plugin+theme back (only while the new release is unverified)
#   staging-host.sh commit       <cc> <store> <sha>      the release passed every check: keep it, move the previous copy out of the web root
#   staging-host.sh recover      <cc> <store>            finish an interrupted run: an unverified release is always rolled back
#   staging-host.sh status       <cc>                    the journal for this store
#   staging-host.sh launch-check <store>                 `wp ym launch-check --format=json` (open items are not an error)
#   staging-host.sh cron-fresh   <store>                 exit 0 only if the 5-minute job ran in the last 15 minutes (server cron works)
#   staging-host.sh mode         <store> <development|staging>   set WP_ENVIRONMENT_TYPE in wp-config.php
#   staging-host.sh gate         <store>                 JSON: environment, catalog gate enforced, purchasable products that the gate should block, open test orders
#
# Layout (outside the web root): $YM_DEPLOY_HOME (default ~/ym-deploy)
#   releases/<sha>/     the extracted release (plugin/, theme/, deploy/)      backups/<cc>/   deploy/backup.sh copies, 14 kept
#   state/<cc>          journal: phase=none|swapping|verifying|done|rolled_back, new=<sha>, prev=<sha>
#   previous/<cc>/      the release that was live before the last committed one (redeploy its commit to go back)
#   failed/             a first release that failed its checks, kept for reading
# While a release is unverified the old copies sit next to the live ones as dot-directories (.ym-previous-*), which
# WordPress never loads as plugins or themes, so a rollback is two renames per component.
set -euo pipefail
DH="${YM_DEPLOY_HOME:-$HOME/ym-deploy}"
WP="${WP:-wp}"
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_REL="wp-content/plugins/yawmiyati-commerce"
THEME_REL="wp-content/themes/yawmiyati"
MAINT_MARK="// ym-deploy"

die() { echo "ERROR: $*" >&2; exit 1; }
wpq() { ( cd "$STORE" && $WP "$@" ); }
valid_cc() { [[ "$1" =~ ^(eg|iq|sa|ae|om)$ ]] || die "bad store code '$1'"; }
valid_sha() { [[ "$1" =~ ^[0-9a-f]{40}$ ]] || die "bad commit '$1'"; }
need_store() { [ -f "$STORE/wp-config.php" ] || die "no WordPress at $STORE"; }

state_get() { # key -> value ('' when absent)
  [ -f "$DH/state/$CC" ] || { echo ""; return; }
  sed -n "s/^$1=//p" "$DH/state/$CC" | tail -1
}
state_write() { # phase new prev
  mkdir -p "$DH/state"
  printf 'phase=%s\nnew=%s\nprev=%s\nat=%s\n' "$1" "$2" "$3" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$DH/state/$CC.tmp"
  mv -f "$DH/state/$CC.tmp" "$DH/state/$CC"
}
lock() {
  mkdir -p "$DH/state"
  if command -v flock >/dev/null 2>&1; then
    exec 9>"$DH/state/$CC.lock"
    flock -n 9 || die "another deployment for $CC is running on this host"
  fi
}
release_of() { cat "$1/.ym-release" 2>/dev/null || echo unknown; }
maint_on() { printf '<?php $upgrading = %s; %s ?>\n' "$(date +%s)" "$MAINT_MARK" > "$STORE/.maintenance"; }
maint_off() { if [ -f "$STORE/.maintenance" ] && grep -q "$MAINT_MARK" "$STORE/.maintenance"; then rm -f "$STORE/.maintenance"; fi; }
comp_paths() { # rel -> sets LIVE INC PREV
  LIVE="$STORE/$1"; INC="$(dirname "$LIVE")/.ym-incoming-$(basename "$LIVE")"; PREV="$(dirname "$LIVE")/.ym-previous-$(basename "$LIVE")"
}

do_rollback() { # put the previous copies back; a component with no previous copy that is the new release is moved aside
  local new="$1"
  trap '' HUP INT TERM PIPE
  maint_on
  for rel in "$PLUGIN_REL" "$THEME_REL"; do
    comp_paths "$rel"
    if [ -e "$PREV" ]; then
      rm -rf "$LIVE"; mv "$PREV" "$LIVE"
    elif [ -e "$LIVE" ] && [ "$(release_of "$LIVE")" = "$new" ]; then
      mkdir -p "$DH/failed"; rm -rf "$DH/failed/$CC-$(basename "$rel")"; mv "$LIVE" "$DH/failed/$CC-$(basename "$rel")"
    fi
    rm -rf "$INC"
  done
  maint_off
  state_write rolled_back "$new" "$(state_get prev)"
  trap - HUP INT TERM PIPE
  ( cd "$STORE" && $WP cache flush --skip-plugins --skip-themes --quiet ) || true   # cache flush failure does not undo the rollback
  echo "rolled back $CC: plugin $(release_of "$STORE/$PLUGIN_REL"), theme $(release_of "$STORE/$THEME_REL")"
}

do_recover() {
  local phase; phase="$(state_get phase)"
  case "$phase" in
    swapping|verifying)
      echo "recover $CC: previous run stopped in phase '$phase' for $(state_get new); rolling back (an unverified release is never left live)"
      do_rollback "$(state_get new)" ;;
    done)
      # The release was verified; only the move of the old copies out of the web root may be unfinished.
      for rel in "$PLUGIN_REL" "$THEME_REL"; do
        comp_paths "$rel"
        if [ -e "$PREV" ]; then mkdir -p "$DH/previous/$CC"; rm -rf "$DH/previous/$CC/$(basename "$rel")"; mv "$PREV" "$DH/previous/$CC/$(basename "$rel")"; fi
      done ;;
  esac
  for rel in "$PLUGIN_REL" "$THEME_REL"; do comp_paths "$rel"; rm -rf "$INC"; done
  maint_off
}

cmd="${1:-}"; shift || true
case "$cmd" in
  identity)
    STORE="$1"; need_store
    wpq eval --skip-plugins --skip-themes 'echo wp_json_encode( array( "home" => home_url( "/" ), "site" => site_url( "/" ), "env" => wp_get_environment_type(), "plugin_active" => in_array( "yawmiyati-commerce/yawmiyati-commerce.php", (array) get_option( "active_plugins", array() ), true ) ) );'
    ;;
  install)
    CC="$1"; STORE="$2"; SHA="$3"; valid_cc "$CC"; valid_sha "$SHA"; need_store; lock
    REL="$DH/releases/$SHA"
    [ -f "$REL/.complete" ] || die "release $SHA is not fully unpacked in $REL"
    do_recover
    # 1. Backup before anything changes (database, uploads, shared consent ledger when the plugin is active).
    WP="$WP" "$HERE/backup.sh" "$STORE" "$DH/backups/$CC" > "$DH/state/$CC.backup" || die "backup failed: nothing was changed"
    echo "backup: $(tail -1 "$DH/state/$CC.backup")"
    # 2. Copies next to the live ones (same filesystem, so the swap is a rename). Dot-directories are never loaded.
    for pair in "plugin:$PLUGIN_REL" "theme:$THEME_REL"; do
      comp_paths "${pair#*:}"
      rm -rf "$INC"; mkdir -p "$(dirname "$INC")"
      cp -a "$REL/${pair%%:*}" "$INC"
      echo "$SHA" > "$INC/.ym-release"
    done
    # 3. Swap under maintenance; a dropped SSH connection does not stop it half-way (signals ignored).
    PREV_SHA="$(release_of "$STORE/$PLUGIN_REL")"
    trap '' HUP INT TERM PIPE
    state_write swapping "$SHA" "$PREV_SHA"
    maint_on
    for rel in "$PLUGIN_REL" "$THEME_REL"; do
      comp_paths "$rel"
      rm -rf "$PREV"
      if [ -e "$LIVE" ] || [ -L "$LIVE" ]; then mv "$LIVE" "$PREV"; fi
      mv "$INC" "$LIVE"
    done
    maint_off
    state_write verifying "$SHA" "$PREV_SHA"
    trap - HUP INT TERM PIPE
    # 4. WordPress loads with every active plugin (a PHP fatal here rolls back at once).
    if ! out="$( cd "$STORE" && $WP eval 'echo "ym-loaded";' 2>&1 )" || [[ "$out" != *ym-loaded* ]]; then
      echo "WordPress did not load with release $SHA:" >&2; echo "$out" | tail -20 >&2
      do_rollback "$SHA"
      die "release $SHA rolled back on $CC: WordPress failed to load"
    fi
    ( cd "$STORE" && $WP cache flush --quiet ) || { do_rollback "$SHA"; die "cache flush failed on $CC; rolled back"; }
    echo "installed $CC: $SHA (was $PREV_SHA), state verifying"
    ;;
  rollback)
    CC="$1"; STORE="$2"; valid_cc "$CC"; need_store; lock
    phase="$(state_get phase)"
    [[ "$phase" == swapping || "$phase" == verifying ]] || die "nothing unverified to roll back on $CC (phase '$phase'); to go back to an older release, deploy its commit"
    do_rollback "$(state_get new)"
    ;;
  commit)
    CC="$1"; STORE="$2"; SHA="$3"; valid_cc "$CC"; valid_sha "$SHA"; need_store; lock
    [ "$(state_get phase)" = verifying ] && [ "$(state_get new)" = "$SHA" ] || die "release $SHA is not the one being verified on $CC"
    state_write done "$SHA" "$(state_get prev)"
    do_recover   # moves the previous copies out of the web root
    ls -1dt "$DH"/releases/*/ 2>/dev/null | tail -n +4 | while read -r d; do [ "$(basename "$d")" = "$SHA" ] || rm -rf "$d"; done
    echo "committed $CC: $SHA"
    ;;
  recover)
    CC="$1"; STORE="$2"; valid_cc "$CC"; need_store; lock
    do_recover
    echo "recover $CC: phase now '$(state_get phase)'"
    ;;
  status)
    CC="$1"; valid_cc "$CC"; cat "$DH/state/$CC" 2>/dev/null || echo "phase=none"
    ;;
  launch-check)
    STORE="$1"; need_store
    wpq ym launch-check --format=json
    ;;
  cron-fresh)
    STORE="$1"; need_store
    age="$(wpq eval '$r = (array) get_option( "ym_ops_runs", array() ); $at = (int) ( $r["consent_certify"]["at"] ?? 0 ); echo $at ? ( time() - $at ) : -1;' | tail -1)"
    [[ "$age" =~ ^-?[0-9]+$ ]] || die "could not read the job log: '$age'"
    if [ "$age" -ge 0 ] && [ "$age" -le 900 ]; then echo "cron ok: 5-minute job ran ${age}s ago"; else
      die "cron NOT running: the 5-minute job has $( [ "$age" -lt 0 ] && echo 'never run' || echo "not run for ${age}s" ) (docs/STAGING.md §2 step 4)"
    fi
    ;;
  mode)
    STORE="$1"; TARGET="$2"; need_store
    [[ "$TARGET" == development || "$TARGET" == staging ]] || die "mode must be development or staging"
    wpq config set WP_ENVIRONMENT_TYPE "$TARGET" --type=constant --quiet
    ;;
  gate)
    STORE="$1"; need_store
    wpq eval '
      $leaks = array();
      foreach ( wc_get_products( array( "limit" => -1, "status" => "publish" ) ) as $p ) {
        if ( YM_Catalog::blockers( $p ) && $p->is_purchasable() && $p->is_in_stock() ) { $leaks[] = $p->get_sku(); }
      }
      $open = array();
      foreach ( YM_Orders::ids_by_meta( "_ym_test", "1", 0, 500 ) as $id ) {
        $o = wc_get_order( $id );
        if ( $o && in_array( YM_Lifecycle::outcome( $o ), array( "in_progress", "unverified", "conflict" ), true ) ) { $open[] = $id; }
      }
      echo wp_json_encode( array( "env" => YM_Env::type(), "enforced" => YM_Env::enforced(), "accepts_orders" => YM_Markets::accepts_orders(), "leaks" => $leaks, "open_test_orders" => $open ) );'
    ;;
  *)
    die "usage: staging-host.sh identity|install|rollback|commit|recover|status|launch-check|cron-fresh|mode|gate …"
    ;;
esac
