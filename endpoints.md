# Agent Bridge — API Endpoints

**Base URL:** `/wp-json/agent/v1`
**Auth:** `Authorization: Basic base64(username:app_password)` (WordPress Application Passwords)
**Idempotency:** Pass `X-Idempotency-Key: <uuid>` on any mutating request to enable replay-safe retries (24 h TTL).

All responses use a consistent envelope:
```json
{ "ok": true,  "data": { ... } }
{ "ok": false, "code": "error_code", "message": "...", "data": { "status": 404 } }
```

---

## Posts

| Method | Route | Capability | Description |
|--------|-------|------------|-------------|
| `GET` | `/posts` | `edit_posts` | List posts |
| `GET` | `/posts/{id}` | `edit_posts` | Get single post with meta, terms, and ACF |
| `POST` | `/posts` | `publish_posts` | Create post |
| `PATCH` | `/posts/{id}` | `publish_posts` | Partial update — only supplied fields change |
| `DELETE` | `/posts/{id}` | `manage_options` | Move to trash; `?force=true` for permanent delete |
| `POST` | `/posts/{id}/duplicate` | `publish_posts` | Clone post as draft, copying all meta and terms |

### GET /posts — query params
| Param | Type | Default | Notes |
|-------|------|---------|-------|
| `post_type` | string | `post` | Any registered post type |
| `status` | string | `publish` | `draft`, `publish`, `private`, `future`, `any` |
| `per_page` | integer | `20` | Max 100 |
| `page` | integer | `1` | |
| `search` | string | | Full-text search |
| `tag` | string | | Tag slug |
| `category` | string | | Category slug |

### POST /posts — body schema
```json
{
  "title": "string",
  "content": "string (HTML or blocks JSON)",
  "excerpt": "string",
  "status": "draft|publish|private|future",
  "post_type": "post|page|product|...",
  "date": "ISO 8601 (for scheduled posts)",
  "terms": {
    "category": ["slug-a"],
    "post_tag": ["tag1"],
    "custom_taxonomy": ["term-slug"]
  },
  "meta": { "arbitrary_key": "value" },
  "acf": { "field_key_or_name": "value" },
  "featured_media": 123
}
```
`PATCH /posts/{id}` accepts the same fields; omitted fields are unchanged.

---

## Media

| Method | Route | Capability | Description |
|--------|-------|------------|-------------|
| `GET` | `/media` | `edit_posts` | List attachments |
| `GET` | `/media/{id}` | `edit_posts` | Get attachment with all sizes, alt, caption, description |
| `POST` | `/media/upload` | `publish_posts` | Upload new file — multipart or base64 JSON |
| `PATCH` | `/media/{id}` | `publish_posts` | Update alt text, caption, description, title |
| `POST` | `/media/{id}/replace` | `publish_posts` | Replace file on disk; preserves attachment ID and all associations |
| `POST` | `/media/{id}/regenerate` | `publish_posts` | Regenerate all thumbnail sizes |
| `DELETE` | `/media/{id}` | `manage_options` | Delete attachment and all sizes. Requires `?force=true` |
| `GET` | `/media/{id}/sizes` | `edit_posts` | Return all registered image sizes and their URLs |

### GET /media — query params
| Param | Type | Default | Notes |
|-------|------|---------|-------|
| `mime_type` | string | | e.g. `image/jpeg`, `application/pdf` |
| `search` | string | | |
| `parent_post` | integer | | Filter by attached post ID |
| `per_page` | integer | `20` | Max 100 |
| `page` | integer | `1` | |

### POST /media/upload — option A (multipart)
```
Content-Type: multipart/form-data
file=<binary>
alt=Hero image alt text
caption=Optional caption
post_parent=456
```

### POST /media/upload — option B (base64 JSON)
```json
{
  "filename": "hero.jpg",
  "data": "<base64-encoded bytes>",
  "mime_type": "image/jpeg",
  "alt": "Alt text",
  "caption": "Caption",
  "post_parent": 456
}
```

### POST /media/{id}/replace
Accepts the same body as `/media/upload`. The plugin overwrites the original file, updates `_wp_attached_file` and `_wp_attachment_metadata`, and regenerates all thumbnail sizes. The attachment ID and all post references remain unchanged.

---

## Taxonomies

| Method | Route | Capability | Description |
|--------|-------|------------|-------------|
| `GET` | `/taxonomies` | `edit_posts` | List all registered taxonomies |
| `GET` | `/terms/{taxonomy}` | `edit_posts` | List terms in a taxonomy |
| `POST` | `/terms/{taxonomy}` | `publish_posts` | Create term |
| `PATCH` | `/terms/{taxonomy}/{id}` | `publish_posts` | Update term |
| `DELETE` | `/terms/{taxonomy}/{id}` | `manage_options` | Delete term (orphaned posts are not deleted) |

