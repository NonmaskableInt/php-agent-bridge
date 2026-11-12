#!/usr/bin/env bash
# Runs the wp-agent-mcp contract tests against a throwaway WordPress built from THIS checkout.
# The same script is used by CI and locally.
#
#   dev/run-contract-tests.sh [path-to-wp-agent-mcp] [extra vitest args...]
#
# Needs podman or docker (with compose), and Node 20+. Set CONTAINER_CLI to force one,
# KEEP_STACK=1 to leave the containers running afterwards.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
MCP="${1:-$HERE/../../wp-agent-mcp}"
[ $# -gt 0 ] && shift
[ -d "$MCP" ] || { echo "wp-agent-mcp not found at $MCP (pass its path as the first argument)" >&2; exit 2; }
MCP="$(cd "$MCP" && pwd)"

export CONTAINER_CLI="${CONTAINER_CLI:-$(command -v podman >/dev/null 2>&1 && echo podman || echo docker)}"
CONTAINER=agent-bridge-dev-wp
compose() { "$CONTAINER_CLI" compose -f "$HERE/compose.yaml" "$@"; }

cleanup() {
  status=$?
  if [ "$status" -ne 0 ]; then
    echo "::group::container logs (tests failed)" >&2
    compose logs --tail 80 >&2 || true
    echo "::endgroup::" >&2
  fi
  [ "${KEEP_STACK:-}" = 1 ] || compose down -v >/dev/null 2>&1 || true
  exit "$status"
}
trap cleanup EXIT

echo "Using $CONTAINER_CLI; bridge: $HERE/..; tests: $MCP"
compose down -v >/dev/null 2>&1 || true
compose up -d

APP_PASSWORD="$("$HERE/bootstrap.sh" | tail -1)"
[ -n "$APP_PASSWORD" ] || { echo "bootstrap did not produce an application password" >&2; exit 1; }
[ -n "${GITHUB_ACTIONS:-}" ] && echo "::add-mask::$APP_PASSWORD" # hide it in CI logs

cd "$MCP"
[ -d node_modules ] || npm ci --no-audit --no-fund
BRIDGE_TEST_URL=http://127.0.0.1:8088 \
BRIDGE_TEST_USER=agent \
BRIDGE_TEST_PASSWORD="$APP_PASSWORD" \
BRIDGE_TEST_CONTAINER="$CONTAINER" \
  npx vitest run "$@"
