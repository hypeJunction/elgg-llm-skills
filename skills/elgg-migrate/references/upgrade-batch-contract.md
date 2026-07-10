# Writing an `Elgg\Upgrade\Batch` that actually finishes

An upgrade batch that never terminates, or that reports a failure, does not just fail itself —
it stalls **every upgrade queued behind it**, forever. When a later Elgg release deletes the
class of a stalled upgrade, the data migration it was carrying becomes permanently unreachable.

That is not hypothetical. On bodyology six batches looped, three failed, and by the time anyone
looked, `\Elgg\Pages\Upgrades\MigratePageTop` and
`\Elgg\Discussions\Upgrades\MigrateDiscussionReply` had been deleted from Elgg — leaving 85 pages
and every discussion reply on the site stranded on subtypes Elgg 7 does not register. They loaded
as `ElggUndefinedObject`, `getURL()` returned `''`, and nothing 404'd, because nothing linked to
them any more.

## The termination contract

`Elgg\Upgrade\Loop::isCompleted()`:

```php
if ($this->batch->shouldBeSkipped())                return true;
if ($this->result->wasMarkedComplete())             return true;
if ($this->count === Batch::UNKNOWN_COUNT)          return false;   // you must markComplete()
if (!$this->batch->needsIncrementOffset()) {
    return ($this->batch->countItems() - $this->result->getFailureCount()) <= 0;
}
return $this->processed >= $this->count;
```

Read that as three mutually exclusive modes. Pick one deliberately.

| `needsIncrementOffset()` | `countItems()` must be | `run()` must | Ends when |
|---|---|---|---|
| `true` | the total, once | do all its work in one pass, or page with `$offset` (Elgg advances it for you) | `processed >= count` |
| `false` | **remaining work**, recomputed every call | REMOVE or CONVERT rows so the count falls | `countItems()` reaches 0 |
| `false` + `UNKNOWN_COUNT` | — | call `$result->markComplete()` when done | you say so |

### The trap

`needsIncrementOffset() === false` is a **promise that your count shrinks**. Two ways to break it,
both of which spin forever:

```php
// BROKEN: constant count. The loop calls run() until the heat death of the universe.
public function needsIncrementOffset(): bool { return false; }
public function countItems(): int { return count(self::ARRAY_SETTINGS); }   // always 2

// BROKEN: pages with $offset, but Elgg only advances the offset when this returns true,
// so run() re-fetches the same first 50 rows forever.
public function needsIncrementOffset(): bool { return false; }
public function run(Result $result, $offset): Result {
    $items = elgg_get_entities([... 'limit' => 50, 'offset' => $offset]);
}
```

Symptom: the CLI progress bar climbs past the item count and keeps going. hypeinbox's reached
1.5 million; anypage's passed 987,000.

Fix: return `true` in both cases.

A subtler variant: `countItems()` counts "rows not yet converted", but `run()` *skips* a row it
cannot convert. The count never reaches zero. **Delete the row** (log a notice) rather than skip it.

## Never `addFailures()` for a row you can never convert

`UpgradeService::runUpgrades()` rejects the promise if `Result::getFailureCount()` is non-zero,
and `all($promises)` rejects the whole batch — so nothing after it runs:

```
Unhandled promise rejection with RuntimeException: Upgrade "…" failed
```

A row that is neither JSON nor valid serialized data is not a *failure*, it is *garbage*. Delete
it, `addSuccesses()`, and move on.

Real instances:

- **hypegallery** counted two 2016-era serialized `ElggBatch` **objects** as failures. With
  `allowed_classes: false` they decode to `__PHP_Incomplete_Class`, never an array, so they could
  never become JSON. Dead cache *and* a PHP object-injection vector — delete them.
- **hypescraper** called `$result->addFailure()`. **There is no such method.** It is
  `addFailures()`. The first unparseable row was a fatal, not a failure.

## API notes that cost real time

- `Result` has exactly: `addSuccesses()`, `addFailures()`, `addError()`, `markComplete()`.
- `elgg_log($msg, 'NOTICE')` **throws** in Elgg 7. Pass `\Psr\Log\LogLevel::NOTICE`.
- `elgg_private_settings` was removed in Elgg 4. Plugin settings live in metadata; read them with
  `$plugin->getAllMetadata()`. Do **not** use `getAllSettings()` to test whether a key is stored —
  it merges the `elgg-plugin.php` defaults and can never tell you.
- Elgg 6 made `Upgrade\Batch` an abstract class: `extends AsynchronousUpgrade` and
  `run(Result $result, $offset): Result`.

## Running them

```bash
elgg-cli upgrade          # SYSTEM upgrades only — silently skips every async Batch
elgg-cli upgrade all -n -f   # what you actually want
```

`-n` because the bare command blocks on a confirmation prompt and looks like a hang. `-f` to force
past the lock (`elgg_config.upgrade_running`) an aborted run leaves behind.

## Verifying

After every tier, and again at the end:

```sql
SELECT e.guid, MAX(CASE WHEN m.name='class' THEN m.value END) AS class
FROM elgg_entities e JOIN elgg_metadata m ON m.entity_guid = e.guid
WHERE e.subtype = 'elgg_upgrade'
GROUP BY e.guid
HAVING MAX(CASE WHEN m.name='is_completed' THEN m.value END) IN ('0')
    OR MAX(CASE WHEN m.name='is_completed' THEN m.value END) IS NULL;
```

Zero rows, or explain every one. `elgg-site-upgrade/references/7x-prune-orphaned-upgrades.php`
classifies them into *dead class*, *camelCase twin* (the 4.x plugin-id lowercasing orphans an
upgrade whose identity is `{plugin_id}:{version}`), and *genuinely runnable*.

Then ask every entity for its own URL. A route crawl cannot see an entity that has none:

```php
foreach (elgg_get_entities(['type' => 'object', 'limit' => 0]) as $e) {
    if (!$e->getURL()) { /* subtype never migrated -> unreachable content */ }
}
```

See `FC-UPG-01`…`FC-UPG-06` in `migration-failure-catalog.md`.