### GET /terms/{taxonomy} — query params
| Param | Type | Default | Notes |
|-------|------|---------|-------|
| `search` | string | | |
| `parent` | integer | `0` | Filter by parent term ID |
| `hide_empty` | boolean | `false` | Exclude terms with no posts |
| `per_page` | integer | `100` | Max 500 |

### POST /terms/{taxonomy} — body schema
```json
{
  "name": "string (required)",
  "slug": "string",
  "parent": 0,
  "description": "string"
}
```

---

## Site Options

| Method | Route | Capability | Description |
|--------|-------|------------|-------------|
| `GET` | `/options` | `manage_options` | Get options by key |
| `PATCH` | `/options` | `manage_options` | Update options |

Keys are restricted to the `AGENT_BRIDGE_ALLOWED_OPTIONS` allowlist (default: `blogname`, `blogdescription`, `siteurl`, `home`, `default_comment_status`, `posts_per_page`).

### GET /options — query params
| Param | Type | Notes |
|-------|------|-------|
| `keys[]` | string (repeatable) | e.g. `?keys[]=blogname&keys[]=siteurl`. Omit to return all allowlisted options. |

### PATCH /options — body schema
```json
{ "blogname": "New Site Title", "posts_per_page": 10 }
```

---

## Menus

| Method | Route | Capability | Description |
|--------|-------|------------|-------------|
| `GET` | `/menus` | `edit_posts` | List all nav menus with their assigned theme locations |
| `GET` | `/menus/{id}` | `edit_posts` | Get menu with full nested item tree |
| `POST` | `/menus/{id}/items` | `publish_posts` | Add item to menu |
| `PATCH` | `/menus/items/{item_id}` | `publish_posts` | Update menu item |
| `DELETE` | `/menus/items/{item_id}` | `manage_options` | Remove item from menu |

### POST /menus/{id}/items — body schema
```json
{
  "type": "post_type|taxonomy|custom",
  "object_id": 42,
  "object": "page",
  "title": "string",
  "url": "https://... (for custom type)",
  "parent_item_id": 0,
  "position": 1
}
```

### PATCH /menus/items/{item_id} — updatable fields
`title`, `url`, `target`, `parent_item_id`, `position`

---

## Users _(read-only)_

| Method | Route | Capability | Description |
|--------|-------|------------|-------------|
| `GET` | `/users` | `manage_options` | List users |
| `GET` | `/users/{id}` | `manage_options` | Get user with meta, capabilities, avatar |
| `GET` | `/users/me` | `edit_posts` | Get the currently authenticated user |

### GET /users — query params
| Param | Type | Default | Notes |
|-------|------|---------|-------|
| `role` | string | | Filter by role slug, e.g. `editor`, `subscriber` |
| `search` | string | | Matches login, email, display name |
| `per_page` | integer | `20` | Max 100 |
| `page` | integer | `1` | |
| `orderby` | string | `registered` | `ID`, `login`, `nicename`, `email`, `registered`, `display_name`, `post_count` |
| `order` | string | `DESC` | `ASC` or `DESC` |

### GET /users/{id} — full response includes
`first_name`, `last_name`, `description`, `capabilities` (array of granted caps), `meta` (public keys, excluding `_` and `wp_` prefixed), `post_count`, `avatar_url`

---

## Plugins

