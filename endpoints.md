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

## Files

**Writes disabled by default.** Enable via:
```php
define('AGENT_BRIDGE_ALLOW_FILE_WRITE', true);
```

Reads need `manage_options`. Writes additionally need `edit_themes` (themes) or `edit_plugins` (plugins, mu-plugins), or only `manage_options` for `agent-apps`. Writes are refused when `DISALLOW_FILE_MODS` is set, and (except for `agent-apps`) when `DISALLOW_FILE_EDIT` is set.

All paths are relative to `wp-content` and limited to the roots `themes`, `plugins`, `mu-plugins` and `agent-apps`. `wp-admin`, `wp-includes`, `wp-config.php`, symlinks, traversal (`..`) and the bridge plugin's own directory are never accessible.

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/files?path=` | List a directory (no `path` lists the roots) |
| `GET` | `/files/content?path=` | Read a file — `{ content, encoding, size, sha256 }` (2 MB limit) |
| `POST` | `/files/content` | Create or replace a file |
| `DELETE` | `/files?path=&recursive=` | Delete a file (backed up) or, with `recursive=true`, a directory |
| `GET` | `/files/backups?path=` | List stored previous versions, newest first |
| `POST` | `/files/restore` | Restore a backup (latest unless `backup_id` given) |

### POST /files/content — body schema
```json
{
  "path": "themes/my-theme/functions.php",
  "content": "<?php ...",
  "encoding": "utf8",
  "expected_sha256": "optional — fail with 409 if the file changed since this hash"
}
```
`encoding` is `utf8` (default) or `base64` (required for binary files). Max 5 MB.

### Write pipeline
1. Path jail, root and extension allowlist (`php js mjs css json html htm txt md xml svg map twig png jpg jpeg gif webp ico woff woff2 ttf`). `.htaccess`, `.user.ini`, `php.ini`, `.htpasswd` and `wp-config.php` are never writable. PHP is not allowed inside `agent-apps`.
2. **PHP only:** `php -l` lint of the new content — `php_lint_failed` on a syntax error, file untouched.
3. The previous version is copied to `wp-content/agent-bridge-backups/` (20 kept per file, web access denied).
4. The new file is swapped in atomically.
5. **PHP only:** WordPress is booted in a separate PHP process. If it no longer boots, the change is rolled back and `health_check_failed` is returned with the PHP error.
6. The action is appended to `agent-bridge-backups/audit.log`.

If PHP or `proc_open` is unavailable the bridge refuses PHP writes (`verification_unavailable`) rather than write unverified code.

**Known limit:** a PHP file is live for the duration of one WordPress boot (typically under a second) before the health check completes. A bad file is removed immediately after, but a request in that window can see the fault.

### Error codes
| Code | HTTP | Meaning |
|------|------|---------|
| `file_write_disabled` | 403 | `AGENT_BRIDGE_ALLOW_FILE_WRITE` is not true |
| `file_edit_disabled` | 403 | `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS` is set |
| `path_not_allowed` | 403 | Outside the allowed roots, protected, or a disallowed file type |
| `file_not_found` | 404 | Path does not exist |
| `backup_not_found` | 404 | No such backup |
| `conflict` | 409 | `expected_sha256` did not match |
| `path_in_use` | 409 | Directory is the active theme or an active plugin |
| `file_too_large` | 413 | Over the read / write size limit |
| `php_lint_failed` | 422 | PHP syntax error; nothing was written |
| `health_check_failed` | 422 | WordPress failed to boot; change rolled back |
| `verification_unavailable` | 503 | PHP binary / `proc_open` unavailable; PHP write refused |

---

## Apps

Deploy a built front-end (React, Vue, plain static) onto the site and serve it at a mount path. **Disabled unless `AGENT_BRIDGE_ALLOW_FILE_WRITE` is true**; also refused when `DISALLOW_FILE_MODS` is set. All routes require `manage_options`.

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/apps` | List apps: mount, URL, live release |
| `GET` | `/apps/{slug}` | One app plus its stored releases (newest first) |
| `POST` | `/apps/deploy` | Upload a zip as a new release and make it live |
| `POST` | `/apps/{slug}/rollback` | Make an earlier release live (previous one unless `release_id` given) |
| `DELETE` | `/apps/{slug}` | Stop serving, then delete the app and all releases |

### POST /apps/deploy — body schema
```json
{
  "slug": "dashboard",
  "mount": "/app",
  "archive_base64": "<base64 of a zip of the build output>",
  "spa": true,
  "allow_root": false
}
```
- `slug`: lowercase letters, digits, `-` (max 41 chars). Redeploying the same slug creates a new release.
- `mount`: URL path to serve at (`/app`, `/apps/dashboard`). `/` replaces the whole front end and requires `allow_root: true`.
- `spa`: serve `index.html` for unknown extension-less paths (client-side routing). Unknown paths **with** a file extension return 404 (on a root mount they fall through to WordPress).
- Archive limit 20 MB (also bounded by PHP `post_max_size`); expands to at most 50 MB, 5000 files, 10 MB per file.
- A single wrapper directory (e.g. `dist/`) is stripped. `index.html` must be at the bundle root.

