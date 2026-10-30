#!/usr/bin/env bash
#
# End-to-end check of Orbit Connect against a TEST WordPress site: sets each
# field through the API, fetches the public page to see it took effect, undoes
# it and checks the page is back. Never point this at a client site.
#
# Usage: WP_URL=… WP_USER=… WP_APP_PASSWORD=… PAGE_PATH=/services/ ./smoke.sh
#   Optional WP_CLI="wp --path=…" also checks undo refuses after a manual edit.
# The password is read from the environment and never printed.

set -uo pipefail
: "${WP_URL:?}" "${WP_USER:?}" "${WP_APP_PASSWORD:?}"
PAGE_PATH="${PAGE_PATH:-/services/}"
API="$WP_URL/wp-json/orbit/v1"
pass=0; fail=0

ok()   { pass=$((pass + 1)); printf '  \033[32m✓\033[0m %s\n' "$*"; }
bad()  { fail=$((fail + 1)); printf '  \033[31m✗\033[0m %s\n' "$*"; }
check(){ if eval "$2"; then ok "$1"; else bad "$1"; fi; }
api()  { local m=$1 p=$2; shift 2; curl -s -X "$m" -u "$WP_USER:$WP_APP_PASSWORD" -H 'Content-Type: application/json' "$API$p" "$@"; }
html() { curl -s "$WP_URL$PAGE_PATH?orbit-check=$RANDOM$RANDOM"; }

status=$(api GET /status)
plugin=$(jq -r .seo_plugin <<<"$status")
echo "SEO plugin: $plugin ($(jq -r .seo_plugin_version <<<"$status")), WordPress $(jq -r .wordpress_version <<<"$status")"

check "anonymous request is refused" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$API/status")" = 401 ]'
check "wrong password is refused" '[ "$(curl -s -o /dev/null -w "%{http_code}" -u "$WP_USER:wrong wrong wrong" "$API/status")" = 401 ]'

page=$(api GET "/page?url=$WP_URL$PAGE_PATH")
id=$(jq -r .id <<<"$page")
check "page resolves from its URL (id $id)" '[ "$id" -gt 0 ] 2>/dev/null'
before_title=$(html | sed -n 's:.*<title>\(.*\)</title>.*:\1:p' | head -1)

stamp=$(date +%s)
title="Orbit test title $stamp"
desc="Orbit test description $stamp for the services page."
canon="$WP_URL/orbit-canonical-$stamp/"
res=$(api POST "/page/$id" -d "{\"title\":\"$title\",\"description\":\"$desc\",\"canonical\":\"$canon\",\"note\":\"smoke test\"}")
ids=$(jq -r '.changes[].id' <<<"$res" | tr '\n' ' ')
check "three changes recorded ($ids)" '[ "$(jq ".changes | length" <<<"$res")" = 3 ]'
check "API reads back the new title" '[ "$(jq -r .page.seo.title <<<"$res")" = "$title" ]'

page_html=$(html)
check "page shows the new <title>" 'grep -q "<title>$title" <<<"$page_html"'
check "page shows the new description" 'grep -Eq "name=\"description\" content=\"$desc\"" <<<"$page_html"'
check "page shows the new canonical" 'grep -Eq "rel=\"canonical\" href=\"$canon\"" <<<"$page_html"'

# Separately: Yoast leaves the canonical out of noindex pages.
res=$(api POST "/page/$id" -d '{"noindex":true}')
ids="$ids $(jq -r '.changes[0].id' <<<"$res")"
check "page is noindex" 'html | grep -Eiq "name=.robots.[^>]*noindex"'

again=$(api POST "/page/$id" -d "{\"title\":\"$title\"}")
check "setting the same value records nothing" '[ "$(jq ".changes | length" <<<"$again")" = 0 ]'

# Image alt by the resized URL the crawler saw.
src=$(jq -r '[.images[] | select(.in_content)][0].src // empty' <<<"$page")
if [ -n "$src" ]; then
    alt="Orbit alt $stamp"
    img=$(api POST /image -d "{\"src\":\"$src\",\"alt\":\"$alt\"}")
    alt_change=$(jq -r '.changes[0].id // empty' <<<"$img")
    check "alt set from the resized image URL (media $(jq -r .image.id <<<"$img"))" '[ -n "$alt_change" ]'
    check "page shows the alt on the image already in the content" 'html | grep -q "alt=\"$alt\""'
fi

# Redirect.
from="/orbit-old-$stamp"
red=$(api POST /redirects -d "{\"from\":\"$from\",\"to\":\"$WP_URL$PAGE_PATH\",\"code\":301}")
red_change=$(jq -r '.changes[0].id // empty' <<<"$red")
loc=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$WP_URL$from/")
check "redirect serves 301 to the page ($loc)" '[ "$loc" = "301 $WP_URL$PAGE_PATH" ]'
dup=$(api POST /redirects -d "{\"from\":\"$from\",\"to\":\"$WP_URL/\"}" | jq -r .code)
check "a second redirect from the same path is refused" '[ "$dup" = orbit_redirect_exists ]'

# Undo everything, newest first.
for c in $red_change ${alt_change:-} $(echo "$ids" | tr ' ' '\n' | sort -rn); do
    r=$(api POST "/changes/$c/undo")
    check "undo change $c" '[ "$(jq -r ".change.undone_at // empty" <<<"$r")" != "" ]'
done
check "undoing twice is refused" '[ "$(api POST "/changes/$red_change/undo" | jq -r .code)" = orbit_already_undone ]'

page_html=$(html)
check "page title is back to \"$before_title\"" 'grep -qF "<title>$before_title</title>" <<<"$page_html"'
check "test description is gone" '! grep -q "$desc" <<<"$page_html"'
check "test canonical is gone" '! grep -q "orbit-canonical-$stamp" <<<"$page_html"'
check "page is indexable again" '! grep -Eiq "name=.robots.[^>]*noindex" <<<"$page_html"'
[ -n "${alt_change:-}" ] && check "test alt is gone" '! grep -q "Orbit alt $stamp" <<<"$page_html"'
check "redirect is gone" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$WP_URL$from/")" != 301 ]'

# Undo must not overwrite a later edit made in WordPress.
if [ -n "${WP_CLI:-}" ]; then
    r=$(api POST "/page/$id" -d "{\"description\":\"Orbit wrote this $stamp\"}")
    c=$(jq -r '.changes[0].id' <<<"$r"); key=$(jq -r '.changes[0].storage_key' <<<"$r")
    $WP_CLI post meta update "$id" "$key" "Edited by hand $stamp" >/dev/null
    check "undo refuses after a manual edit" '[ "$(api POST "/changes/$c/undo" | jq -r .code)" = orbit_changed_since ]'
    $WP_CLI post meta delete "$id" "$key" >/dev/null
fi

echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