All plugin routes require `manage_options`. Install and delete additionally require `AGENT_BRIDGE_ALLOW_PLUGIN_INSTALL=true`.

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/plugins` | List all installed plugins with status, version, update availability |
| `GET` | `/plugins/{slug}` | Get plugin details |
| `POST` | `/plugins/install` | Download and install from wordpress.org or a zip URL |
| `POST` | `/plugins/{slug}/activate` | Activate plugin |
| `POST` | `/plugins/{slug}/deactivate` | Deactivate plugin |
| `POST` | `/plugins/{slug}/update` | Update to latest version |
| `DELETE` | `/plugins/{slug}` | Delete plugin files (must be deactivated first) |
| `GET` | `/plugins/{slug}/options` | Get plugin options (allowlisted keys only) |
| `PATCH` | `/plugins/{slug}/options` | Update plugin options (allowlisted keys only) |

### POST /plugins/install — body schema
```json
{ "source": "wordpress.org", "slug": "woocommerce", "activate": false }
```
```json
{ "source": "url", "url": "https://example.com/plugin.zip", "activate": false }
```
`activate: true` requires `AGENT_BRIDGE_ALLOW_PLUGIN_AUTOACTIVATE=true`.

### Plugin option allowlist
Configure in `wp-config.php`:
```php
define('AGENT_BRIDGE_PLUGIN_OPTIONS_ALLOWLIST', [
    'woocommerce_currency',
    'rank_math_modules',
]);
```

### Error codes specific to plugins
| Code | Meaning |
|------|---------|
| `plugin_not_found` | Slug not installed |
| `plugin_still_active` | Delete attempted on active plugin |
| `plugin_activation_error` | Activation hook threw an error |
| `plugin_install_disabled` | `AGENT_BRIDGE_ALLOW_PLUGIN_INSTALL` is false |
| `plugin_option_not_allowed` | Key not in plugin options allowlist |
| `plugin_network_only` | Plugin requires network activation on multisite |

---

## Themes

All theme routes require `manage_options` + `switch_themes`. Install and delete additionally require `AGENT_BRIDGE_ALLOW_THEME_INSTALL=true`.

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/themes` | List all installed themes with status and update availability |
| `GET` | `/themes/{stylesheet}` | Get theme details |
| `POST` | `/themes/install` | Install from wordpress.org or a zip URL |
| `POST` | `/themes/{stylesheet}/activate` | Switch active theme (resolves parent for child themes) |
| `POST` | `/themes/{stylesheet}/update` | Update to latest version |
| `DELETE` | `/themes/{stylesheet}` | Delete theme files (active theme cannot be deleted) |
| `GET` | `/themes/mods` | Get all `theme_mods` for the active theme |
| `PATCH` | `/themes/mods` | Update theme mods — only recognized keys accepted |
| `GET` | `/themes/customizer` | Export customizer settings (theme mods + widgets) as JSON |
| `POST` | `/themes/customizer` | Import customizer settings from JSON blob |

### POST /themes/install — body schema
```json
{ "source": "wordpress.org", "slug": "astra", "activate": false }
```
```json
{ "source": "url", "url": "https://example.com/theme.zip", "activate": false }
```

### PATCH /themes/mods — body schema
Only keys already present in `get_theme_mods()` or the well-known set (`custom_logo`, `header_textcolor`, `header_image`, `background_color`, `background_image`, `custom_css_post_id`, `nav_menu_locations`) are accepted. Unknown keys return `theme_mod_not_allowed`.
```json
{ "custom_logo": 123, "header_textcolor": "#333333" }
```

### POST /themes/customizer — body schema
```json
{
  "stylesheet": "astra",
  "theme_mods": { "custom_logo": 123 },
  "widget_options": { "widget_text": { "2": { "title": "Hello" } } },
  "sidebars": { "sidebar-1": ["text-2"] }
}
```
`theme_mods` is required. `widget_options` and `sidebars` are optional. If `stylesheet` does not match the active theme a warning action is fired but the import proceeds.

### Error codes specific to themes
| Code | Meaning |
|------|---------|
| `theme_not_found` | Stylesheet not installed |
| `theme_is_active` | Delete attempted on active theme |
| `theme_parent_missing` | Child theme's parent not installed |
| `theme_install_disabled` | `AGENT_BRIDGE_ALLOW_THEME_INSTALL` is false |
| `theme_mod_not_allowed` | Mod key not recognized |
| `customizer_import_invalid` | JSON failed schema validation |

---

## WP-CLI Proxy

| Method | Route | Capability | Description |
|--------|-------|------------|-------------|
| `POST` | `/wp-cli` | `manage_options` | Run a WP-CLI command |

### POST /wp-cli — body schema
```json
{ "command": "cache flush" }
```

The `command` string is parsed into discrete arguments (handles quoted strings) and passed to `proc_open` as an array — no shell interpolation ever occurs.

### Response
```json
{
  "ok": true,
  "data": {
    "command": "cache flush",
    "stdout": "Success: Cache flushed.",
    "stderr": "",
    "exit_code": 0
  }
}
```

### Modes

**Allowlist mode (default)** — only commands matching a prefix in the allowlist are permitted. Override the list via:
```php
define('AGENT_BRIDGE_CLI_ALLOWLIST', ['cache flush', 'rewrite flush', 'my-plugin command']);
```

Default allowlist: `cache flush`, `cron event run --due-now`, `rewrite flush`, `option get`, `plugin list`, `plugin get`, `plugin verify-checksums`, `theme list`, `theme get`, `search-replace --dry-run`, `media regenerate --yes`, `post list`, `language core list`, `language plugin list`

