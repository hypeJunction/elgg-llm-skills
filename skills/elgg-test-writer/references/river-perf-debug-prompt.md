# Debug prompt: Elgg 7 river listing full-scans (527k rows for 4,278 entries)

Self-contained prompt for a fresh session working in an **Elgg 7 core checkout**.
Companion to FC-ALL-16 in `migration-failure-catalog.md`. The homepage-perf win
(3.9s → 1.57s) came from the hypeseo SEF fix; this is the *remaining* ~0.4s that
lives in core's river query and could not be fixed downstream.

---

You are working in an Elgg 7.x core checkout. A river-backed page (the site
homepage / /activity, which call elgg_list_river()) spends ~730ms in two SQL
queries. Find out why the optimizer starts from the wrong table and propose a fix
IN CORE (elgg_get_river / the river QueryBuilder), with before/after EXPLAIN and
timing proof. Do NOT "fix" it by patching a plugin.

## The two hot queries (real SQL, captured from MySQL general_log — NOT the
## normalized performance_schema digest, which hides the join order)

```sql
SELECT DISTINCT rv.*
FROM elgg_river rv
INNER JOIN elgg_entities se ON se.guid = rv.subject_guid
INNER JOIN elgg_entities oe ON oe.guid = rv.object_guid
LEFT  JOIN elgg_entities te ON te.guid = rv.target_guid
WHERE (se.enabled = 'yes' AND se.deleted = 'no'
       AND NOT EXISTS (SELECT 1 FROM elgg_metadata pp_md
                       WHERE (pp_md.entity_guid = se.guid OR pp_md.entity_guid = se.owner_guid)
                         AND pp_md.name = ? AND pp_md.value = ?)
       AND se.access_id IN (...))
  AND (oe.enabled = 'yes' AND oe.deleted = 'no'
       AND NOT EXISTS (... same shape on oe ...)
       AND oe.access_id IN (...)
       AND ((oe.type = ? AND oe.subtype IN (?)) OR ... ))
  AND (te ... )
ORDER BY rv.posted DESC
LIMIT 20;
```

(There is a matching `SELECT COUNT(DISTINCT rv.id)` with the same joins.)

The NOT EXISTS subqueries are injected by the `private_profiles` plugin via an
access hook. Treat them as a given input — the plan is wrong even without them.

## What EXPLAIN shows (this is the actual defect)

- Leading table is `oe` (object entity): type=ref, key=deleted, rows≈11,600,
  Extra="Using temporary; Using filesort".
- It should lead with `elgg_river` (4,278 rows) ordered by `posted`, then eq_ref
  out to se/oe/te on their PRIMARY (guid). Instead it scans ~11,600 object
  entities and joins back to river.
- Each of the 3 `pp_md` NOT EXISTS runs as a DEPENDENT SUBQUERY per candidate
  row (key=name, rows≈19). 11,600 × 19 × 3 ≈ the ~527k rows examined per query.
- Net: ~365ms cold per query, ×2 (list + count).

The optimizer picks `oe` because the `oe.type/subtype IN (...)` predicate makes it
look more selective than it is, and DISTINCT + ORDER BY force a temporary table +
filesort that the optimizer tries to avoid by reordering joins.

## Already ruled out (do not repeat — measured, no effect)

- private_profiles `entity_guid = g OR entity_guid = og` → `IN (g, og)`:
  EXPLAIN still chose key=name (correlated outer columns).
- composite index elgg_metadata(entity_guid, name(50), value(50)): optimizer
  ignored it; dropped.
- ANALYZE TABLE elgg_river, elgg_entities, elgg_metadata: plan unchanged.
- Enabling system_cache / simplecache minify: irrelevant, this is DB time.

## Hypotheses to test, in order

1. Force the river table to lead: add STRAIGHT_JOIN, or restructure so the driver
   is `SELECT ... FROM elgg_river rv ... ORDER BY rv.posted DESC LIMIT 20` with the
   entity/access constraints applied as EXISTS/semi-joins rather than as filters on
   joined-in `oe`/`se` that the planner can reorder around. Confirm EXPLAIN leads
   with rv and drops "Using temporary; Using filesort".
2. Check whether DISTINCT is even needed. river.id is unique; the DISTINCT exists
   only because target/object joins can fan out. If the joins are eq_ref on guid,
   they can't fan out — DISTINCT may be removable, which kills the temp table.
3. Consider a covering/order index the planner can use to satisfy ORDER BY
   rv.posted DESC directly (elgg_river already has a `posted` index — verify the
   plan can use it once rv leads).

## Where to look in core

- elgg_get_river() and the river repository/QueryBuilder it delegates to
  (grep: `elgg_get_river`, `class River`, `RiverTable`, river `->execute()`).
- How access constraints are appended to the river query (the `se/oe/te` enabled/
  deleted/access_id predicates and the access-hook NOT EXISTS).
- Whether the QueryBuilder emits STRAIGHT_JOIN or lets MySQL choose.

## How to measure (these three things lied to me — trust only the last)

- Elgg's Database::getQueryCount() UNDERCOUNTS. Use
  `SHOW GLOBAL STATUS LIKE 'Questions'` before/after instead (43 reported vs 116
  actual on the bodyology homepage).
- A fast `SELECT 1` round trip (0.04ms) says NOTHING about query execution time.
- The slow query log needs `SET GLOBAL long_query_time=0.05` AND a reconnect; a
  stale session value of 10s records nothing.
- USE: `TRUNCATE performance_schema.events_statements_summary_by_digest;`, run one
  cold render, then
  `SELECT ROUND(SUM_TIMER_WAIT/1e9,1) ms, COUNT_STAR, SUM_ROWS_EXAMINED, LEFT(DIGEST_TEXT,60)
   FROM performance_schema.events_statements_summary_by_digest
   WHERE SCHEMA_NAME='elgg' ORDER BY SUM_TIMER_WAIT DESC LIMIT 5;`
- For self-time: `pecl install excimer` in a THROWAWAY container, sample at 1ms,
  aggregate by top frame. It put 82.7% in Doctrine\DBAL\...\Statement::execute,
  which is how I knew it was the DB and not PHP.
- To get the REAL SQL (with join order): `SET GLOBAL general_log='ON'` briefly,
  render once, read the `FROM elgg_river` line, run EXPLAIN on it.

## Data shape for a realistic repro

~4,278 elgg_river rows, ~11,600 non-deleted object entities, ~208k elgg_metadata
rows, private_profiles active. A synthetic seed with those proportions reproduces
the plan; a near-empty DB will not (the optimizer picks a different plan).

## Success criteria

- EXPLAIN leads with elgg_river, no "Using temporary; Using filesort".
- SUM_ROWS_EXAMINED for the river SELECT drops from ~527k toward a few thousand.
- Cold render of the river resource drops measurably (baseline: ~365ms/query).
- Output is byte-identical (same 20 river items, same order) — diff the rendered
  HTML before/after.
- If the only correct fix is in core query construction, write it as an upstream
  Elgg PR, not a downstream patch.
