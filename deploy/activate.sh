#!/usr/bin/env bash
# Activates a release built by the "deploy" workflow, without taking the site down.
#
#   activate.sh <app-dir> <release.tar.gz>
#
# Layout on the server (created on the first run):
#   <app-dir>/releases/<timestamp>/   one folder per deploy (the last 5 are kept)
#   <app-dir>/shared/.env             production settings, never in git
#   <app-dir>/shared/storage/         uploads, logs, sessions: survive every deploy
#   <app-dir>/current -> releases/…   what nginx serves (document root: current/public)
set -euo pipefail

APP_DIR="${1:?usage: activate.sh <app-dir> <release.tar.gz>}"
ARCHIVE="${2:?usage: activate.sh <app-dir> <release.tar.gz>}"
PHP="${PHP_BIN:-php}"
RELEASE="$APP_DIR/releases/$(date +%Y%m%d%H%M%S)"

mkdir -p "$APP_DIR/releases" "$APP_DIR/shared/storage"/{app/public,app/private,framework/cache,framework/sessions,framework/views,logs}

if [ ! -f "$APP_DIR/shared/.env" ]; then
    echo "Missing $APP_DIR/shared/.env — create it from .env.example before the first deploy." >&2
    exit 1
fi

mkdir -p "$RELEASE"
tar -xzf "$ARCHIVE" -C "$RELEASE"
rm -rf "$RELEASE/storage"
ln -s "$APP_DIR/shared/storage" "$RELEASE/storage"
ln -s "$APP_DIR/shared/.env" "$RELEASE/.env"

cd "$RELEASE"
"$PHP" artisan storage:link --force >/dev/null
"$PHP" artisan migrate --force
"$PHP" artisan optimize

# Switch atomically, then let PHP-FPM and the queue worker pick up the new code.
ln -sfn "$RELEASE" "$APP_DIR/current.tmp"
mv -Tf "$APP_DIR/current.tmp" "$APP_DIR/current"
"$PHP" artisan queue:restart
if [ -n "${PHP_FPM_RELOAD:-}" ]; then eval "$PHP_FPM_RELOAD"; fi

# Keep the last five releases for a quick rollback (ln -sfn releases/<older> current).
ls -1dt "$APP_DIR"/releases/*/ | tail -n +6 | xargs -r rm -rf
rm -f "$ARCHIVE"

echo "Release $(basename "$RELEASE") is live."
