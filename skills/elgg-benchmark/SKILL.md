---
name: elgg-benchmark
description: >
  Use when benchmarking Elgg database/query performance, proving the impact of an
  index or schema change, or measuring a change across a realistic large site.
  Triggers on "benchmark elgg", "elgg performance", "does this index help",
  "before/after query benchmark", "seed a large elgg site".
---

# elgg-benchmark

Measure the performance impact of an Elgg database change (an index, a schema
tweak, a query refactor) so the result is **deterministic, reproducible, and
believable** — not a wall-clock number that drifts run to run.

The skill is organized in **layers**. Run the layer that matches the question.

| Layer | Question it answers | Data | Where |
| --- | --- | --- | --- |
| **SQL** | Does this index/DDL change the access path for one hot query? | formula-generated bulk rows (millions, seconds) | `layers/sql/` |
| **API** | What happens to the real query surface on a realistic site? | **native `elgg-cli database:seed`** (production-shaped) | `layers/api/` |

Both layers share the same clean-container stack (`infra/docker-compose.yml`) and
the same measurement rule (see `references/methodology.md`).

## Iron Laws

1. **Deterministic metric is the verdict; wall-clock is a footnote.** The verdict
   is the `Handler_read_*` delta and the `EXPLAIN` plan change — cache-independent
   and reproducible. Wall-clock is reported warmed, as a median of many runs, and
   only ever corroborates.
2. **Clean, throwaway containers.** Every run starts from a pristine server (tmpfs
   datadir, fixed buffer pool, pinned image). No persisted state between runs.
3. **Before and after run on identical data.** Only the change under test differs.
4. **The change under test is a real migration** (`up`/`down`), committed with the
   results — never an ad-hoc `ALTER` that isn't in version control.
5. **No silent truncation.** If you sampled shapes or capped the seed, say so in
   the report. A bounded run must not read as "measured everything."

## When to use which layer

- **"Will adding index X help query Y?"** → SQL layer. It isolates one table's
  access path and shows the row-scan collapse unambiguously in minutes.
- **"Is the whole app faster / did anything regress?"** → API layer. It seeds a
  production-shaped site and runs the query shapes real Elgg code issues.
- Most index work wants **both**: SQL layer to prove the mechanism, API layer to
  confirm the realistic surface didn't regress. Run SQL first (fast, decisive),
  then API (slower, realistic).

## Layer 1 — SQL micro-benchmark

Isolates a single query's access path across the full Elgg 7.x CI engine matrix
(mysql 8.0/8.4, mariadb 10.6/10.11).

```bash
cd layers/sql
./run.sh        # boots each engine clean, seeds 1M rows, measures before -> add index -> after
```

The shipped example proves the `metadata (entity_guid, name)` composite index:
`Handler_read_next` for 10,000 `getIDsByName` lookups drops **130,003 → 0**,
`EXPLAIN` switches from `entity_guid` (20 rows/scan) to `entity_guid_name`
(1 row) — identically on all four engines (`examples/metadata-entity-guid-name/`).

To prove the same index against a **real** site's actual table (real GUIDs and
per-entity fan-out, not a formula seed), use `layers/sql/bench-real-db.sh`, which
dumps one table from a live Elgg DB into a clean throwaway container. A captured
run against a real 208k-row production `metadata` table (`Handler_read_next`
57,225 → 0) is in `examples/metadata-real-bodyology/`.

To benchmark a different change: edit `layers/sql/sql/00_schema.sql` (the pre-change
table), `01_seed.sql` (the deterministic numbers-table seed), `02_measure.sql`
(the query + `FLUSH STATUS`/`SHOW STATUS`), and `03_add_index.sql` (the DDL, which
must mirror your migration). Keep the seed formula-generated — no `RAND()`/clock.

## Layer 2 — API realistic benchmark

Runs the query shapes real Elgg code issues (`references/query-shapes.md`: 92
distinct shapes mined from core+plugins; top 25 cover 78% of usage) against a
**natively seeded** site, and diffs before/after the change.

### Step 1 — bring up a clean, installed Elgg + DB

```bash
cd layers/api
./bin/up.sh            # clean DB container + PHP container, elgg-cli install, activate content plugins
```

### Step 2 — seed with native Elgg seeding

Native seeding runs every row through Elgg's real save lifecycle, so entities,
subtypes, access, metadata names, river, and annotations are production-shaped
(measured: ~10 metadata rows/entity, matching the reference site's ~9). It is
**not instant** — budget from `references/site-profile.md`:

```bash
./bin/seed.sh 400      # --limit per type; ~400 -> tens of thousands of entities; runs in background, polls counts
```

Pick `--limit` from the sizing table in `site-profile.md`. For a *very large* site
either run a high limit in the background for hours, or native-seed a realistic
slice and let the SQL layer formula-multiply the metadata table (both documented
there).

### Step 3 — measure before / after

```bash
./bin/bench.sh          # drops the index, runs bench.php; adds the index, runs again; prints the diff
```

`bench.php` discovers concrete seeded GUIDs (a populous subtype, an owner, a real
metadata name/value pair) and runs the head of the query-shape catalog through
`elgg_get_*()`. For each shape it records three things:

- **`Handler_read_next`** — rows the engine walked (deterministic headline),
- **query ms** — actual SQL time from `performance_schema`, isolated from PHP,
- **wall ms** — the full `elgg_get_*()` cost, median over N iterations.

`report.php` diffs the before/after JSON and leads with the shapes flagged `MD`
(the ~45% that hit metadata by `(entity_guid, name)`). A worked run is in
`examples/api-metadata-index/` — the metadata-fetch shapes drop **−83/−84%** SQL
time; it also surfaced a count-query edge case on a non-selective value.

## Infra

`infra/docker-compose.yml` defines one throwaway, tmpfs-backed server per CI
engine, fixed `--innodb-buffer-pool-size`, no published host ports (clients are
exec'd inside the containers, so it never clashes with other local databases).

## References

- `references/methodology.md` — why deterministic-first, and how to measure it
- `references/query-shapes.md` — the ranked query-shape catalog
- `references/site-profile.md` — production baseline + native-seed sizing
- `examples/metadata-entity-guid-name/` — a complete worked before/after result (synthetic matrix)
- `examples/metadata-real-bodyology/` — the same index proven on a real 208k-row site table

## Layout

```
elgg-benchmark/
  SKILL.md
  infra/docker-compose.yml          clean CI-matrix containers (shared)
  layers/
    sql/  run.sh, sql/*.sql          index/query micro-benchmark
    api/  bench.php, report.php,      native-seed + query-shape benchmark
          bin/{up,seed,bench}.sh
  references/  methodology, query-shapes, site-profile
  examples/    metadata-entity-guid-name (SQL), api-metadata-index (API)
```
