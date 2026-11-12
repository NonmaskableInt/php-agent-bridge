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

Typed, grouped access to site options. Values are **validated**, and a PATCH is **all-or-nothing**: every key is checked (and every problem reported) before anything is written.

| Method | Route | Capability | Description |
|--------|-------|------------|-------------|
| `GET` | `/options/schema` | `manage_options` | Groups, keys with types and limits, what is enabled, what can never be set |
| `GET` | `/options` | `manage_options` | Get options by key (typed: integers, booleans) |
| `PATCH` | `/options` | `manage_options` | Update options |

### Groups
Enable groups in `wp-config.php` with `define('AGENT_BRIDGE_OPTION_GROUPS', ['general','reading', ...]);` (default: the first four).

| Group | Default | Keys |
|-------|---------|------|
| `general` | on | `blogname` (1–200), `blogdescription` (0–300), `timezone_string` (IANA id), `date_format`, `time_format`, `start_of_week` (0–6), `posts_per_page` (1–100), `posts_per_rss` (1–100) |
| `reading` | on | `show_on_front` (`posts`\|`page`), `page_on_front`, `page_for_posts` (0 or a **published page**) |
| `discussion` | on | `default_comment_status`, `default_ping_status` (`open`\|`closed`), `require_name_email`, `comment_registration`, `close_comments_for_old_posts`, `thread_comments` (booleans), `close_comments_days_old` (1–3650), `comments_per_page` (1–200) |
| `media` | on | `thumbnail_size_w/h`, `medium_size_w/h`, `large_size_w/h` (0–10000) |
| `permalinks` | **off** | `permalink_structure`, `category_base`, `tag_base` |
| `site_url` | **off** | `siteurl`, `home` |

### Never settable
`default_role` and `users_can_register` (together they create admin accounts), `admin_email` (receives password resets: account takeover), `blog_public` (silent de-indexing), `active_plugins` (use the plugins API), `template` / `stylesheet` (use the themes API). These are refused with an explanation, whatever groups are enabled.

### Rules worth knowing
- **Cross-field:** `show_on_front=page` needs a valid `page_on_front` (set it in the same request); `page_for_posts` must differ from `page_on_front`.
- **`permalink_structure`** must start with `/`, use only known tags (`%year% %monthnum% %day% %hour% %minute% %second% %post_id% %postname% %category% %author%`) and contain `%postname%` or `%post_id%`. **Plain permalinks (empty) are refused:** they remove `/wp-json/`, which would cut the agent off from the site. Rewrite rules are flushed automatically.
- **`siteurl` / `home`** (group `site_url`) must be an http(s) URL without credentials, query or fragment. A wrong value takes the whole site offline, which is why the group is off by default. *(Earlier versions exposed these two keys by default and wrote them unvalidated.)*
- Updates return the new, typed values.

### Legacy allowlist
`AGENT_BRIDGE_ALLOWED_OPTIONS` still works and **replaces** the allowlist (groups are then ignored). It is the site owner's explicit decision, so it may list any key, including ones above. Keys the bridge knows are still validated; other keys are passed through unvalidated, as before.

### PATCH /options — body schema
```json
{ "blogname": "New Site Title", "posts_per_page": 10, "require_name_email": true }
```

### Error codes
| Code | HTTP | Meaning |
|------|------|---------|
| `option_not_allowed` | 403 | Key is never settable, in a disabled group (the message says which constant enables it), or unknown |
| `validation_error` | 422 | One or more values invalid; the message and `data.errors` name every key and reason. Nothing was saved |

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

