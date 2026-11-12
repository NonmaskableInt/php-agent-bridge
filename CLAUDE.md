# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

A WordPress plugin (`agent-bridge.php`) that exposes a structured, agent-friendly REST API at the `agent/v1` namespace. Every write operation is idempotent via the `X-Idempotency-Key` header. All responses follow a consistent `{ ok: bool, data/code/message }` envelope.

There is no build step and no Composer. Validation runs against a throwaway WordPress in containers (`dev/`), driven by the contract tests in the sibling `wp-agent-mcp` repo. Needs `podman` or `docker` (with compose) and Node 20+.

```bash
dev/run-contract-tests.sh ../wp-agent-mcp        # builds the stack, runs every test, tears it down
KEEP_STACK=1 dev/run-contract-tests.sh ../wp-agent-mcp tests/contract/files.test.ts   # one file, keep the containers

# or by hand:
podman compose -f dev/compose.yaml up -d        # WP + MariaDB on 127.0.0.1:8088 (containers: agent-bridge-dev-wp / -db)
dev/bootstrap.sh                                # installs WP, activates the bridge, prints an app password
cd ../wp-agent-mcp && BRIDGE_TEST_URL=http://127.0.0.1:8088 BRIDGE_TEST_USER=agent \
  BRIDGE_TEST_PASSWORD='<app password>' BRIDGE_TEST_CONTAINER=agent-bridge-dev-wp npx vitest run
# BRIDGE_TEST_CONTAINER is optional: it enables the tests that edit wp-config.php or write hostile data into the DB
podman compose -f dev/compose.yaml down -v      # discard everything
```

`CONTAINER_CLI=docker` (or `podman`) selects the runtime; the default is podman if installed, else docker. **CI** (`.github/workflows/ci.yml`) lints every PHP file on PHP 8.0–8.4 and runs the same script against `wp-agent-mcp` — the branch with the same name as the PR's if it exists, otherwise `main`, so paired PRs test each other.

