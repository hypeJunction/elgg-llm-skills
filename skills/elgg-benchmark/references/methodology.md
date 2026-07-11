# Benchmark methodology

How to measure a database change so the result is trustworthy and reproducible.
Synthesised from cross-framework practice (Laravel factories/`Benchmark`, Doctrine
fixtures + Stopwatch, Rails `explain`/benchmark-ips, Django `explain()`/
`CaptureQueriesContext`, Prisma seed + `$queryRaw EXPLAIN ANALYZE`) and MySQL
server-side methodology.

## The one rule that matters most

**Make the headline metric deterministic; treat wall-clock as secondary.**

Wall-clock depends on cache state, the scheduler, other load, and I/O — it varies
run to run even when nothing changed. The number of rows the storage engine
actually walked does not. So the pass/fail signal is the row-access delta; the
clock is corroboration.

### Deterministic metric: `Handler_read_*`

```sql
FLUSH STATUS;
/* query under test */;
SHOW SESSION STATUS LIKE 'Handler_read_%';
```

| Counter | Meaning | Reading |
| --- | --- | --- |
| `Handler_read_rnd_next` | next row in the data file | high ⇒ **full/partial table scan** — the thing a missing index causes |
| `Handler_read_key` | row fetched via an index | high ⇒ index used (good) |
| `Handler_read_next` | next row in index order | rises during index range scans / per-key fan-out |
| `Handler_read_first` | first entry of an index | high ⇒ full index scan |
| `Handler_read_rnd` | row by fixed position | high ⇒ sorting / poor join keys |

"The index worked" = `rnd_next`/`read_next` collapses toward the number of rows
you actually wanted, while `read_key` rises. Confirm the *plan* too, not just the
counter: `EXPLAIN` (`key`, `rows`, `filtered`) — and `EXPLAIN ANALYZE` when you
need actual-vs-estimated rows (MySQL 8.0.18+/MariaDB; TREE format, so keep plain
`EXPLAIN` for anything that must parse across engines).

### Wall-clock, done honestly

Warm the buffer pool first (discard warm-up runs), then time **many** iterations
and report the **median** (and p95 if you can). Never a single run. State whether
the cache was cold or warm — a warm, memory-resident dataset makes wall-clock
understate a scan win because there are no page reads to remove; the Handler
counter still shows the full effect.

## Determinism of the data

- **One fixed source of randomness.** Every framework converges on a single
  seedable entry point (Faker `->seed()`, `Faker::Config.random`,
  `factory.random.reseed_random`, `faker.seed()`, MySQL `RAND(N)`). Set it once,
  before generation, in fixed order.
- **Prefer formula-generated volume** (numbers/tally table, recursive CTE) over
  PRNG loops for bulk rows — zero PRNG dependency, reproducible, fast to insert.
- **Beware wall-clock leaking into "deterministic" data** — relative-date helpers
  and `NOW()`/unseeded `RAND()`/`UUID()` silently break reproducibility across
  days. Pin a reference date or avoid them.
- **Before and after must run on byte-identical data** — snapshot it; only the
  schema/query changes between the two measurements.

This skill offers two seeding strategies, matched to the two layers:

| Layer | Seed | Why |
| --- | --- | --- |
| SQL micro | formula-generated numbers-table (millions of rows, seconds) | isolates one table's access path; needs volume, not entity semantics |
| API realistic | **native `elgg-cli database:seed`** | real entities/metadata/relationships through the actual save lifecycle — production-shaped rows, subtypes, access, metadata names |

## The environment is part of the result

- Run in **clean, throwaway containers** (this skill: tmpfs datadir, no persisted
  state, one per supported engine) so every run starts identical.
- Hold `innodb_buffer_pool_size` fixed across before/after and record it.
- Pin image tags. Record engine version in the results header.
- The change under test is a **reversible, version-controlled artifact** (a
  migration `up`/`down`), committed alongside the seed profile and results so the
  whole benchmark reproduces from the repo.

## Reporting

Lead with the Handler-counter table (before → after, per engine), then the
`EXPLAIN` key/rows change, then wall-clock as a secondary warmed/median column.
Call out any coverage the run bounded (sampled shapes, capped scale) — silent
truncation reads as "measured everything" when it wasn't.
