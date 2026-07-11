# Site profile — realistic sizing for the large-site benchmark

The API-layer seed is sized from a **real production Elgg site** (an established
community running Elgg 7.x) so that row counts, per-entity fan-out, and the
type/subtype/metadata mix match what a query actually meets in production. The
seed then scales that profile up by a `SCALE` factor to model a *very large*
site.

## Measured baseline (production reference)

| Table | Rows |
| --- | ---: |
| entities | 22,940 |
| metadata | 208,519 |
| entity_relationships | 25,950 |
| river | 4,278 |
| annotations | 1,128 |

### Entities by type

| type | count | share |
| --- | ---: | ---: |
| object | 20,350 | 88.7% |
| user | 2,538 | 11.1% |
| group | 51 | 0.2% |
| site | 1 | — |

### Top object subtypes (the query-shape hotspots)

| subtype | count | note |
| --- | ---: | --- |
| widget | 10,280 | dominant — drives the `context`/`handler` metadata lookups |
| messages | 2,512 | |
| river_object | 1,470 | |
| comment | 1,051 | drives the `parent_guid` metadata pair (shape #1) |
| hjwall | 999 | |
| notification | 950 | |
| resource_folder | 885 | |
| file | 291 | |
| page | 90 | |

### Derived ratios (what the seeder preserves)

| ratio | value | drives |
| --- | ---: | --- |
| metadata rows / entity | **~9.0** | the `(entity_guid, name)` fan-out this index targets |
| relationships / entity | ~1.13 | relationship shapes (#3, #14, #23) |
| users / total entities | ~11% | `type => user` shapes (#9, #16) |
| widgets / objects | ~50% | widget `context` lookup (#25, #26) |

### Top metadata names

`videos`, `handler`, `context`, `column`, `order` (all ~10,280 — widget
attributes), then `fixed_parent_guid` (~9,719), `description`, `title`, `name`,
`email`. The seeder plants the same high-cardinality names so `metadata_name` /
`metadata_name_value_pairs` filters have production-like selectivity.

## Scaling to a large site — with native seeding

The API layer seeds with **native `elgg-cli database:seed`**, so every row goes
through Elgg's real save lifecycle. Measured throughput on a clean install:

- **~12 entities/sec**, producing **~10 metadata rows/entity** — which matches the
  reference site's ~9 ratio, confirming the native seeder reproduces production
  shape (users, subtypes, access, metadata names, river, annotations) faithfully.

That fidelity has a cost: native seeding is not instant.

| target entities | metadata (~10×) | native seed time | models |
| ---: | ---: | ---: | --- |
| ~5k | ~50k | ~7 min | medium site |
| ~20k | ~200k | ~28 min | ≈ the reference site |
| ~50k | ~500k | ~70 min | large site (default target) |
| ~1M | ~10M | ~23 h (background) | very large site |

`--limit` is **per entity type**, so total entities ≈ `limit ×` the number of
seeded content types plus users/groups. Pick `--limit` from the target column and
run the seed in the background (`bin/seed.sh` does this and polls counts).

### Reaching *very large* without waiting hours

Two supported paths, both documented in `SKILL.md`:

1. **Long native seed** — the authentic route; run `bin/seed.sh` to a high
   `--limit` in the background (hours) and benchmark when it reaches the target.
2. **Native shape + formula bulk** — native-seed a realistic slice (minutes) to
   fix the production shape, then let the **SQL layer** formula-multiply the
   `metadata` table to millions of rows for the index micro-benchmark. Use this
   when you only need the `(entity_guid, name)` table volume, not full entities.

> Numbers here are the reference snapshot + measured throughput at capture time;
> re-run `bin/capture-profile.sh <container> <db>` against any site to refresh.
