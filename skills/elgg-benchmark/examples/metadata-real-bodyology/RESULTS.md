# Worked result — `metadata (entity_guid, name)` index on REAL site data

Companion to `examples/metadata-entity-guid-name/` (the synthetic 1M-row run).
This one measures the same composite index against a **real production Elgg 7.x
site's actual `metadata` table** — real GUIDs, real names, real per-entity
fan-out — via `layers/sql/bench-real-db.sh`, which dumps one table from a live
DB into a clean throwaway container (the live DB is never modified).

## Reproduce

```bash
cd skills/elgg-benchmark/layers/sql
./bench-real-db.sh <source-container> <db> <prefix>
# e.g. ./bench-real-db.sh bodyology7x-db-1 elgg elgg_
```

## Site under test

Real chain-migrated Elgg 7.x community site (the same site the
`references/site-profile.md` baseline is captured from):

| Table | Rows |
| --- | ---: |
| entities | 22,940 |
| **metadata** | **208,519** |
| entity_relationships | 25,950 |
| river | 4,278 |
| annotations | 1,128 |

Real `metadata` schema: `name` is `mediumtext` (indexed `name(50)`), `value` is
`longtext` — which is why the composite must prefix `name(255)`, not index the
raw column. Pre-change indexes: single-column `entity_guid`, `name(50)`,
`value(50)` only. No `(entity_guid, name)` composite.

## Result — 10,000 `getIDsByName` lookups over real (entity_guid, name) pairs

| Metric | BEFORE (as-shipped) | AFTER (+ `entity_guid_name`) |
| --- | ---: | ---: |
| EXPLAIN `key` | `entity_guid` | `entity_guid_name` |
| EXPLAIN `rows` / scan | 25 | **1** |
| EXPLAIN `filtered` | 1.10% | 100.00% |
| **`Handler_read_next`** | **57,225** | **0** |
| `Handler_read_key` | 20,000 | 20,000 |

**Verdict: `Handler_read_next` 57,225 → 0.** The optimizer switches from
resolving `entity_guid` and then walking ~25 rows per entity to filter `name`,
to a single exact composite hit (`rows=1`, `filtered=100%`). On real data the
engine walked ~5.7 rows per lookup on the sampled pair distribution — lower than
the synthetic run's 20-per-entity (`130,003 → 0`) because bodyology's real
fan-out is uneven (widgets carry ~10 metadata rows, most objects far fewer), but
the collapse to **0** is identical. Real data confirms the mechanism the
synthetic seed demonstrates.

`Handler_read_key` is 20,000 (not 10,000) because each iteration does two keyed
reads — one PK read on the 10k-row workload `pairs` table, one on
`${prefix}metadata` — and that overhead is identical before and after, so it
does not affect the verdict.

## Notes / honesty

- **Single-engine.** This real-data run measured `mysql:8.0` only (the site's
  engine family). The synthetic `metadata-entity-guid-name/` example proves the
  same collapse identically across the full CI matrix (mysql 8.0/8.4, mariadb
  10.6/10.11); the real-data variant does not re-run the matrix.
- **Sampled workload.** The 10,000 lookup pairs are a deterministic even sample
  (every *k*-th row by `id`) of the 208,519 real pairs — not the full table.
  This is stated rather than implied (Iron Law #5: no silent truncation).
- The measured `metadata`/`entity` ratio here is ~9.1, matching the ~9.0 in
  `references/site-profile.md`.
