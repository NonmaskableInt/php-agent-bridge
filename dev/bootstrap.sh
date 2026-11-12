#!/usr/bin/env bash
# Installs WP into the dev stack, activates the bridge, prints an app password.
# Usage: dev/bootstrap.sh   (stack must be up: $CONTAINER_CLI compose -f dev/compose.yaml up -d)
# CONTAINER_CLI selects the runtime (podman or docker); default: podman if installed, else docker.
set -euo pipefail
CLI="${CONTAINER_CLI:-$(command -v podman >/dev/null 2>&1 && echo podman || echo docker)}"
NET=agent-bridge-dev_default
VOL=agent-bridge-dev_wpdata
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
wpcli() {
  "$CLI" run --rm --network "$NET" --user 33:33 \
    -v "$VOL":/var/www/html \
    -v "$PLUGIN_DIR":/var/www/html/wp-content/plugins/php-agent-bridge:ro \
    -e WORDPRESS_DB_HOST=db -e WORDPRESS_DB_NAME=wp -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp \
    docker.io/library/wordpress:cli wp "$@"
}
# Wait (bounded) for the wp container to have written wp-config.php.
for i in $(seq 1 30); do
  wpcli config path >/dev/null 2>&1 && break
  [ "$i" = 30 ] && { echo "wp-config.php never appeared" >&2; exit 1; }
  sleep 2
done
if ! wpcli core is-installed >/dev/null 2>&1; then
  wpcli core install --url=http://127.0.0.1:8088 --title="Bridge Dev" \
    --admin_user=agent --admin_password=agent-admin-pw --admin_email=dev@example.test --skip-email >&2
fi
wpcli plugin activate php-agent-bridge >&2
wpcli rewrite structure '/%postname%/' --hard >&2
# Fresh app password each run; stdout is the credential for tests.
wpcli user application-password create agent "bridge-dev-$(date +%s)" --porcelain
