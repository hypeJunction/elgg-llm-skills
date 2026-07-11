# Benchmark: `metadata (entity_guid, name)` composite index

A reproducible, clean-container before/after benchmark for adding a composite index
on `metadata (entity_guid, name)`, mirroring the long-standing `entity_guid_name`
index on the `annotations` table.

The matching migration is
`engine/schema/migrations/20260711120000_add_entity_guid_name_index_to_metadata.php`.

## Why this index

The hottest metadata read paths all filter on `entity_guid` **and** `name`:

- `Elgg\Database\MetadataTable::getIDsByName()` — `WHERE entity_guid = ? AND name = ?`
- the metadata preloader / `elgg_get_metadata()` — per-entity name lookups
- `elgg_get_entities(['metadata_name_value_pairs' => ...])` — joins `metadata` on
  `entity_guid` then filters `name`

Today only single-column indexes exist (`entity_guid`, `name(50)`, `value(50)`), so a
lookup by `(entity_guid, name)` resolves `entity_guid` via its index and then scans
every metadata row on that entity to filter `name`. The `metadata` table also absorbed
the entire `private_settings` table in migration `20220825064811`, so entities now
carry more rows each — widening that per-entity scan. The `annotations` table gained
the equivalent `(entity_guid, name)` index back in 2020 (`20200331083912`); `metadata`
never did.

## What is measured

Following standard MySQL index-benchmark methodology, the **headline metric is
deterministic** and the wall-clock is corroborating secondary evidence:

| Metric | Source | Why |
| --- | --- | --- |
| `Handler_read_next` (rows examined) | `FLUSH STATUS` → run → `SHOW SESSION STATUS` | Cache-independent, reproducible run-to-run. It counts rows the engine actually walked. A missing index inflates it; the right index collapses it. |
| Chosen access path (`key`, `rows`, `filtered`) | `EXPLAIN` | Confirms the optimizer switches from the single-column `entity_guid` index to the composite `entity_guid_name`. Plain `EXPLAIN` is used (not `EXPLAIN ANALYZE`) because its output is portable across all four engines. |
| Median wall-clock over 10k lookups | `/usr/bin/time` around a warmed `CALL point_lookups(10000)` | Human-facing corroboration. Warmed buffer pool, median of three runs. |

The workload (`point_lookups`) replays the exact `getIDsByName` query 10,000 times over
a deterministic spread of `(entity_guid, name)` pairs.

## Determinism

Nothing depends on wall-clock or an unseeded PRNG:

- The 1,000,000-row dataset is generated from a **numbers table** (`seq`, built by a
  digit cross-join) via pure integer formulas — 50,000 entities × 20 metadata rows.
  Identical bytes on every run and every engine.
- No `RAND()`, `NOW()`, or `UUID()` anywhere in the seed.
- `ANALYZE TABLE` is run before measuring so the optimizer has fresh statistics.

Before and after run against byte-identical data; only the index changes between them.

## Clean containers

`docker-compose.yml` defines one throwaway server per engine in the Elgg 7.x CI matrix:

- `mysql:8.0`, `mysql:8.4`, `mariadb:10.6`, `mariadb:10.11`

Each uses a **tmpfs datadir** (pristine, empty server on every `up`; no state carried
between runs) and a fixed `--innodb-buffer-pool-size=1G` so the whole dataset lives in
memory — we measure access-path efficiency, not disk I/O. No host ports are published;
the runner execs the client inside each container, so this never clashes with other
local databases.

## Running it

```bash
cd skills/elgg-benchmark/layers/sql
./run.sh   # uses the shared clean-container stack in ../../infra/docker-compose.yml
```

For each engine the runner boots a clean container, installs the exact pre-fix schema
(`sql/00_schema.sql`) and deterministic seed (`sql/01_seed.sql`), measures BEFORE
(`sql/02_measure.sql`), applies the index (`sql/03_add_index.sql`, the same DDL as the
migration), measures AFTER, and writes a per-engine report to `results/<engine>.txt`.
A consolidated summary lives in `RESULTS.md`.

## Real-data variant — `bench-real-db.sh`

`run.sh` uses a formula-generated seed (fixed, portable, matrix-wide). When you
have an actual site database and want the index proven against its **real**
per-entity fan-out, use `bench-real-db.sh` instead. It dumps one table from a
live Elgg DB into the same kind of clean throwaway container and replays the
`getIDsByName` shape over the site's real `(entity_guid, name)` pairs. The live
DB is read-only (dump only); it is never modified.

```bash
./bench-real-db.sh <source-container> [db] [prefix]
# e.g. ./bench-real-db.sh bodyology7x-db-1 elgg elgg_
```

Same verdict rule (`Handler_read_*` delta + `EXPLAIN`). A captured run against a
real 208k-row production `metadata` table (`Handler_read_next` 57,225 → 0) lives
in `../../examples/metadata-real-bodyology/`. Use the synthetic `run.sh` to prove
the mechanism across the full engine matrix; use `bench-real-db.sh` to confirm it
on a specific site's real data (single engine).

## Files

| File | Role |
| --- | --- |
| `../../infra/docker-compose.yml` | Clean throwaway containers, one per CI engine (shared by all layers) |
| `sql/00_schema.sql` | Exact pre-fix `metadata` schema (migration-chain output) + numbers table |
| `sql/01_seed.sql` | Deterministic 1M-row seed + `point_lookups` workload |
| `sql/02_measure.sql` | EXPLAIN + Handler-counter measurement (run before and after) |
| `sql/03_add_index.sql` | The index under test (mirrors the migration) |
| `run.sh` | Orchestrates the full matrix and captures results (synthetic seed) |
| `bench-real-db.sh` | Real-data variant: dump one table from a live site DB and measure |
| `RESULTS.md` | Captured before/after data |

## Methodology references

The design follows established cross-framework benchmarking practice — deterministic
seeding via a single fixed source and formula-generated volume (Laravel factories/Faker
`->seed()`, Doctrine fixtures, Rails `Faker::Config.random`, Django
`factory.random.reseed_random`, Prisma `faker.seed`), with the MySQL-specific headline
metric being `Handler_read_*` deltas plus `EXPLAIN`, wall-clock reported second with
warmup and medians. See [MySQL server status variables](https://dev.mysql.com/doc/refman/8.0/en/server-status-variables.html)
and [EXPLAIN output format](https://dev.mysql.com/doc/refman/8.0/en/explain-output.html).
