# Elgg 7 migration: verification gospel + the gotchas that hide behind HTTP 200

> Written after a migration where "validated" meant crawling section landing
> pages (`/activity`, `/courses/all`) that all returned 200 — while **every
> entity URL 404'd** and multiple page types fataled behind a 200. Do not
> repeat that. This file is the non-negotiable verification bar + the concrete
> Elgg 6→7 breakages to fix.

## The Iron Rule of migration verification

**A migration is NOT verified until an authenticated, feature-level E2E suite
is green.** Section-page status crawls are worthless — they miss:
- entity-detail pages (profiles, courses, topics, files, videos, bookmarks) that 404
- PHP fatals that render behind an HTTP 200 (empty/partial body)
- broken JS (no toasts, dead widgets) — needs a real browser, not curl
- unstyled pages (CSS cache version bumped out from under the page)
- admin area (its own theme + boot-time plugin failures)

### Verify with a real browser, as a real user AND admin

Use a Playwright verification suite (scaffold one with the `elgg-js-test-writer`
skill; the pattern below is portable to any Elgg site):
per navigation it asserts, automatically, on **every** page:
1. document status < 400
2. **no PHP-fatal signature in the body** (`Fatal Error|ArgumentCountError|
   TypeError|Too few arguments|Call to undefined|must be of type|Cannot include
   start\.php|has been deactivated\. Reason`)
3. **no broken sub-resource** (assets / fetch / ESM imports, tracked by URL —
   ignore favicon.ico + PWA manifest.json)
4. **no uncaught JS error and no JS console error**
5. **page is styled** (≥1 stylesheet applied with real rules) — catches the
   stale-simplecache "unstyled admin" class of bug
It crawls by **following the first link of each list item** so it exercises the
real, generated entity URLs (pretty or internal), as a logged-in user and admin.

Run on a **local stack served at ROOT** (`http://localhost:PORT/`), never only
behind the production stripping-proxy — see "subpath" gotcha below.


## The verification bar, raised again (2026-07-10)

The Iron Rule above is still not enough. An authenticated feature-level E2E suite,
green, still missed **85 unreachable pages and every discussion reply on the site**.

Add these three checks. Each one catches a class the others cannot see.

### 1. Ask every entity for its own URL

A route crawl walks links. An entity whose subtype was never migrated has **no class and no
URL**, so nothing links to it, so nothing 404s. It is simply gone, silently.

```php
foreach (elgg_get_entities(['type' => 'object', 'limit' => 0]) as $e) {
    if (!$e->getURL()) {
        // subtype not registered -> ElggUndefinedObject -> unreachable content
    }
}
```

Then fetch one permalink per subtype. `bin/verify-preview-live.sh` (elgg-site-upgrade) does this.

### 2. Assert zero pending upgrades

See `FC-UPG-*` in the failure catalog. `elgg-cli upgrade` runs only SYSTEM upgrades; the
asynchronous batches need `elgg-cli upgrade all -n -f`. Anything left pending is either work that
never happened or an orphan whose class Elgg has since deleted — and the second kind never errors.

### 3. Verify against the OLD site, not against your own expectations

Production is the oracle. Sample **at least two** entities of a kind before concluding anything:
`/profile/dror` (an admin) returns 200 while `/profile/kevin.macaulay` (an ordinary member)
returns 302 — on BOTH 2.x and 7.x. Sampling only the admin makes the page look public and
produces a "fix" that is a regression.

Beware the anonymised dev database. If the anonymiser is not name-scoped it clobbers *functional*
metadata (`content_access_mode`, `membership`), so anonymous visibility on the dev stack does not
match production, and its GUID space is disjoint from the real one — the same number can be a
group in one and a widget in the other. Generate fixtures from the target database; never
hardcode them.

## Subpath deployments: the reverse proxy must forward the prefix

If Elgg's `wwwroot` carries a path (`https://host/forum-7.x/`) and the proxy STRIPS that prefix
before proxying, Elgg's base path disagrees with the request path. Everything looks fine until a
plugin rewrites a route.

`Elgg\Router::allowRewrite()` hands the new segments to `Request::setUrlSegments()`, which rebuilds
the URI as `"$base_path/" . implode('/', $segments)` — i.e. `forum-7.x/profile/dror`. Elgg then
looks for a route named `forum-7.x` and 404s. **Every SEF pretty URL dies**: `/@username`,
`/blog/{guid}-{slug}`, the lot. Nothing is logged.

