# Production Cutover Runbook (blue-green)

A generic, reusable runbook for flipping an Elgg site to a migrated major version
with a seconds-fast rollback. Copy it into your site repo and fill the `<...>`
placeholders. The **model is blue-green**: the live old-version stack is NEVER
mutated; a new stack is built alongside, the production DB + dataroot are
migrated into it through the chain, it is smoke-tested, then traffic is flipped
by DNS/reverse-proxy. **Rollback = flip back to the untouched old stack.**

## 0. Go/No-Go gates (all must hold before scheduling the window)

| Gate | Evidence |
|---|---|
| Every version tier boots/activates/renders (clean install) | per-tier clean-rebuild check |
| Full chain on ANONYMIZED prod DB, no 5xx | chain harness on `bin/build-anon-seed.sh` output |
| Authenticated read + write paths pass on target | `bin/verify-write-paths.sh` |
| Render parity old→new, no route regressed | `bin/verify-parity.sh check <from> <to>` |
| `elgg-cli upgrade` clean on migrated prod data | run against a restored prod dump, expect exit 0 |
| Avatar/file serving from the real dataroot | md5 byte-match a known avatar; a signed serve-file returns 200 |
| Backup/restore round-trip is loss-less | §6 dry-run: state A == state B (counts AND page md5s) |
| Plugin disposition signed off | which prod-active plugins are kept/dropped — **owner decision** |
| Maintenance window + rollback owner agreed | scheduled, named |

Do **not** proceed while any gate is open. The last three are human decisions,
not scripts.

## 1. Pre-cutover (T-24h)

1. Freeze code on the target branch; confirm `composer.lock` tips are current
   (`bin/check-release-lag.sh <lock> <plugins-dir>` → no LAG/FLOAT).
2. Build and warm the target image; confirm it boots a clean install.
3. Announce the maintenance window.

## 2. Backup (T-0 — production enters read-only/maintenance mode)

The backup is the rollback target. Capture **DB + dataroot + code**:

```bash
mysqldump -u<user> -p --single-transaction --routines --triggers \
  <prod_db> | gzip > cutover-backup-$(date +%F).sql.gz
tar czf cutover-dataroot-$(date +%F).tar.gz -C <dataroot-parent> <dataroot-dir>
```

> The old stack stays running and untouched — it IS the rollback. The backup is
> a belt-and-suspenders second copy in case the old stack is later torn down.

## 3. Migrate (T+0:10)

Run the production DB through the chain into the new stack (phinx schema +
`Elgg\Upgrade\Batch` data migrations per tier), or drive a single git-managed
site with `bin/upgrade-linear.sh --project <site> --to <N>`. Then restore the
prod-active plugin set with `bin/restore-active-plugins.sh` (case-insensitive
`LOWER(title)` ∩ on-disk), overlay the dataroot, run `elgg-cli upgrade` (must
exit 0), and `elgg-cli cache:clear && cache:invalidate`.

> **Enable simplecache AFTER loading prod data.** A migrated prod DB often carries
> `simplecache_enabled = 0`, so the site serves stale `/cache/0/...` asset URLs
> that 410 → the UI loads completely UNSTYLED while pages still return HTTP 200.
> Set `simplecache_enabled = 1`, then `cache:clear`. The smoke test MUST assert a
> CSS asset returns 200 (not 410) — a status-only page check passes on a broken UI.

## 4. Smoke test (T+0:40, BEFORE flipping traffic)

```bash
bin/verify-write-paths.sh --base http://localhost:<port> --user <admin> --pass <pw> --db-container <db>
bin/verify-parity.sh check <from> <to> --base http://localhost:<port> --user <admin> --pass <pw>
```

Required: 0×5xx on anon + authenticated batteries; avatars serve; `/admin/plugins`
renders; `elgg-cli upgrade` exits 0. **Open the homepage in a real browser** and
confirm it renders correctly — HTTP 200 hides JS/importmap/CSS failures.

> **Theme-priority trap.** If your theme works by overriding core element views,
> confirm it holds the HIGHEST plugin priority — a later-loading core plugin
> (e.g. `activity`, which ships its own `resources/index`) can outrank it, and
> the site silently renders core defaults (still HTTP 200) instead of your theme.
> `$theme->setPriority('last')` then `cache:invalidate && cache:clear`; assert
> `resources/index` resolves to your theme's mod dir.

## 5. Cutover (T+1:00)

1. Flip DNS / reverse-proxy upstream to the new stack.
2. Watch the new stack's error log + a synthetic 5xx monitor for ~30 min.
3. Lift maintenance mode.

## 6. Rollback

**Primary (blue-green, seconds):** flip DNS/proxy back to the untouched old
stack. No data restore needed. Content created on the new stack after cutover is
lost on rollback — acceptable inside the watch window.

**Secondary (full restore, if the old stack is gone):**

```bash
mysql -u -p -e "DROP DATABASE <db>; CREATE DATABASE <db>;"
gunzip -c cutover-backup-DATE.sql.gz | mysql -u -p <db>
tar xzf cutover-dataroot-DATE.tar.gz -C <dataroot-parent>
```

**Prove this is loss-less BEFORE the window** (a go/no-go gate): on a clone,
record entity counts + md5 of a few rendered anonymous pages (state A), then
`mysqldump --single-transaction → DROP DATABASE → restore`, and re-check (state
B). A and B must be identical — same counts AND byte-identical page renders.

## 7. Post-cutover cleanup

### 7a. Prune stale plugin-entity rows (guarded)

A chain-migrated prod DB often carries `object/plugin` entities with no on-disk
`mod/<id>` dir (camelCase originals from a pre-rename layout, retired plugins).
They're inactive but log "Cannot include elgg-plugin.php" on every scan. Run
AFTER `elgg-cli upgrade` exits 0 and smoke passes. Guarded so it can never touch
an active or on-disk plugin:

```php
$removed = [];
foreach (elgg_get_entities(['type' => 'object', 'subtype' => 'plugin', 'limit' => 0]) as $plugin) {
    $id = $plugin->getID();
    if (is_dir(elgg_get_plugins_path() . $id)) {
        continue;                 // real on-disk plugin — keep
    }
    if ($plugin->isActive()) {
        error_log("SKIP active-but-missing plugin entity: {$id}"); // real problem — do NOT delete
        continue;
    }
    $plugin->delete();
    $removed[] = $id;
}
echo 'pruned ' . count($removed) . " stale plugin entities: " . implode(', ', $removed) . "\n";
```

Re-open `/admin/plugins` and confirm the noise is gone and the page still renders.

### 7b. Misc

- Remove maintenance-mode assets; re-enable cron/queue workers.
- Regenerate caches; confirm simplecache CSS/JS is served (200, not 410).