> **Warning: open mode bypasses every guardrail in this document.** In open mode `wp eval`, `wp eval-file`, `wp shell`, `wp config set`, `wp plugin install <url>`, `wp theme install <url>`, `wp db query` (and any plugin's own subcommands) all run. That is arbitrary PHP and arbitrary database access, **without** the path jail, extension allowlist, PHP lint, backups, boot-test and rollback that `/files` applies, without the `AGENT_BRIDGE_ALLOW_FILE_WRITE` gate, and the code that `wp eval`, `eval-file`, `shell` and `db query` run never consults `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS`, so those constants do not stop it. Only the five commands in the denylist below are blocked. Enabling open mode means trusting the agent with the same power as shell access to the site: do not turn it on if the safety of the PHP backend matters. The default allowlist mode is read-mostly and is what you want.

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

Reads need `manage_options`. Writes additionally need `edit_themes` (themes), `edit_plugins` (plugins, mu-plugins), or `manage_options` **and `unfiltered_html`** (`agent-apps`, because app scripts run with the site's own origin; WordPress withholds `unfiltered_html` from multisite site admins and when `DISALLOW_UNFILTERED_HTML` is set). Writes, deletes, restores and app deploys are refused when `DISALLOW_FILE_MODS` **or** `DISALLOW_FILE_EDIT` is set — there is no exemption for `agent-apps`, because JavaScript is code.

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
1. Path jail, root and extension allowlist (`php js mjs css json html htm txt md xml svg map twig png jpg jpeg gif webp ico woff woff2 ttf webmanifest`). `.htaccess`, `.user.ini`, `php.ini`, `.htpasswd` and `wp-config.php` are never writable. PHP is not allowed inside `agent-apps`. A script extension (`php php3-8 phtml pht phps phar`) in **any** position of the name is refused, so `avatar.php.png` cannot be used to slip past the allowlist on servers that execute multi-extension names.
2. **PHP only:** `php -l` lint of the new content — `php_lint_failed` on a syntax error, file untouched.
3. The previous version is copied to the backup directory (20 kept per file) — see *Backup directory* below.
4. The new file is swapped in atomically.
5. **PHP only:** WordPress is booted in a separate PHP process. If it no longer boots, the change is rolled back and `health_check_failed` is returned with the PHP error.
6. The action is appended to `audit.log` in the backup directory.

If PHP or `proc_open` is unavailable the bridge refuses PHP writes (`verification_unavailable`) rather than write unverified code.

**Known limit:** a PHP file is live for the duration of one WordPress boot (typically under a second) before the health check completes. A bad file is removed immediately after, but a request in that window can see the fault.

### Backup directory
Backups hold full source code, so they are kept out of reach of the web server:

- Set `AGENT_BRIDGE_BACKUP_DIR` to a directory **outside the web root** (recommended).
- Otherwise the bridge creates `wp-content/agent-bridge-<32 random hex>` once and remembers it. The name is unguessable, it contains `.htaccess` (Apache), `web.config` (IIS) and `index.php` (no listings), and these are re-created if they go missing. Protection therefore does not depend on the web server honouring `.htaccess` (it does not on nginx).
- It holds `audit.log`, the per-file backups and, when `AGENT_BRIDGE_LOG_REQUESTS` is on, `requests.log`.
- A `wp-content/agent-bridge-backups/` or `wp-content/agent-bridge.log` left by an earlier version is migrated into it automatically, so nothing remains at a guessable path.

### Error codes
| Code | HTTP | Meaning |
|------|------|---------|
| `file_write_disabled` | 403 | `AGENT_BRIDGE_ALLOW_FILE_WRITE` is not true |
| `file_edit_disabled` | 403 | `DISALLOW_FILE_EDIT` or `DISALLOW_FILE_MODS` is set |
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

Deploy a built front-end (React, Vue, plain static) onto the site and serve it at a mount path. **Disabled unless `AGENT_BRIDGE_ALLOW_FILE_WRITE` is true**; also refused when `DISALLOW_FILE_MODS` or `DISALLOW_FILE_EDIT` is set. Deploy, rollback and delete need `manage_options` **and `unfiltered_html`** (app scripts run on the site's own origin). Reads need `manage_options`.

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

Turn WordPress into a headless CMS for a separate front end (a React app deployed with `/apps`, or any other origin). Changes site options only, so `AGENT_BRIDGE_ALLOW_FILE_WRITE` is **not** required. `GET/PATCH /headless` need `manage_options`; every change is written to `audit.log` in the backup directory.

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

Define custom post types, taxonomies and typed meta fields **as data**. They are registered on every request with REST forced on, so a headless front end can use them immediately at `/wp-json/wp/v2/<rest_base>` (create, read, update, delete through WordPress core REST, with terms and typed `meta`). No PHP file is written, so `AGENT_BRIDGE_ALLOW_FILE_WRITE` is **not** required. All routes need `manage_options`; changes are written to `audit.log` in the backup directory.

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

## Templates

Find out **what renders a page**, and manage block-theme template overrides.

> **The trap this solves.** In a block theme, anything saved in the Site Editor is stored in the *database* and **overrides the theme's template file**. Editing `templates/single.html` can then appear to do nothing. `template_list` / `template_resolve` show `source: "custom"` and `masks_theme_file: true` when that is happening, and `template_get` returns the hidden file content (`theme_content`).

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/templates?type=template\|part` | Block themes: templates (or parts) with their source. Classic themes: PHP template `files` |
| `GET` | `/templates/item?id=&type=` | One template: block markup, plus `theme_content` when an override hides a theme file |
| `POST` | `/templates/item` | Create or update a template/part in the database (an override when the theme ships one) |
| `DELETE` | `/templates/item?id=&type=` | Remove an override (back to the theme file) or delete a custom-only template. Backed up first |
| `POST` | `/templates/resolve` | `{ "url": "/?p=12" }` — which template WordPress renders for that URL |
| `GET` | `/templates/backups` | Saved copies of removed overrides, newest first |
| `POST` | `/templates/restore` | `{ "name": "<backup>" }` — bring one back |

Reads need `manage_options`; changes also need `edit_theme_options`. Writes go through WordPress's own REST controllers, so sanitising (kses for users without `unfiltered_html`) and term assignment are core's, exactly as in the Site Editor. No files are written, so `AGENT_BRIDGE_ALLOW_FILE_WRITE` is **not** required. Changes are audit-logged.

### Template object (block themes)
`id` (`theme//slug`), `slug`, `type` (`template`\|`part`), `title`, `description`, `source` (`theme`\|`custom`), `origin`, `is_custom` (no theme file exists), `has_theme_file`, **`masks_theme_file`** (a database override hides the theme file), `wp_id` (the post id of the override, else `null`), `modified`, and `area` for parts.

### POST /templates/item — body
```json
{ "id": "single", "type": "template", "content": "<!-- wp:group -->…", "title": "Single post", "description": "…" }
```
`id` is a slug or `theme//slug` of the **active theme only**. A slug the theme does not ship creates a custom template. Parts accept `area` (`header`, `footer`, `uncategorized`). `content` (block markup) is limited to 500000 bytes; `title` to 100, `description` to 300. Unknown fields are rejected (`422`).

### POST /templates/resolve
Runs WordPress in a separate PHP process (like the file health check), so it cannot affect the request. It reports what WordPress itself would render: `{ is_block, type, hierarchy, template_id, slug, source, masks_theme_file, file }`. `type` is the hierarchy that **matched** (a category with no `category.html` reports `archive`). Block themes return `template_id`; classic themes return `file`. The site's own redirects (canonical URLs, the headless redirect, deployed apps) are deliberately not followed. `url` is a path/query or a full URL on this site; other hosts, scheme-relative URLs, control characters and URLs over 2000 characters are refused.

### Classic themes
There are no block templates. `GET /templates` returns `files` — each with `file`, `kind` (`template`, `part`, `page_template` with its `name`, `functions`), the owning `theme` (child or parent) and a `path` for the `/files` API. `GET/POST/DELETE /templates/item` answer `not_block_theme`; edit the PHP with `/files` (linted, backed up, health-checked).

### Error codes
| Code | HTTP | Meaning |
|------|------|---------|
| `not_block_theme` | 409 | The active theme is a classic theme |
| `template_not_found` | 404 | No such template/part in the active theme |
| `no_override` | 409 | Reset requested but the template comes from the theme file |
| `wrong_theme` | 422 | The id names a theme other than the active one |
| `validation_error` | 422 | Bad id/type/area/field, or content too large |
| `backup_not_found` | 404 | No such backup |
| `resolve_failed` | 500 | WordPress could not resolve that URL (detail in the message) |
| `insufficient_capability` | 403 | Writing needs `edit_theme_options` |

---

## Theme tools (validation and scaffolding)

Checks so an agent can verify what it wrote **before trusting or activating it**, and one-step theme scaffolding (below). WordPress's own parsers are forgiving: broken block markup renders as something odd, an invalid `theme.json` is silently ignored, a missing template part shows nothing. These report it. The validators need `manage_options` and write nothing; scaffolding writes files and is gated like `/files`.

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/theme-tools/validate?stylesheet=` | Validate an installed theme (default: the active one) |
| `POST` | `/theme-tools/validate-markup` | `{ "content": "<!-- wp:… -->" }` — check block markup without saving |
| `POST` | `/theme-tools/scaffold` | Create a block, classic or child theme (not activated) |

`POST /templates/item` also returns the markup check as `validation` (the template is saved either way, like the Site Editor).

### Result
```json
{ "stylesheet": "my-theme", "theme_type": "block", "valid": false,
  "errors":   [ { "code": "missing_template_part", "level": "error", "message": "…", "file": "templates/single.html", "line": 1 } ],
  "warnings": [ … ], "checked": { "files": 14 } }
```
`valid` is true when there are no errors; warnings never fail validation.

### Block markup checks
| Code | Level | Meaning |
|------|-------|---------|
| `unclosed_block`, `unexpected_closer`, `mismatched_closer` | error | Delimiters do not balance |
| `invalid_attributes` | error | The `{…}` attributes are not a valid JSON object |
| `unregistered_block` | warning | Block name not registered (misspelt, or its plugin is inactive) |
| `freeform_content` | warning | HTML outside any block |

### Theme checks
| Code | Level | Meaning |
|------|-------|---------|
| `missing_theme_header` | error | `style.css` has no `Theme Name:` |
| `missing_index_template` | error | Block theme: `templates/index.html`; classic: `index.php` |
| `missing_parent_theme` / `parent_is_child` | error | A child theme's parent is absent, or is itself a child |
| `missing_template_part` | error | A template references `parts/<slug>.html` that does not exist |
| `invalid_theme_json` | error | `theme.json` is not valid JSON (WordPress ignores the whole file) |
| `php_lint_failed` | error | A theme PHP file has a syntax error |
| `unknown_theme_json_key`, `unsupported_theme_json_version` | warning | Ignored key / version other than 1–3 |
| `ignored_theme_json_property` | warning | A `settings`/`styles` property WordPress's sanitiser drops (lists are not compared) |
| `pattern_missing_header` | warning | A `patterns/*.php` file lacks `Title:` / `Slug:` |
| `lint_unavailable` | warning | PHP could not be run to check syntax |

A theme counts as a block theme if it has `theme.json` or `templates/*.html`, even when `templates/index.html` is missing (which is then reported). At most 200 files are checked per kind.


### POST /theme-tools/scaffold
```json
{ "type": "block", "slug": "my-theme", "name": "My Theme", "description": "…", "author": "…" }
{ "type": "child", "slug": "my-child", "name": "My Child", "parent": "twentytwentyfive" }
```
Creates a working theme in one step. **Never activates it** (use `/themes/{stylesheet}/activate`) and **never overwrites** an existing theme (`theme_exists`).

| `type` | Creates |
|--------|---------|
| `block` | `style.css`, `theme.json` (palette, typography, layout, header/footer `templateParts` areas), `functions.php`, `templates/{index,single,page,404}.html`, `parts/{header,footer}.html` |
| `classic` | `style.css`, `functions.php`, `header.php`, `footer.php`, `index.php`, `single.php`, `page.php`, `archive.php`, `search.php`, `404.php` |
| `child` | `style.css` (with `Template:`) and `functions.php`. A block parent's templates and `theme.json` are inherited; a classic parent's stylesheet is enqueued. The parent must be installed and not itself a child |

- **All-or-nothing.** Files are written to a hidden staging directory (`.agent-scaffold-…`, ignored by WordPress), generated PHP is linted, and the theme must pass the validator above before it is moved into place. A failure leaves nothing behind.
- `slug`: 2–41 characters, lowercase letters, digits and dashes, starting with a letter. `name` (1–60), `description` (≤200) and `author` (≤60) must be a single line with no control characters, `*/` or `<?`, because they are written into the `style.css` header comment.
- Unknown fields are rejected. Needs `AGENT_BRIDGE_ALLOW_FILE_WRITE`, the `edit_themes` capability, and is refused when `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS` is set.
- Returns `{ stylesheet, type, path, files, validation, activated: false }`. Customise afterwards with `/files` (PHP, `theme.json`), `/templates` and `/global-styles`.

| Code | HTTP | Meaning |
|------|------|---------|
| `theme_exists` | 409 | A theme with that slug already exists |
| `parent_not_found` / `parent_is_child` | 422 | The parent is missing, or is itself a child |
| `scaffold_failed` | 500 | Generation, lint or validation failed; nothing was created |
| `file_write_disabled` / `file_edit_disabled` | 403 | The file-write opt-in is off, or a `DISALLOW_*` constant is set |

---

## Global styles

The Site Editor's **user-level `theme.json` layer** (the "Styles" panel: colours, typography, spacing, palettes). It lives in the database as a `wp_global_styles` post and **overrides the theme's own `theme.json`**, so editing `theme.json` can appear to do nothing. Needs a theme with `theme.json` (`no_theme_json` otherwise). Reads need `manage_options`; changes also need `edit_theme_options`. No files are written. Changes are audit-logged.

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/global-styles?include=theme` | The user layer (`user.settings`, `user.styles`, `has_customizations`, `post_id`); `include=theme` adds the `theme.json` defaults it overrides |
| `POST` | `/global-styles` | Change styles. Merges by default |
| `DELETE` | `/global-styles` | Remove all customisations (backed up first) |
| `GET` | `/global-styles/backups` | Saved copies of removed customisations |
| `POST` | `/global-styles/restore` | `{ "name": "<backup>" }` — bring one back (same theme only) |

### POST /global-styles — body
```json
{ "styles": { "color": { "background": "#fff" }, "typography": { "fontSize": "18px" } },
  "settings": { "color": { "palette": { "custom": [ { "slug": "brand", "name": "Brand", "color": "#12ab34" } ] } } },
  "merge": true }
```
- `settings` / `styles` use `theme.json` structure. At least one is required; both must be objects; unknown fields are rejected (`422`); payload limit 200000 bytes.
- **Merge** (default): objects merge key by key; **lists are replaced whole** (a palette, font families). `merge: false` replaces the given section outright.
- **Only valid properties are saved.** WordPress silently ignores unknown properties; the bridge drops them *before* saving and returns their paths in `ignored` (e.g. `styles.color.notAProperty`), so the stored data matches what actually applies.
- Palettes entered under `settings.color.palette.custom` become CSS variables (`--wp--preset--color--<slug>`) on the site.

### Error codes
| Code | HTTP | Meaning |
|------|------|---------|
| `no_theme_json` | 409 | The active theme has no `theme.json` |
| `no_customizations` | 409 | Reset requested but there is nothing to reset |
| `validation_error` | 422 | Bad field/type, nothing to change, oversized, or a malformed backup name |
| `wrong_theme` | 422 | Backup belongs to a different theme |
| `backup_not_found` | 404 | No such backup |

---

## Status

What this bridge is and what it is allowed to do on this site: for diagnostics and for agents deciding what to attempt. Needs `manage_options`. Reports **that** things are enabled, never secrets, credentials or server paths.

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/status` | Bridge version and route count; WordPress, PHP and theme details; the enabled features; the calling user's capabilities |

```json
{
  "bridge":    { "version": "1.0.0", "namespace": "agent/v1", "routes": 62 },
  "wordpress": { "version": "6.9.4", "multisite": false },
  "php":       { "version": "8.3.31" },
  "site":      { "url": "https://example.com", "theme": "twentytwentyfive", "theme_type": "block" },
  "features": {
    "file_write": true,            "file_edit_blocked": false,
    "theme_install": false,        "plugin_install": false,
    "cli": { "enabled": true, "mode": "allowlist" },
    "db_api": false,               "content_model": true,
    "option_groups": ["general", "reading", "discussion", "media"],
    "legacy_option_list": false,   "backup_dir": "default",
    "php_verification": true
  },
  "user": { "login": "agent", "roles": ["administrator"],
            "can": { "manage_options": true, "edit_themes": true, "edit_plugins": true, "edit_theme_options": true, "unfiltered_html": true } }
}
```

| Field | Meaning |
|-------|---------|
| `file_write` | `AGENT_BRIDGE_ALLOW_FILE_WRITE` is true |
| `file_edit_blocked` | `DISALLOW_FILE_EDIT` or `DISALLOW_FILE_MODS` is set, so writes are refused even when `file_write` is true |
| `cli.mode` | `allowlist` (default) or `open` (`AGENT_BRIDGE_CLI_OPEN`: bypasses the file guardrails) |
| `backup_dir` | `custom` when `AGENT_BRIDGE_BACKUP_DIR` is set, else `default`. The path itself is never reported |
| `php_verification` | A `php` binary and `proc_open` are available. When `false`, PHP writes and template resolving are refused (the bridge will not save PHP it cannot lint and boot-test); shared hosts sometimes lack them |

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

// Option groups exposed via GET/PATCH /options (default: general, reading, discussion, media).
// Also available: 'permalinks', 'site_url'. See the Site Options section.
define('AGENT_BRIDGE_OPTION_GROUPS', ['general', 'reading', 'discussion', 'media']);

// Legacy: an explicit list of option keys. Replaces the groups above when defined.
// define('AGENT_BRIDGE_ALLOWED_OPTIONS', ['blogname', 'blogdescription']);

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

// Where backups, audit.log and requests.log live. Point this OUTSIDE the web root.
// Default: wp-content/agent-bridge-<random> (unguessable, with deny rules).
define('AGENT_BRIDGE_BACKUP_DIR', '/var/lib/agent-bridge');

// File writes under wp-content/{themes,plugins,mu-plugins,agent-apps} (disabled by default)
define('AGENT_BRIDGE_ALLOW_FILE_WRITE', false);

// Stop registering the stored content model (post types, taxonomies, meta) — emergency switch
define('AGENT_BRIDGE_DISABLE_CONTENT_MODEL', false);

// Database API (disabled by default)
define('AGENT_BRIDGE_DB_API', false);
define('AGENT_BRIDGE_DB_ALLOW_DDL', false); // enables DROP TABLE, TRUNCATE, ALTER, CREATE

// Idempotency TTL in seconds (default 24 h)
define('AGENT_BRIDGE_IDEMPOTENCY_TTL', 86400);

// Request logging to requests.log in the backup directory (not web-served)
define('AGENT_BRIDGE_LOG_REQUESTS', true);
```
