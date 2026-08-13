# WPLinker

An API-first URL routing engine for WordPress. Fast 301/302 redirects with wildcard subpath
matching, stored in a custom table and fully manageable over the REST API.

WPLinker skips custom post types entirely: routes live in their own indexed table, and a
front end request resolves through a single `IN ()` lookup on a primary-key-sized index.

## Requirements

- WordPress 5.8+ (for the `Update URI` header the updater relies on)
- PHP 7.4+

## Installation

Download `wplinker.zip` from the [latest release](https://github.com/domkirby/wplinker/releases)
and install it through Plugins → Add New → Upload Plugin, or copy this directory into
`wp-content/plugins/wplinker` and activate it. The routes table is created on activation, and
re-checked on load so a git or FTP deploy that skips the activation hook still upgrades the
schema.

## Updates

WPLinker updates itself from GitHub Releases — it is not on the WordPress.org plugin
directory. Updates appear in Dashboard → Updates and on the Plugins screen exactly like any
other plugin, including the one-click update and the background auto-update toggle.

This works through the `Update URI` header, which has a useful side effect: WordPress.org
will refuse to serve updates for a plugin whose `Update URI` points elsewhere, so nobody can
take over your sites by publishing a plugin with the `wplinker` slug there.

- The latest release is checked at most once every 12 hours and cached. Failures are cached
  for an hour, so a rate-limited or unreachable GitHub never slows down or breaks the admin.
- **Check for updates** in the plugin's row on the Plugins screen forces an immediate check,
  as does the "Check again" button on the Updates screen.
- Only published, non-prerelease releases are offered. Enable prereleases with the
  `wplinker_updater_allow_prereleases` filter.
- Packages are only ever downloaded from GitHub hosts; any other URL in a release is refused.

The GitHub API allows 60 unauthenticated requests per hour per IP, which the caching stays
well inside. On a host with many sites behind one IP, or for a private fork, define a token
in `wp-config.php`:

```php
define( 'WPLINKER_GITHUB_TOKEN', 'ghp_...' );
```

A constant rather than a setting, deliberately: a token in the options table would be
readable through database exports and backups.

### Cutting a release

```bash
# bump both the Version header and WPLINKER_VERSION in wplinker.php
git commit -am "Release 0.2.0"
git tag v0.2.0
git push origin main --tags
```

`.github/workflows/release.yml` then asserts that the tag, the `Version:` header and the
`WPLINKER_VERSION` constant all agree, lints every file, runs the tests, builds a
`wplinker.zip` containing a clean `wplinker/` directory (no `tests/`, no `.github/`), and
attaches it to the release. Sites pick it up within 12 hours, or immediately on a forced
check.

If a release has no `wplinker.zip` asset, the updater falls back to GitHub's auto-generated
zipball and renames the extracted folder so the plugin stays active.

## Concepts

A route has a **source path**, a **destination**, a **match type** and a **status code**.

| Match type | Source | Request | Redirects to |
| --- | --- | --- | --- |
| `exact` | `/promo` | `/promo` | `https://example.com/new-promo` |
| `prefix` | `/docs` (entered as `/docs/*`) | `/docs/api/v1/auth` | `https://new-domain.com/documentation/api/v1/auth` |

Prefix routes append the remaining path segments to the destination. Matching respects
segment boundaries, so `/docs/*` never captures `/docs-archive`.

Resolution order: an exact route always wins over a prefix route, and between prefix routes
the longest (most specific) source wins.

Source paths are normalised on save — leading slash, no trailing slash, query string and
fragment dropped, the site's home path stripped so routes survive a move between a root and
a subdirectory install. Destinations may be absolute `http(s)` URLs or site relative paths
beginning with `/`. The incoming query string is forwarded to the destination.

## Admin UI

**WPLinker** in the admin menu, gated on `manage_options`:

- **All Routes** — a `WP_List_Table` with core pagination, sortable columns, search, a
  per-page screen option and bulk delete.
- **Add Route** — a `form-table` form submitted through `admin-post.php`, nonce protected.

No custom CSS or JavaScript is enqueued; the screens use core classes only and inherit the
user's admin colour scheme.

## REST API

Namespace `custom-routes/v1`. Every endpoint requires `manage_options` and standard
WordPress REST authentication (application passwords, cookies + nonce, or whatever your
auth plugin provides).

| Method | Endpoint | Description |
| --- | --- | --- |
| `GET` | `/wp-json/custom-routes/v1/routes` | List routes, paginated. |
| `GET` | `/wp-json/custom-routes/v1/routes/{id}` | Retrieve one route. |
| `POST` | `/wp-json/custom-routes/v1/routes` | Create a route. |
| `PUT`/`PATCH` | `/wp-json/custom-routes/v1/routes/{id}` | Update a route. |
| `DELETE` | `/wp-json/custom-routes/v1/routes/{id}` | Delete a route. |

Collection parameters: `page`, `per_page` (max 100), `search`, `orderby`, `order`,
`match_type`, `status_code`. Responses carry `X-WP-Total` and `X-WP-TotalPages`.

Write payloads accept either the column names or the short aliases:

| Canonical | Alias |
| --- | --- |
| `source_path` | `source` |
| `destination_url` | `destination` |
| `status_code` | `status` |
| `match_type` | `type` |

```bash
curl -u admin:APPLICATION_PASSWORD \
  -H 'Content-Type: application/json' \
  -d '{"source":"/docs/*","destination":"https://new-domain.com/documentation","status":301}' \
  https://example.com/wp-json/custom-routes/v1/routes
```

A trailing `/*` on the source implies `match_type: prefix`, so `type` can be omitted.

Errors come back as standard REST errors: `400` for validation failures, `404` for a missing
route, `409` for a duplicate source path + match type pair.

## Safety rules

- **Redirect loops.** A destination on the same host that resolves back into its own source
  is rejected — exactly equal for exact routes, anywhere under the source for prefix routes.
  External destinations are never treated as loops, even when the paths coincide.
- **Reserved paths.** `/wp-admin`, `/wp-login.php`, `/wp-json` (and the site's actual REST
  prefix), `/wp-content`, `/wp-includes`, `/xmlrpc.php` and friends cannot be used as a
  source, either directly or by a prefix route that would sit above them. A catch-all `/*`
  is refused for the same reason. Extend the list with `wplinker_reserved_paths`.
- **Request scope.** The router ignores admin, AJAX, cron, WP-CLI and REST requests, and
  anything that is not a `GET` or `HEAD`.
- **Cache headers.** Permanent redirects are sent with `Cache-Control: public, max-age=3600`
  (filterable) so CDNs and browsers can keep them; temporary redirects are sent with
  `no-store, no-cache, must-revalidate` so they are always revalidated.

## Performance notes

- The lookup builds the candidate source paths from the request itself (`/a/b/c` →
  `/a/b/c`, `/a/b`, `/a`, `/`), so one prepared `IN ()` query against the `source_path`
  index answers both exact and prefix matching. No `LIKE` scans.
- Results — including "no route here" — are memoised in the object cache under an
  invalidation stamp that every write bumps. With a persistent object cache (Redis,
  Memcached) a hot path costs zero queries.
- Sites with an empty routing table skip the lookup query entirely.
- The hit counter is written *after* the response is flushed (`fastcgi_finish_request()`
  where available), so analytics never sit in the visitor's critical path.

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `wplinker_should_handle_request` | filter | Skip route resolution for a request. |
| `wplinker_redirect_destination` | filter | Rewrite or cancel a resolved destination. |
| `wplinker_forward_query_string` | filter | Stop forwarding the incoming query string. |
| `wplinker_count_clicks` | filter | Disable hit counting. |
| `wplinker_permanent_redirect_max_age` | filter | `max-age` for 301/308 responses. |
| `wplinker_allowed_status_codes` | filter | Allow 307/308 in addition to 301/302. |
| `wplinker_reserved_paths` | filter | Extend the blocked source path list. |
| `wplinker_updater_allow_prereleases` | filter | Offer prereleases as updates. |
| `wplinker_updater_release_data` | filter | Override the parsed release; useful for testing the upgrade path without publishing one. |
| `wplinker_updater_tested_up_to` | filter | WordPress version reported as tested. |
| `wplinker_rest_capability` | filter | Capability required by the REST API. |
| `wplinker_admin_capability` | filter | Capability required by the admin screens. |
| `wplinker_pre_redirect` | action | Fires immediately before a redirect is sent. |
| `wplinker_routes_changed` | action | Fires after any write; use it to purge an edge cache. |

## Schema

`{$wpdb->prefix}custom_routes`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `bigint unsigned` | Primary key. |
| `source_path` | `varchar(255)` | Normalised, no wildcard suffix. Unique with `match_type`, indexed on the first 191 characters. |
| `destination_url` | `text` | Absolute URL or site relative path. |
| `status_code` | `smallint unsigned` | 301 or 302 by default. |
| `match_type` | `varchar(20)` | `exact` or `prefix`. |
| `clicks` | `bigint unsigned` | Hit counter. |
| `created_at` | `datetime` | GMT. |
| `updated_at` | `datetime` | GMT. |

Deleting the plugin from the admin drops the table and its options.

## Tests

The pure routing logic (normalisation, prefix matching, loop detection, subpath resolution)
runs without WordPress:

```bash
php tests/smoke-test.php
```

## Layout

```
wplinker.php                              bootstrap
includes/class-wplinker-install.php       schema install and upgrade
includes/class-wplinker-routes.php        data access and caching
includes/class-wplinker-validator.php     normalisation and validation rules
includes/class-wplinker-router.php        request interceptor
includes/class-wplinker-rest-controller.php  custom-routes/v1
includes/class-wplinker-updater.php       updates from GitHub Releases
admin/class-wplinker-admin.php            menus, forms, notices
admin/class-wplinker-list-table.php       WP_List_Table implementation
uninstall.php                             data removal
tests/smoke-test.php                      dependency free logic tests
.github/workflows/release.yml             tag -> verified, built, published release
```

## Not in this pass

Import/export, per-route enable/disable, 404 logging and hit timestamps, and a route
grouping/tagging model. The table and validator are structured so these can be added
without a migration of existing rows.
