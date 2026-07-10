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
