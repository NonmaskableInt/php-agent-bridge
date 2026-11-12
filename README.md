# Agent Bridge

A WordPress plugin that exposes a structured, agent-friendly REST API at the `agent/v1` namespace. Every write operation is idempotent via the `X-Idempotency-Key` header. All responses follow a consistent `{ ok, data/code/message }` envelope.

## Features

- **Idempotent writes** — replay-safe via `X-Idempotency-Key`
- **Consistent JSON envelope** — `{ ok: bool, data | code + message }`
- **Capability-tiered auth** — four permission levels mapped to WordPress capabilities
- **Feature-flagged routes** — database and CLI endpoints disabled by default
- **WooCommerce module** — auto-loaded when WooCommerce is active

## Installation

Drop the plugin folder into `wp-content/plugins/` and activate:

```bash
wp plugin activate php-agent-bridge
```

Activation creates the `{prefix}agent_idempotency` table via `dbDelta`.

## Authentication

All endpoints use WordPress Application Passwords:

```
Authorization: Basic base64(username:app_password)
```

## Configuration

Define in `wp-config.php`:

| Constant | Default | Description |
|----------|---------|-------------|
| `AGENT_BRIDGE_LOG_REQUESTS` | `false` | Log requests to `requests.log` in the (non-web-served) backup directory |
| `AGENT_BRIDGE_BACKUP_DIR` | `wp-content/agent-bridge-<random>` | Where backups, `audit.log` and `requests.log` are kept — set to a path outside the web root |
| `AGENT_BRIDGE_IDEMPOTENCY_TTL` | `86400` | Idempotency key TTL in seconds |
| `AGENT_BRIDGE_CLI_ALLOWLIST` | built-in list | Array of allowed WP-CLI command prefixes |
| `AGENT_BRIDGE_CLI_OPEN` | `false` | Allow any WP-CLI command. **Bypasses every file/PHP guardrail** (`wp eval` and friends run unguarded) — see endpoints.md before enabling |
| `AGENT_BRIDGE_DB_API` | `false` | Enable `/db/*` endpoints |
| `AGENT_BRIDGE_DB_ALLOW_DDL` | `false` | Permit DDL statements in DB API |

## Endpoints

See [endpoints.md](endpoints.md) for the full reference.

**Routes registered:**

- `GET/POST/PATCH/DELETE /agent/v1/posts`
- `GET/POST/PATCH/DELETE /agent/v1/media`
- `GET/POST/PATCH/DELETE /agent/v1/terms`
- `GET/PATCH /agent/v1/options`
- `GET/POST/PATCH/DELETE /agent/v1/users`
- `GET/POST/PATCH/DELETE /agent/v1/menus`
- `GET/POST/PATCH/DELETE /agent/v1/plugins`
- `GET/POST /agent/v1/themes`
- `POST /agent/v1/cli` *(feature-flagged)*
- `GET/POST /agent/v1/db/*` *(feature-flagged)*
- WooCommerce routes *(auto-loaded when WooCommerce active)*

## Adding a Route

1. Create `includes/Routes/NewThingRoute.php` in namespace `AgentBridge\Routes`
2. Implement `public static function register(): void`
3. Use `Auth::require_*` for `permission_callback`
4. Wrap mutating callbacks: `fn($r) => Idempotency::wrap($r, [self::class, 'handler'])`
5. Return `Response::success()` / `Response::created()` / `Response::error()`
6. Register in `agent-bridge.php` inside the `rest_api_init` action

## Requirements

- PHP 8.1+
- WordPress 6.0+

## License

MIT