### Bundle validation (all checked before anything is extracted)
Rejected with `archive_rejected` and nothing served: path traversal (`..`), absolute or backslash paths, symlinks, hidden files (`.env`, `.git/…`), PHP and any extension outside the allowlist, `.htaccess`. `__MACOSX/` and `.DS_Store` are dropped silently. Real bytes written are counted while extracting, so a zip that lies about its size is cut off.

### Serving
Static files only, from the live release, via `template_redirect` (priority 0). `wp-admin`, `wp-json`, `wp-login.php`, `wp-content`, `wp-includes`, `xmlrpc.php` and other WordPress entry points are never intercepted, and an app cannot be mounted on them (`mount_reserved`). Responses carry `X-Content-Type-Options: nosniff`; `index.html` is `no-cache`, files under `assets/` are `immutable`; SVGs are sent with a sandboxing CSP. Dotfiles and `..` segments are never served.

### Releases
Each deploy is an immutable directory under `wp-content/agent-apps/{slug}/releases/`; going live is a single option update, so deploys are atomic and rollback is instant. The 5 newest releases are kept (never the live one). Release ids sort chronologically.

### Error codes
| Code | HTTP | Meaning |
|------|------|---------|
| `mount_reserved` | 422 | Mount is a WordPress path |
| `root_mount_requires_allow_root` | 422 | `/` needs `allow_root: true` |
| `mount_in_use` | 409 | Another app owns this mount |
| `archive_invalid` | 422 | Not a valid zip |
| `archive_rejected` | 422 | Contains disallowed entries (listed in the message) |
| `archive_too_large` | 413 | Over a size or file-count limit |
| `app_index_missing` | 422 | No `index.html` at the bundle root |
| `app_not_found` | 404 | No such app |
| `release_not_found` | 404 | No such release |
| `no_previous_release` | 409 | Nothing earlier to roll back to |

---

## Headless

Turn WordPress into a headless CMS for a separate front end (a React app deployed with `/apps`, or any other origin). Changes site options only, so `AGENT_BRIDGE_ALLOW_FILE_WRITE` is **not** required. `GET/PATCH /headless` need `manage_options`; every change is written to `agent-bridge-backups/audit.log`.

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/headless` | Current config plus live status (`site_origin`, `rest_url`, `cors_active`, `redirect_active`) |
| `PATCH` | `/headless` | Change settings. Partial; the whole result is validated and nothing is saved unless all of it is valid |
| `GET` | `/headless/preview-link?post_id=` | Preview URL for a post (`edit_posts`; needs `edit_post` on that post) |

### PATCH /headless — body schema
```json
{
  "frontend_url": "https://app.example.com",
  "cors_origins": ["https://app.example.com", "http://localhost:5173"],
  "redirect_frontend": true,
  "redirect_status": 302,
  "preview": true
}
```
Unknown keys are rejected. `frontend_url: null` clears it.

| Setting | Meaning |
|---------|---------|
| `cors_origins` | Exact origins (max 20) that may call the REST API from a browser. No wildcard, `null`, path, query or credentials. **Empty list = WordPress default behaviour** (core reflects any origin). Once set, only listed origins get CORS headers (`Vary: Origin`; methods incl. preflight; `Authorization`, `Content-Type`, `X-Idempotency-Key` allowed; `X-WP-Total`, `X-WP-TotalPages`, `Link` exposed). Credentials/cookies are not allowed cross-origin — use Application Passwords. |
| `frontend_url` | Where the front end lives. http(s), no credentials/query/fragment, optional path. Not this site's own root. |
| `redirect_frontend` | Send public pages (`GET`/`HEAD`) to `frontend_url` + the same path and query, with `redirect_status` (301/302). Requires a `frontend_url` on a **different origin** (a same-origin target can loop). Never redirected: `wp-admin`, `wp-login.php`, REST, `robots.txt`, sitemaps, embeds, trackbacks, and apps deployed with `/apps` (they are served first). |
| `preview` | The editor Preview button opens `<frontend_url>/preview?post_id=…&post_type=…&status=…` (default on). Without a `frontend_url` the normal WordPress preview is used. |

**Lock-out note:** turning the redirect on with a front end that is down makes the public site unreachable. `wp-admin` and the REST API are never redirected, so it can always be switched off with `PATCH /headless {"redirect_frontend": false}`.

### Error codes
| Code | HTTP | Meaning |
|------|------|---------|
| `validation_error` | 422 | Unknown setting, wrong type, invalid origin/URL, empty patch |
| `frontend_url_required` | 409 | Redirect enabled (or `frontend_url` cleared while enabled) without a front end |
| `frontend_same_origin` | 409 | `frontend_url` is this site's own root, or a same-origin target with the redirect on |
| `post_not_found` | 404 | Preview link for a post that does not exist |

---

## Content model

Define custom post types, taxonomies and typed meta fields **as data**. They are registered on every request with REST forced on, so a headless front end can use them immediately at `/wp-json/wp/v2/<rest_base>` (create, read, update, delete through WordPress core REST, with terms and typed `meta`). No PHP file is written, so `AGENT_BRIDGE_ALLOW_FILE_WRITE` is **not** required. All routes need `manage_options`; changes are written to `agent-bridge-backups/audit.log`.

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/content-model` | `{ post_types, taxonomies, meta, warnings }` |
| `POST` | `/content-model/post-types/{slug}` | Create or update a post type |
| `DELETE` | `/content-model/post-types/{slug}` | Remove the definition (posts stay in the database; `orphaned_posts` reports how many) |
| `POST` | `/content-model/taxonomies/{slug}` | Create or update a taxonomy |
| `DELETE` | `/content-model/taxonomies/{slug}` | Remove the definition (term data stays) |
| `POST` | `/content-model/meta/{key}` | Create or update a typed meta field |
| `DELETE` | `/content-model/meta/{key}` | Remove the definition (stored values stay) |

