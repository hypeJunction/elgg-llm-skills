# API-layer benchmark — before/after `metadata (entity_guid, name)` index

Server: `8.0.46` · iterations: 50 · seeded (native): subtype `comment`, metadata `level=1`

Query duration = actual SQL time (performance_schema), isolated from PHP.

| shape | MD | rows | H.read_next | query ms (SQL) | Δ query | wall ms (PHP) |
|---|:--:|--:|--:|--:|--:|--:|
| `entities:type+subtype` |  | 20 | 54 → 54 | 3.57 → 2.43 | -32% | 3.79 → 3.61 |
| `entities:type=user` |  | 20 | 1391 → 1391 | 1.97 → 2.02 | +2% | 4.29 → 4.71 |
| `entities:guids` |  | 20 | 345 → 345 | 0.96 → 1.76 | +83% | 2.55 → 2.48 |
| `entities:type+subtype+owner` |  | 20 | 112 → 112 | 0.81 → 0.81 | -0% | 1.77 → 1.59 |
| `count_entities:type+subtype` |  | 6294 | 6308 → 6308 | 5.91 → 5.61 | -5% | 6.18 → 5.64 |
| `entities:type+subtype+md_name` | ✅ | 20 | 6348 → 54 | 15.35 → 2.52 | -84% | 17.03 → 4.01 |
| `entities:type+subtype+md_nvp` | ✅ | 20 | 6348 → 54 | 15.39 → 2.64 | -83% | 17.25 → 4.52 |
| `entities:type+subtype+owner+md_nvp` | ✅ | 20 | 112 → 112 | 0.84 → 1.34 | +59% | 1.88 → 2.63 |
| `count_entities:type+subtype+md_nvp` | ✅ | 6294 | 6308 → 12602 | 10.74 → 17.38 | +62% | 10.37 → 19.93 |
| `metadata:guid+md_name` | ✅ | 0 | 59 → 14 | 0.38 → 0.37 | -3% | 0.40 → 0.76 |
| `metadata:guid` | ✅ | 45 | 59 → 59 | 0.51 → 0.38 | -26% | 0.69 → 0.59 |

**Metadata-hitting shapes (MD) combined:** Handler_read_next 19234 → 12895 (-33%), SQL query time 43.20 ms → 24.63 ms (-43%).

## Notes

- Site: natively seeded via `elgg-cli database:seed` — 11,102 entities /
  68,565 metadata (~6 metadata rows/entity). Only the index differs between runs.
- The seeder's discovered metadata value here is `level=1`, which every comment
  carries (a worst-case *non-selective* value). That is why:
  - the **row-fetch** shapes (`md_name`, `md_nvp`, `LIMIT 20`) win hugely — the
    join goes from scanning ~6,300 rows to a 54-row seek (−83/−84% SQL time);
  - the **count** shape regresses — it must count all ~6,300 matches regardless,
    and with the composite present the optimizer picks a heavier plan for this
    all-matching value. For a *selective* value (the real hot path: `parent_guid`,
    `context`, `username` — a handful of matches) the composite helps counts too.
- Headline metric is `Handler_read_next` (deterministic); SQL query time
  (performance_schema) isolates DB work from PHP; wall-clock is the full
  `elgg_get_*()` cost.