`WORDPRESS_CONFIG_EXTRA` in `dev/compose.yaml` only applies when `wp-config.php` is first created — after changing it, rebuild with `down -v`. The stack enables `AGENT_BRIDGE_ALLOW_FILE_WRITE`; production defaults stay off. `dev/php-dev.ini` sets `opcache.revalidate_freq=0` so PHP edits take effect immediately (with PHP's default of 2s, a test run right after an edit can silently run the old code — this makes mutation checks unreliable).

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
- **Open mode** (`AGENT_BRIDGE_CLI_OPEN=true`): **bypasses all file/PHP guardrails** (`wp eval`, `eval-file`, `shell`, `config set`, `db query`, … run with no lint, backup, rollback, write gate or DISALLOW_* check) — documented, deliberately not hardened. Any WP-CLI command is allowed, including plugin-registered subcommands. A hardcoded denylist (`db drop`, `db reset`, `db clean`, `site delete`, `core download`) is always enforced in both modes.

`includes/Routes/FilesRoute.php` — read/write/delete files under `wp-content/{themes,plugins,mu-plugins,agent-apps}`; writes are off unless `AGENT_BRIDGE_ALLOW_FILE_WRITE` is `true`. Supporting classes in `includes/Files/`: `PathGuard` (path jail, protected paths incl. the bridge itself, write denylist/extension allowlist), `PhpGuard` (`php -l` lint + a separate-process WordPress boot as the health check; fails closed), `Backups` (versioned copies, `audit.log` and `requests.log` in a non-web-served directory — see `Backups::dir()`), `WriteGate` (the single place that decides if writes are allowed: opt-in constant, `DISALLOW_FILE_MODS`/`DISALLOW_FILE_EDIT` with no exemptions, per-root capabilities incl. `unfiltered_html` for agent-apps; used by both `/files` and `/apps`). Every PHP write is linted, backed up, swapped atomically, health-checked, and rolled back if WordPress stops booting. Never weaken a guard without a contract test that fails when it is removed.

`includes/Routes/AppsRoute.php` — deploy built front-end bundles (zip) to `wp-content/agent-apps/{slug}/releases/` and serve the live release at a mount path. Supporting classes in `includes/Apps/`: `Archive` (validates every entry before extracting; no PHP, no hidden files, no symlinks, no traversal), `Registry` (option-backed app registry, reserved WordPress mounts, time-ordered release ids, pruning), `Server` (static serving on `template_redirect` priority 0, SPA fallback, never intercepts WordPress entry points). Same `AGENT_BRIDGE_ALLOW_FILE_WRITE` gate as `/files`.

`includes/Routes/HeadlessRoute.php` — headless-CMS configuration (options only, no file writes): CORS allowlist, optional redirect of the public site to a separate front end, editor preview links. Supporting classes in `includes/Headless/`: `Config` (all-or-nothing validation incl. cross-field rules; strict origin/URL parsing), `Runtime` (hooks: replaces core's reflect-any-origin CORS only when an allowlist is set; redirect on `template_redirect` priority 1, after `Apps\Server`; `preview_post_link` filter). The redirect builds its target as `frontend_url` + path, so it cannot be steered to another host.

`includes/Routes/ContentModelRoute.php` — custom post types, taxonomies and typed meta fields defined as data (option `agent_bridge_content_model`), registered on `init` priority 0 by `ContentModel\Runtime` with `show_in_rest` forced on; options only, no file writes. `ContentModel\Model` does strict all-or-nothing validation and, **independently**, filters reserved names when reading (runtime must never trust stored data — a bad DB value must not redefine core). A broken definition is skipped, never fatal. Rewrite rules flush on the request after a change (flag option).

`includes/Options/` + `includes/Routes/OptionsRoute.php` — typed, grouped site options. `Registry` holds the groups, a typed spec per key, the never-settable list (with reasons) and which groups are enabled (`AGENT_BRIDGE_OPTION_GROUPS`); `Validator` checks values. Writes are two-phase (validate every key and collect every error, then apply), so a bad value never leaves a half-applied batch. Plain permalinks are refused because they remove `/wp-json/`, the endpoint the agent itself uses.

`includes/Routes/TemplatesRoute.php` + `includes/Templates/` — what renders a page, and block-template overrides. `Store` reads with WordPress's resolver and writes through core's REST controllers (`rest_do_request`) so Site-Editor behaviour cannot drift; `Resolver` + `resolve-worker.php` answer "which template renders this URL" in a separate CLI process (it sets `$_GET` and server vars, and removes `template_redirect` actions so canonical/headless redirects do not interfere); `Classic` lists a classic theme's PHP template files. Key idea: a database override masks the theme file, so everything reports `source` and `masks_theme_file`.

`includes/Routes/ThemeToolsRoute.php` + `includes/Themes/` — read-only validators. `BlockMarkup` checks the `<!-- wp:… -->` syntax with its own tokenizer (balance, JSON attributes, unregistered blocks, free-form HTML) because WordPress's parser silently accepts broken markup; `Validator` checks a theme's structure, `theme.json` (including which properties WordPress's sanitiser drops, found by diffing against `WP_Theme_JSON`), template-part references, and PHP syntax. `template_set` returns the markup check as `validation`.

`includes/Routes/GlobalStylesRoute.php` + `includes/GlobalStyles/Store.php` — the Site Editor's user-level theme.json layer (a `wp_global_styles` post that overrides the theme's `theme.json`). Reads the post directly (no resolver cache), merges objects key-by-key (lists replaced), **sanitises through `WP_Theme_JSON` before saving and reports what was dropped**, and writes through core's REST controller. Backups are restored only by exact name pattern (`NAME_RE`), which is what stops path traversal into sibling backup folders — covered by a test using a real file.

`includes/Themes/Scaffold.php` (route: `ThemeToolsRoute::scaffold`) — creates block, classic or child themes. Writes to a hidden `.agent-scaffold-*` staging directory, passes every path through `PathGuard`, lints generated PHP, requires `Validator` to pass, then renames into place; never activates, never overwrites. Gated by `WriteGate` like `/files`. Header fields are rejected if they contain `*/`, `<?`, newlines or control characters (they are written into `style.css`).

`includes/Routes/StatusRoute.php` — `GET /status`: version, route count, WordPress/PHP/theme, enabled features (file writes, DISALLOW_FILE_* blocking, CLI mode, DB API, content model, option groups, whether a custom backup dir is set, whether PHP can be verified) and the caller's capabilities. Used by the MCP server's `bridge_status` / `--check`. Never reports secrets or filesystem paths — a test asserts this.

## Configuration Constants

Define in `wp-config.php`:

- `AGENT_BRIDGE_LOG_REQUESTS` — enable request logging to `requests.log` in the backup directory
- `AGENT_BRIDGE_BACKUP_DIR` — where backups, `audit.log` and `requests.log` live; set outside the web root (default: unguessable `wp-content/agent-bridge-<random>` with deny rules)
- `AGENT_BRIDGE_IDEMPOTENCY_TTL` — idempotency key TTL in seconds (default: 86400)
- `AGENT_BRIDGE_CLI_ALLOWLIST` — array of allowed WP-CLI command prefixes (allowlist mode)
- `AGENT_BRIDGE_CLI_OPEN` — set `true` to allow any WP-CLI command (denylist-only mode)
- `AGENT_BRIDGE_ALLOW_FILE_WRITE` — set `true` to enable `/files` writes, deletes and restores (disabled by default)
- `AGENT_BRIDGE_DISABLE_CONTENT_MODEL` — set `true` to stop registering the stored content model (emergency switch)
- `AGENT_BRIDGE_OPTION_GROUPS` — option groups exposed (default `general`, `reading`, `discussion`, `media`; also `permalinks`, `site_url`)
- `AGENT_BRIDGE_ALLOWED_OPTIONS` — legacy explicit key list; replaces the groups when defined
- `AGENT_BRIDGE_PHP_BINARY` — optional path to the PHP CLI used for lint and health checks (auto-detected otherwise)
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
