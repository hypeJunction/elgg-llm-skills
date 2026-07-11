# Elgg query-shape catalog

The API-layer benchmark drives the query shapes real Elgg code actually issues,
through the public API (`elgg_get_entities()`, `elgg_get_metadata()`,
`elgg_get_annotations()`, `elgg_get_river()`, …) rather than hand-written SQL.

This catalog was built by statically parsing every literal-options call site of
the getter functions across `engine/` (core) and `mod/` (bundled plugins):
**238 shape-bearing call sites → 92 distinct shapes**. Signatures use only the
planning-relevant keys (type/subtype/owner/container/guid, metadata &
annotation pairs, relationship, order_by/group_by/wheres, count/distinct);
`limit`/`offset`/access flags are dropped because they don't change the
join/where topology.

The head is sharply skewed: the **top 12 shapes cover 50%** of all call sites,
the **top 25 cover 78%**. The API harness runs the full ranked set but weights
its reporting toward the head.

## Family totals

| Function | shape-bearing call sites |
| --- | ---: |
| `elgg_get_entities` | 147 |
| `elgg_get_metadata` | 28 |
| `elgg_get_annotations` | 21 |
| `elgg_list_entities` | 21 |
| `elgg_count_entities` | 12 |
| `elgg_get_river` | 8 |
| `elgg_list_river` | 1 |

`elgg_list_entities` / `elgg_count_entities` resolve to the same
`EntityTable::getEntities()` engine as `elgg_get_entities`; `count=true` and
`distinct`/`group_by` are the variations that matter for planning.

## Metadata `(entity_guid, name)` hitters — the composite-index surface

**~108 of 238 sites (45%)** touch the metadata table by `(entity_guid, name)`:
80 entity-getter sites carry `metadata_name_value_pairs` / `metadata_name` /
`metadata_names`, and 26 of 28 `elgg_get_metadata` sites are scoped by
`entity_guid`/`guid` (± name). These are the shapes whose plan changes when the
`entity_guid_name` index exists. They are flagged `MD` in the table below.

## Top 25 shapes (78% of usage)

| # | N | MD | Function | Shape | Example options |
| --: | --: | :-: | --- | --- | --- |
| 1 | 19 | ✅ | get_entities | type+subtype+md_nvp(1) | `type=object, subtype=comment, metadata_name_value_pairs={name:parent_guid, value:$guid}` |
| 2 | 18 | | get_entities | type+subtype | `type=object, subtype=blog, limit=10` |
| 3 | 13 | | get_entities | relationship | `relationship=friend, relationship_guid=$guid, inverse_relationship=false` |
| 4 | 12 | | get_annotations | guid+ann_name | `guid=$guid, annotation_name=generic_comment` |
| 5 | 9 | ✅ | get_entities | type+subtype+md_name | `type=object, subtype=blog, metadata_name=status` |
| 6 | 8 | | get_entities | type_subtype_pairs | `type_subtype_pairs={object:[blog,file]}` |
| 7 | 8 | | get_entities | guid | `guids=[$g1,$g2,$g3]` |
| 8 | 8 | ✅ | get_metadata | entity_guid+md_name | `entity_guid=$guid, metadata_name=foo` |
| 9 | 7 | | get_entities | type | `type=user, limit=10` |
| 10 | 6 | ✅ | get_metadata | guid+md_name | `guid=$guid, metadata_name=foo` |
| 11 | 6 | | get_entities | type+group_by | `type=object, group_by=e.owner_guid` |
| 12 | 5 | ✅ | get_metadata | guid | `guid=$guid` |
| 13 | 4 | | get_river | action_type | `action_type=create, limit=20` |
| 14 | 4 | | list_entities | type+relationship+order_by | `type=user, relationship=friend, relationship_guid=$guid, order_by=[…]` |
| 15 | 3 | | get_entities | wheres | `wheres=[fn(qb,alias){…}]` |
| 16 | 3 | ✅ | get_entities | type+md_nvp(1) | `type=user, metadata_name_value_pairs={name:username, value:$u}` |
| 17 | 3 | ✅ | get_entities | type+md_names+order_by+wheres | `type=object, metadata_names=[x], order_by=[…], wheres=[…]` |
| 18 | 3 | ✅ | get_metadata | guid+count | `guid=$guid, count=true` |
| 19 | 3 | | get_entities | ann_name | `annotation_name=likes` |
| 20 | 3 | ✅ | get_entities | type+subtype+md_names | `type=object, subtype=blog, metadata_names=[status]` |
| 21 | 3 | ✅ | get_entities | type+subtype+md_values | `type=object, subtype=blog, metadata_values=[published]` |
| 22 | 3 | ✅ | get_entities | type+subtype+md_nvp(1)+order_by | `…metadata_name_value_pairs={…}, order_by=[…]` |
| 23 | 3 | | list_entities | type+relationship | `type=user, relationship=friend, relationship_guid=$guid` |
| 24 | 3 | ✅ | list_entities | type+subtype+md_nvp(1) | `type=object, subtype=reported_content, metadata_name_value_pairs={name:state, value:active}` |
| 25 | 2 | ✅ | get_entities | type+subtype+container_guid+md_nvp(1) | `type=object, subtype=widget, container_guid=$g, metadata_name_value_pairs={name:context, value:$c}` |

## Long tail (26–92)

Ranks 26–92 are almost all singletons, one plugin each. Metadata `(entity_guid,
name)` hitters in the tail: 26 (widget owner+context), 27, 31, 33, 37, 39, 41,
49, 55, 56, 67, 74, 75, 77, 83, 84, 88, 89. Non-metadata tail is dominated by
relationship, annotation, river, and `wheres`-closure shapes. The machine-usable
form of the whole ranked set lives in `layers/api/shapes.php`, which is what the
harness iterates.

## Building the runnable set

`layers/api/shapes.php` turns each shape into a concrete callable against seeded
data (resolving `$guid`/`$container`/`$owner` to real seeded GUIDs of the right
type/subtype). The harness runs each shape under `FLUSH STATUS` →
`SHOW SESSION STATUS LIKE 'Handler_read_%'`, records the plan-relevant counters
and wall-clock, and diffs before/after the change under test.