Fix in nginx, not in Elgg:

```nginx
location ^~ /forum-7.x/ {
    proxy_pass http://127.0.0.1:8287/;          # trailing slash strips the prefix
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto https;
    proxy_set_header X-Forwarded-Prefix /forum-7.x;   # <- load-bearing
}
```

Elgg trusts `X-Forwarded-Prefix` (Symfony `HEADER_X_FORWARDED_PREFIX`) and restores the base path.
No core patch is needed — I tried one, and it was unnecessary. Probe a pretty URL as a canary
after every deploy; a plain route crawl passes with all of them broken.

## Deployed code is not migrated code

Source gates (`migrate.php --check/--verify`, PHPUnit, residue scans) read plugin BRANCH SOURCE.
A site installs plugins from the PINNED TAGS in `composer.lock`. A fix committed but never tagged
is absent from the running site no matter how often you rebuild the image, and every source gate
stays green while the preview serves the old code.

Run `bin/check-release-lag.sh <composer.lock> <plugins-dir> --vendor-prefix <vendor>` before you
believe any deploy. It asserts `composer.lock pin == newest tag on the branch == branch tip`.
`bin/overlay-branch-source.sh` lets you test a branch fix against a running container BEFORE
cutting a public tag — which is how you avoid tagging an unverified tip.


## Elgg 6→7 breakages this migration hit (fix at plugin source)

- **hypeseo SEF pretty URLs 404 site-wide.** hypeseo registers its inbound
  `route:rewrite` handler via declarative `elgg-plugin.php` `events`, which Elgg
  7 registers during `init` — AFTER `Application::allowPathRewrite()` fires the
  event. The handler misses the single dispatch → every `/@user`, `/course/*`,
  `/topic/*`, ... 404s. **Fix:** register `Router::enforceRewriteRules` in
  `Bootstrap::boot()` (plugins_boot, before allowPathRewrite).
- **`getIconLastChange()` now requires `$size`.** A 0-arg call (hypeicons
  `setDefaultFileIcons`) throws `ArgumentCountError` on `entity:icon:url` for
  every object → file pages 500 + entity cover icons render the placeholder.
- **`elgg_get_excerpt(string $text)` rejects null.** Legacy views pass a null
  description → `TypeError` → video/bookmark/link-card pages 500. Fix call sites
  (`(string) $entity->description`) or make the helper `?string`.
- **Removed-symbol / signature fatals** surface only once routing works. e.g. an
  action/event handler still using the pre-4.x `($event,$type,$object)` signature
  (`apiadmin_delete_key`) fatals on EVERY object delete — invisible until you
  delete something. Grep for legacy handler signatures.

## Data / boot cleanup steps the chain does NOT do (add them)

- **camelCase plugin entities.** The 3x→4x plugin-id lowercasing leaves the old
  camelCase plugin entities in `elgg_entities` (disabled). In 7.x they can
  re-activate and fatal (`Cannot include start.php for plugin hypeInvite at
  mod/hypeInvite/`), spamming admin notices and deactivating plugins. **First
  copy their functional metadata (settings) to the lowercase twin** (they hold
  the real prod config — see the stranded-settings issue), **then delete** them.
  Filter on the raw `title` metadata via `BINARY value REGEXP '[A-Z]'` — NOT
  `getDisplayName()` (which title-cases legit lowercase plugins and over-deletes).
- **Stale admin notices** persist after the underlying issue is gone — clear the
  `admin_notice` objects.
- **Content URL rewrite + hypescraper serialized data + plugin-settings carry** —
  see the sibling references / bd issues.

## Subpath deployment is a bug amplifier — validate at ROOT

A `/subpath/` deployment behind a prefix-**stripping** reverse proxy makes Elgg's
`getBasePath()` (`/subpath`) disagree with the stripped request path, so
`Request::setUrlSegments()` re-injects the prefix into route segments and every
`route:rewrite` target 404s; it also breaks `elgg_get_current_url()` (pagination
→ prod root) and lets a vhost-level `expires max` clobber Elgg's no-cache headers
(stale login/CSS). These are **proxy artifacts, not migration defects** — a
local root stack won't have them. Validate the migration at root; treat the
subpath preview's proxy patches as deploy-only.