**Open mode** — allows any WP-CLI command, including plugin-registered subcommands, subject only to the permanent denylist below:
```php
define('AGENT_BRIDGE_CLI_OPEN', true);
```

### Permanent denylist (always blocked in both modes)
| Prefix | Reason |
|--------|--------|
| `db drop` | Drops entire database |
| `db reset` | Drops and recreates database |
| `db clean` | Removes all tables |
| `site delete` | Permanently deletes a multisite site |
| `core download` | Downloads WP core — use admin UI |

### Error codes
| Code | Meaning |
|------|---------|
| `cli_command_not_allowed` | Command not in allowlist (allowlist mode) |
| `cli_command_denied` | Command matched permanent denylist |
| `cli_not_available` | WP-CLI binary not found in PATH |
| `cli_exec_failed` | `proc_open` failed to start the process |

---

## Database

**Disabled by default.** Enable via:
```php
define('AGENT_BRIDGE_DB_API', true);
```

All routes require `manage_options`. Write queries require `X-Idempotency-Key`.

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/db/tables` | List all tables in the database |
| `GET` | `/db/schema?table={name}` | Columns and indexes for a table |
| `POST` | `/db/select` | Run a SELECT / SHOW / EXPLAIN query |
| `POST` | `/db/query` | Run an INSERT / UPDATE / DELETE query |

### GET /db/schema — query params
| Param | Type | Notes |
|-------|------|-------|
| `table` | string (required) | Exact table name, e.g. `wp_posts` |

### POST /db/select — body schema
```json
{
  "sql": "SELECT ID, post_title FROM wp_posts WHERE post_status = %s LIMIT %d",
  "params": ["publish", 10]
}
```
`params` is optional. Placeholders: `%s` (string), `%d` (integer), `%f` (float) — passed through `$wpdb->prepare()`.

### POST /db/select — response
```json
{
  "ok": true,
  "data": {
    "rows": [{ "ID": "1", "post_title": "Hello World" }],
    "row_count": 1
  }
}
```

### POST /db/query — body schema
```json
{
  "sql": "UPDATE wp_options SET option_value = %s WHERE option_name = %s",
  "params": ["new-value", "blogname"]
}
```

### POST /db/query — response
```json
{
  "ok": true,
  "data": {
    "rows_affected": 1,
    "insert_id": 0
  }
}
```

### Statement restrictions
DDL statements (`DROP TABLE`, `TRUNCATE`, `ALTER TABLE`, `CREATE TABLE`, `RENAME TABLE`) are blocked by default. Enable via:
```php
define('AGENT_BRIDGE_DB_ALLOW_DDL', true);
```

`DROP DATABASE` and `DROP SCHEMA` are permanently blocked regardless of any flag.

### Error codes
| Code | Meaning |
|------|---------|
| `db_error` | `$wpdb` returned an error |
| `select_only` | Write statement sent to `/db/select` |
| `use_select_endpoint` | SELECT sent to `/db/query` |
| `statement_blocked` | Matched denylist or DDL restriction |
| `param_mismatch` | Placeholder count ≠ param count |

---

## WooCommerce _(optional module)_

Loaded automatically when WooCommerce is active. All order routes require `manage_options`; product read routes require `edit_posts`; product write routes require `publish_posts` or `manage_options`.

### Orders

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/wc/orders` | List orders |
| `GET` | `/wc/orders/{id}` | Get order with line items and notes |
| `PATCH` | `/wc/orders/{id}` | Update order status or add note |

#### GET /wc/orders — query params
| Param | Type | Default |
|-------|------|---------|
| `status` | string | `any` |
| `per_page` | integer | `20` |
| `page` | integer | `1` |

#### PATCH /wc/orders/{id} — body schema
```json
{ "status": "completed", "note": "Shipped via FedEx" }
```

