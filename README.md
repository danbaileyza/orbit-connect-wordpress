# Orbit Connect (WordPress plugin)

Lets Orbit check a WordPress site's health and apply approved SEO fixes to it: SEO title, meta description, canonical, noindex, image alt text and redirects. Every change is logged on the site with its previous value, so Orbit can undo it.

## Install on a site

1. Zip the plugin folder: `cd integrations/wordpress && zip -r orbit-connect.zip orbit-connect`
2. WordPress → Plugins → Add New → Upload Plugin → choose `orbit-connect.zip` → Activate.
3. Users → Profile (an administrator) → Application Passwords → name it "Orbit" → Add. Copy the password once.
4. Orbit → Connectors → WordPress → Add site: the site address, that username, and the application password.

Application passwords need HTTPS (or a site marked `WP_ENVIRONMENT_TYPE` `local`). Revoke the password in the same screen to cut Orbit off.

## Where values go

| Field | Yoast | Rank Math | Neither |
|---|---|---|---|
| Title | `_yoast_wpseo_title` | `rank_math_title` | `_orbit_seo_title`, via `pre_get_document_title` |
| Description | `_yoast_wpseo_metadesc` | `rank_math_description` | `_orbit_seo_description`, printed in `wp_head` |
| Canonical | `_yoast_wpseo_canonical` | `rank_math_canonical_url` | `_orbit_seo_canonical`, via `get_canonical_url` |
| Noindex | `_yoast_wpseo_meta-robots-noindex` | `rank_math_robots` | `_orbit_seo_noindex`, via `wp_robots` |
| Image alt | `_wp_attachment_image_alt` on the media item; an empty `alt` on images in page content, page-builder images included, is filled from it when the page is shown (`wp_content_img_tag`) | same | same |
| Redirects | the plugin's own list (`orbit_connect_redirects`), served on `template_redirect` | same | same |

Notes from testing:
- Yoast rebuilds its cached output (indexables) itself when its fields change. Yoast leaves the canonical out of noindex pages.
- Rank Math prints nothing until its setup wizard is finished or skipped; `status` reports this as `seo_plugin_problem`.
- After a change the plugin clears WordPress's post cache and asks WP Rocket, W3 Total Cache, WP Super Cache and LiteSpeed Cache to drop the page. Other caches (Cloudflare, host caches) may still serve the old page for a while.

## API (`/wp-json/orbit/v1`, administrator only)

- `GET status` — versions, active SEO plugin and any problem with it
- `GET health` — WordPress's Site Health results, plugins and themes with updates, server, database (autoload, transients, revisions), scheduled tasks and security settings (1.2). Read only; takes a few seconds
- `GET page?url=…` / `GET page/{id}` — the page's SEO fields and images
- `POST media/lookup` — `{srcs: [...]}`: which image addresses (as a page shows them) are in the media library, with their alt text (1.1)
- `POST page/{id}` — `{title?, description?, canonical?, noindex?, note?}`; empty text or `null` clears the page's own value
- `POST image` — `{id | src, alt}`; `src` can be a resized copy (`-300x200.jpg`)
- `GET redirects`, `POST redirects` — `{from, to, code}`
- `GET changes`, `POST changes/{id}/undo` — undo refuses (`409 orbit_changed_since`) if the value was edited after Orbit set it

## Test

`tests/smoke.sh` runs every endpoint against a **test** site and checks the public page shows each change and is back to normal after undo. It needs `WP_URL`, `WP_USER`, `WP_APP_PASSWORD`, and optionally `WP_CLI` for the undo-after-manual-edit check. Never point it at a client site.