### Post type fields (`label` required)
`label`, `singular_label`, `description` (≤200), `public` (default true), `hierarchical`, `has_archive`, `supports` (from `title editor author thumbnail excerpt trackbacks custom-fields comments revisions page-attributes post-formats`; default `["title","editor"]`), `taxonomies` (slugs), `rest_base` (default: the slug), `menu_icon` (`dashicons-*` only), `menu_position` (5–100), `rewrite_slug`.

### Taxonomy fields (`label` required)
`label`, `singular_label`, `object_types` (post type slugs), `hierarchical`, `rest_base`, `public`.

### Meta fields (`post_types` required)
`post_types` (non-empty list), `type` (`string integer number boolean array`), `items_type` (for arrays: `string integer number boolean`), `single`, `description`, `default` (scalars only, must match `type`). Values are validated by type over REST (a string sent to an integer field is a `400`), and the post types automatically gain `custom-fields` support so the field appears in the `meta` object.

### Rules
- Slugs: lowercase letters, digits, `_`/`-`, start with a letter, max 20. Meta keys: lowercase, max 64, no leading `_` (private in WordPress) and not `wp_`-prefixed.
- Core types (`post`, `page`, `attachment`, `wp_template`, …), core taxonomies (`category`, `post_tag`, …) and core REST bases (`posts`, `users`, `types`, …) cannot be used. A slug or REST base already served by a theme or plugin is refused (`slug_in_use`, `rest_base_in_use`).
- **Strict:** unknown fields and wrong types are rejected (`422`), and nothing is saved unless the whole definition is valid.
- Taxonomy REST fields on posts are named after the taxonomy's **`rest_base`**, not its slug.
- Rewrite rules are flushed automatically on the request after any change.
- Dangling references (a type naming a taxonomy that is not registered, etc.) are reported in `warnings`, not rejected, so definitions can be created in any order.
- Limits: 30 post types, 30 taxonomies, 100 meta fields.
- **Runtime safety does not trust stored data:** core names and `wp_` meta are filtered out when the model is *read*, so even a bad database value cannot redefine WordPress core. A corrupt option is ignored, never fatal.
- Emergency off switch: `define('AGENT_BRIDGE_DISABLE_CONTENT_MODEL', true);` registers nothing.

### Error codes
| Code | HTTP | Meaning |
|------|------|---------|
| `validation_error` | 422 | Bad slug/key, wrong type, unknown field, missing label |
| `slug_reserved` | 422 | WordPress core type/taxonomy |
| `slug_in_use` | 409 | Already registered by something else |
| `rest_base_reserved` | 422 | Core REST base |
| `rest_base_in_use` | 409 | REST base already served for another type/taxonomy |
| `limit_reached` | 409 | Too many definitions |
| `not_found` | 404 | Not in the content model |

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

// File writes under wp-content/{themes,plugins,mu-plugins,agent-apps} (disabled by default)
define('AGENT_BRIDGE_ALLOW_FILE_WRITE', false);

// Stop registering the stored content model (post types, taxonomies, meta) — emergency switch
define('AGENT_BRIDGE_DISABLE_CONTENT_MODEL', false);

// Database API (disabled by default)
define('AGENT_BRIDGE_DB_API', false);
define('AGENT_BRIDGE_DB_ALLOW_DDL', false); // enables DROP TABLE, TRUNCATE, ALTER, CREATE

// Idempotency TTL in seconds (default 24 h)
define('AGENT_BRIDGE_IDEMPOTENCY_TTL', 86400);

// Request logging to wp-content/agent-bridge.log
define('AGENT_BRIDGE_LOG_REQUESTS', true);
```
