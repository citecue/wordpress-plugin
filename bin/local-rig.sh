#!/usr/bin/env bash
#
# Stands up a real WordPress with this plugin active, pointed at a stub CiteCue,
# and asserts that a page enhancement actually lands on a rendered page.
#
# The PHPUnit suite calls the injector directly or through a synthetic buffer.
# That covers the decisions but not the thing the decisions are for: whether the
# block reaches a browser, through a real theme, a real `wp_head()`/`wp_footer()`
# and whichever output-buffer mechanism this WordPress provides. On 6.9+ that is
# core's template enhancement filter, which no unit test exercises end to end.
#
# The stub stands in for app.citecue.com because the real acceptance test needs
# a deployed server, a CiteCue account and an approved block. This one needs
# none of those and can run on any branch, at any time.
#
# Usage:
#   bin/local-rig.sh up        build the rig and leave it running
#   bin/local-rig.sh verify    build it, assert, tear down, exit non-zero on failure
#   bin/local-rig.sh down      stop the servers
#
#   RIG_DIR      where to build it        (default: a temp directory)
#   RIG_DB_HOST  MySQL/MariaDB host:port  (default: 127.0.0.1:13306)
#   RIG_DB_USER  database user            (default: root)
#
# To point at a REAL CiteCue instead of the stub — a local build, a staging
# origin, or app.citecue.com for the post-deploy acceptance test — set all three:
#
#   RIG_API_BASE    e.g. http://127.0.0.1:8787
#   RIG_API_KEY     the org API key (ck_live_...)
#   RIG_PUBLIC_KEY  the project's public key (pk_...)
#
# The stub is then not started, and the checks that assert on what the plugin
# SENT are skipped — they read the stub's own request log, which a real server
# does not keep for us.

set -euo pipefail

ROOT=$(git rev-parse --show-toplevel)
RIG_DIR=${RIG_DIR:-"${TMPDIR:-/tmp}/citecue-rig"}
DB_HOST=${RIG_DB_HOST:-127.0.0.1:13306}
DB_USER=${RIG_DB_USER:-root}
API_PORT=13380
WP_PORT=13390

REAL_API=${RIG_API_BASE:-}
API_KEY=${RIG_API_KEY:-ck_live_rig}
PUBLIC_KEY=${RIG_PUBLIC_KEY:-pk_rig}
API_BASE="http://127.0.0.1:$API_PORT"
if [ -n "$REAL_API" ]; then
	API_BASE="$REAL_API"
fi

WP_SRC="$ROOT/vendor/roots/wordpress-no-content"
CORE_VERSION_FILE="$WP_SRC/wp-includes/version.php"

down() {
	for port in $API_PORT $WP_PORT; do
		pids=$(lsof -ti tcp:"$port" 2>/dev/null || true)
		[ -n "$pids" ] && kill $pids 2>/dev/null || true
	done
	echo "rig stopped"
}

if [ "${1:-up}" = "down" ]; then
	down
	exit 0
fi

if [ ! -f "$CORE_VERSION_FILE" ]; then
	echo "error: WordPress core not found at $WP_SRC" >&2
	echo "       run 'composer install' first — the rig borrows the core the tests use." >&2
	exit 1
fi

if ! mysqladmin --protocol=TCP --host="${DB_HOST%%:*}" --port="${DB_HOST##*:}" -u "$DB_USER" ping >/dev/null 2>&1; then
	echo "error: no database reachable at $DB_HOST as '$DB_USER' (set RIG_DB_HOST / RIG_DB_USER)" >&2
	exit 1
fi

down >/dev/null 2>&1 || true
rm -rf "$RIG_DIR"
mkdir -p "$RIG_DIR/fake-api"

mysql --protocol=TCP --host="${DB_HOST%%:*}" --port="${DB_HOST##*:}" -u "$DB_USER" \
	-e "DROP DATABASE IF EXISTS citecue_rig; CREATE DATABASE citecue_rig;"

cp -R "$WP_SRC" "$RIG_DIR/wp"
mkdir -p "$RIG_DIR/wp/wp-content/themes/citecue-rig" "$RIG_DIR/wp/wp-content/plugins"
ln -sfn "$ROOT" "$RIG_DIR/wp/wp-content/plugins/citecue-ai-auto-fix"

cp "$ROOT/bin/rig/theme-index.php" "$RIG_DIR/wp/wp-content/themes/citecue-rig/index.php"
printf '/*\nTheme Name: CiteCue Rig\nVersion: 1.0\n*/\n' > "$RIG_DIR/wp/wp-content/themes/citecue-rig/style.css"
cp "$ROOT/bin/rig/fake-citecue.php" "$RIG_DIR/fake-api/router.php"

sed -e "s|__DB_HOST__|$DB_HOST|" -e "s|__DB_USER__|$DB_USER|" \
	-e "s|__API_BASE__|$API_BASE|" -e "s|__WP_PORT__|$WP_PORT|" \
	"$ROOT/bin/rig/wp-config.php.tpl" > "$RIG_DIR/wp/wp-config.php"

if [ -z "$REAL_API" ]; then
	( cd "$RIG_DIR/fake-api" && nohup php -S "127.0.0.1:$API_PORT" router.php </dev/null >"$RIG_DIR/fake-api.log" 2>&1 & )
fi
( cd "$RIG_DIR/wp" && nohup php -S "127.0.0.1:$WP_PORT" </dev/null >"$RIG_DIR/wp.log" 2>&1 & )

if [ -z "$REAL_API" ]; then
	for _ in $(seq 1 20); do
		curl -sf "$API_BASE/api/delivery/v1/crawlers" >/dev/null 2>&1 && break
		sleep 0.5
	done
fi

RIG_DIR="$RIG_DIR" RIG_API_KEY="$API_KEY" RIG_PUBLIC_KEY="$PUBLIC_KEY" php "$ROOT/bin/rig/seed.php"

echo
echo "WordPress : http://127.0.0.1:$WP_PORT/protein-guide/"
if [ -n "$REAL_API" ]; then
	echo "CiteCue   : $API_BASE  (real server)"
else
	echo "Stub API  : $API_BASE  (requests logged to $RIG_DIR/fake-api/requests.log)"
fi
echo "Rig       : $RIG_DIR"

if [ "${1:-up}" = "verify" ]; then
	echo
	status=0
	RIG_DIR="$RIG_DIR" WP_PORT="$WP_PORT" RIG_API_BASE="$REAL_API" php "$ROOT/bin/rig/verify.php" || status=$?
	down >/dev/null 2>&1
	exit $status
fi
