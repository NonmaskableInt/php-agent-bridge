# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

A WordPress plugin (`agent-bridge.php`) that exposes a structured, agent-friendly REST API at the `agent/v1` namespace. Every write operation is idempotent via the `X-Idempotency-Key` header. All responses follow a consistent `{ ok: bool, data/code/message }` envelope.

There is no build step, no Composer, and no test runner. Development happens directly against a WordPress install.

## Installation

Drop the plugin folder into `wp-content/plugins/` and activate via WP admin or WP-CLI:

```bash
wp plugin activate php-agent-bridge
```

Activation runs `Idempotency::create_table()` which creates `{prefix}agent_idempotency` via `dbDelta`.

## Architecture

### Autoloader

`agent-bridge.php` registers an `spl_autoload_register` mapping `AgentBridge\` → `includes/`. No Composer. File path = namespace path (e.g. `AgentBridge\Routes\PostsRoute` → `includes/Routes/PostsRoute.php`).

### Core Classes

| Class | Role |
|-------|------|
| `Auth` | 4 static permission checkers: `require_edit` (edit_posts), `require_publish` (publish_posts), `require_manage` (manage_options), `require_theme_management` (manage_options + switch_themes). Used as `permission_callback` in route registration. |
| `Response` | Static factory for all REST responses: `success()`, `created()`, `error()`, `not_found()`, `forbidden()`, `unauthorized()`, `validation_error()`. Always use this — never return raw arrays. |
| `Idempotency` | Wrap any mutating handler with `Idempotency::wrap($request, $handler)`. Checks `X-Idempotency-Key` header, replays cached response if key exists, stores response after execution. TTL defaults to 86400s, override via `AGENT_BRIDGE_IDEMPOTENCY_TTL` constant. |

### Route Pattern

Every route file in `includes/Routes/` follows the same structure:

```php
class FooRoute {
    const NAMESPACE = 'agent/v1';

    public static function register(): void {
        register_rest_route( self::NAMESPACE, '/foo', [ ... ] );
    }
    // GET handlers: return Response::success(...)
    // POST/PATCH/DELETE handlers: wrapped with Idempotency::wrap()
}
```

Register new routes in `agent-bridge.php` inside the `rest_api_init` action.

### Optional Modules

`includes/Modules/WooCommerce.php` — loaded only when `class_exists('WooCommerce')`. Follow the same Route pattern.

### Feature-Flagged Routes

`includes/Routes/DatabaseRoute.php` — loaded always but registers no routes unless `AGENT_BRIDGE_DB_API` is `true`. Exposes `GET /db/tables`, `GET /db/schema`, `POST /db/select`, `POST /db/query`. All queries use `$wpdb->prepare()`. DDL blocked by default; `DROP DATABASE`/`DROP SCHEMA` permanently blocked.

`includes/Routes/CliRoute.php` — operates in two modes:
- **Allowlist mode (default):** only commands matching a prefix in `AGENT_BRIDGE_CLI_ALLOWLIST` (or the built-in default list) are permitted.
- **Open mode** (`AGENT_BRIDGE_CLI_OPEN=true`): any WP-CLI command is allowed, including plugin-registered subcommands. A hardcoded denylist (`db drop`, `db reset`, `db clean`, `site delete`, `core download`) is always enforced in both modes.

## Configuration Constants

Define in `wp-config.php`:

- `AGENT_BRIDGE_LOG_REQUESTS` — enable request logging to `wp-content/agent-bridge.log`
- `AGENT_BRIDGE_IDEMPOTENCY_TTL` — idempotency key TTL in seconds (default: 86400)
- `AGENT_BRIDGE_CLI_ALLOWLIST` — array of allowed WP-CLI command prefixes (allowlist mode)
- `AGENT_BRIDGE_CLI_OPEN` — set `true` to allow any WP-CLI command (denylist-only mode)
- `AGENT_BRIDGE_DB_API` — set `true` to enable the `/db/*` endpoints (disabled by default)
- `AGENT_BRIDGE_DB_ALLOW_DDL` — set `true` to permit DDL statements (`DROP TABLE`, `TRUNCATE`, `ALTER TABLE`, `CREATE TABLE`, `RENAME TABLE`)

## Capability Tiers

| Capability | Auth method | Used for |
|-----------|-------------|----------|
| `edit_posts` | `Auth::require_edit` | All reads |
| `publish_posts` | `Auth::require_publish` | Create/update |
| `manage_options` | `Auth::require_manage` | Delete, options, plugins |
| `manage_options` + `switch_themes` | `Auth::require_theme_management` | Theme switching |

## Adding a New Route

1. Create `includes/Routes/NewThingRoute.php` in namespace `AgentBridge\Routes`
2. Implement `public static function register(): void` with `register_rest_route('agent/v1', ...)`
3. Use `Auth::require_*` for `permission_callback`
4. Wrap all mutating callbacks: `fn($r) => Idempotency::wrap($r, [self::class, 'handler'])`
5. Return `Response::success()` / `Response::created()` / `Response::error()` — never raw arrays
6. Add `\AgentBridge\Routes\NewThingRoute::register();` in `agent-bridge.php`

## Endpoints Reference

See `endpoints.md` for the full list of all registered endpoints with parameters and auth requirements.