### Products

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/wc/products` | List products |
| `GET` | `/wc/products/{id}` | Get product with description, pricing, stock, categories |
| `POST` | `/wc/products` | Create product |
| `PATCH` | `/wc/products/{id}` | Partial update |
| `DELETE` | `/wc/products/{id}` | Delete product (`?force=true` for permanent) |

#### POST /wc/products — body schema
```json
{
  "name": "string (required)",
  "description": "string",
  "short_description": "string",
  "sku": "string",
  "regular_price": "19.99",
  "sale_price": "14.99",
  "status": "draft|publish",
  "stock_quantity": 50,
  "featured_media": 123
}
```

---

## Global Error Codes

| Code | HTTP | Meaning |
|------|------|---------|
| `auth_required` | 401 | Missing or invalid credentials |
| `insufficient_capability` | 403 | User lacks required capability |
| `validation_error` | 422 | Missing required field or type mismatch |
| `not_found` | 404 | Generic resource not found |
| `post_not_found` | 404 | Post ID does not exist |
| `media_not_found` | 404 | Attachment ID does not exist |
| `media_upload_failed` | 500 | File write or sideload error |
| `term_not_found` | 404 | Taxonomy term does not exist |
| `taxonomy_not_found` | 404 | Taxonomy slug does not exist |
| `option_not_allowed` | 403 | Option key not in allowlist |
| `menu_not_found` | 404 | Nav menu ID does not exist |
| `menu_item_not_found` | 404 | Nav menu item ID does not exist |
| `user_not_found` | 404 | User ID does not exist |
| `plugin_not_found` | 404 | Plugin slug not installed |
| `plugin_still_active` | 409 | Delete attempted on active plugin |
| `plugin_activation_error` | 500 | Plugin activation hook threw an error |
| `plugin_install_disabled` | 403 | `AGENT_BRIDGE_ALLOW_PLUGIN_INSTALL` is false |
| `plugin_option_not_allowed` | 403 | Key not in plugin options allowlist |
| `theme_not_found` | 404 | Theme stylesheet not installed |
| `theme_is_active` | 409 | Delete attempted on active theme |
| `theme_parent_missing` | 422 | Child theme's parent not installed |
| `theme_install_disabled` | 403 | `AGENT_BRIDGE_ALLOW_THEME_INSTALL` is false |
| `theme_mod_not_allowed` | 422 | Theme mod key not recognized |
| `cli_command_not_allowed` | 403 | Command not in WP-CLI allowlist |
| `cli_command_denied` | 403 | Command matched permanent denylist |
| `cli_not_available` | 500 | WP-CLI binary not found in PATH |
| `cli_exec_failed` | 500 | `proc_open` failed to start |
| `db_error` | 500 | `$wpdb` query error |
| `select_only` | 400 | Write statement sent to `/db/select` |
| `use_select_endpoint` | 400 | SELECT sent to `/db/query` |
| `statement_blocked` | 403 | SQL matched denylist or DDL restriction |
| `param_mismatch` | 422 | Placeholder count ≠ param count |
| `idempotency_replay` | — | Replayed key — cached response returned with `X-Idempotency-Replay: true` header |

---

## Configuration Reference

```php
// wp-config.php

// Allowlisted site option keys (GET/PATCH /options)
define('AGENT_BRIDGE_ALLOWED_OPTIONS', [
    'blogname', 'blogdescription', 'siteurl', 'home',
    'default_comment_status', 'posts_per_page',
]);

// Allowlisted WP-CLI commands (POST /wp-cli)
define('AGENT_BRIDGE_CLI_ALLOWLIST', [
    'cache flush',
    'cron event run --due-now',
    'rewrite flush',
]);

// Plugin management — all false by default
define('AGENT_BRIDGE_ALLOW_PLUGIN_INSTALL', false);      // enables install + delete
define('AGENT_BRIDGE_ALLOW_PLUGIN_AUTOACTIVATE', false); // allows activate:true in install body
define('AGENT_BRIDGE_ALLOW_NETWORK_PLUGIN_OPS', false);  // multisite network-activated plugins

// Theme management — false by default
define('AGENT_BRIDGE_ALLOW_THEME_INSTALL', false);       // enables install + delete

// Option keys exposed via GET/PATCH /plugins/{slug}/options
define('AGENT_BRIDGE_PLUGIN_OPTIONS_ALLOWLIST', [
    'woocommerce_store_address',
    'woocommerce_currency',
]);

// WP-CLI: open mode (any command, denylist only)
define('AGENT_BRIDGE_CLI_OPEN', false);

// WP-CLI: custom allowlist (used in allowlist mode only)
define('AGENT_BRIDGE_CLI_ALLOWLIST', ['cache flush', 'cron event run --due-now']);

// Database API (disabled by default)
define('AGENT_BRIDGE_DB_API', false);
define('AGENT_BRIDGE_DB_ALLOW_DDL', false); // enables DROP TABLE, TRUNCATE, ALTER, CREATE

// Idempotency TTL in seconds (default 24 h)
define('AGENT_BRIDGE_IDEMPOTENCY_TTL', 86400);

// Request logging to wp-content/agent-bridge.log
define('AGENT_BRIDGE_LOG_REQUESTS', true);
```
