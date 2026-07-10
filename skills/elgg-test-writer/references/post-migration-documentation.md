# Post-Migration Plugin Documentation

After each version migration, an `ARCHITECTURE.md` file MUST be added or updated in the plugin root. This documents the plugin's current state for future migrations and developer onboarding.

## Why Document Per-Version

1. **Future migration context**: The next migration agent can read the current state without re-discovering it
2. **Drift detection**: Compare documented state vs actual state to find regressions
3. **Onboarding**: New developers see what the plugin does without reading every file
4. **Audit trail**: What changed in each version step is captured in version control

## Required Sections

### 1. Plugin Summary

```markdown
## Summary

**Name**: hypeWall
**Version**: Migrated to Elgg 4.x on 2026-04-12 (from 3.x)
**Purpose**: Activity stream and wall posts for Elgg sites

This plugin provides a Facebook-style wall feature where users can post status
updates, share content, and interact with each other's posts.
```

### 2. Directory Structure

```markdown
## Directory Structure

\`\`\`
hypewall/
├── elgg-plugin.php          # Main configuration
├── composer.json            # Plugin metadata
├── lib/
│   └── functions.php       # Helper functions
├── classes/
│   └── HypeWall/
│       ├── Bootstrap.php
│       ├── Entity/
│       │   └── Post.php
│       ├── Menus/
│       │   ├── Entity.php
│       │   └── Owner.php
│       └── Notifications/
│           └── PublishHandler.php
├── actions/
│   └── wall/
│       ├── save.php
│       └── delete.php
├── views/
│   └── default/
│       ├── object/
│       │   └── hjwall.php
│       └── resources/
│           └── wall/
└── languages/
    └── en.php
\`\`\`
```

### 3. Registered Entities

```markdown
## Entities

| Type | Subtype | Class | Capabilities |
|------|---------|-------|--------------|
| object | hjwall | `HypeWall\Entity\Post` | commentable, likable, searchable |
```

### 4. Routes

```markdown
## Routes

| Name | Path | Handler |
|------|------|---------|
| `collection:object:hjwall:all` | `/wall/all` | `wall/all` resource view |
| `collection:object:hjwall:owner` | `/wall/owner/{username}` | `wall/owner` resource view |
| `view:object:hjwall` | `/wall/view/{guid}` | `wall/view` resource view |
```

### 5. Hooks (4.x) / Events (5.x+)

```markdown
## Hooks (4.x format)

| Event | Type | Handler | Purpose |
|-------|------|---------|---------|
| `register` | `menu:entity` | `HypeWall\Menus\Entity::register` | Add edit/delete to wall posts |
| `register` | `menu:owner_block` | `HypeWall\Menus\Owner::register` | Add wall link to user block |
| `permissions_check` | `object` | `HypeWall\Hooks\Permissions::check` | Wall post edit permissions |

## Events

| Event | Type | Handler | Purpose |
|-------|------|---------|---------|
| `create` | `object` | `HypeWall\Events\Lifecycle::onCreate` | Send notifications on new post |
| `delete` | `object` | `HypeWall\Events\Lifecycle::onDelete` | Cleanup related entities |
```

### 6. Actions

```markdown
## Actions

| Action | File | Permission | Purpose |
|--------|------|------------|---------|
| `wall/save` | `actions/wall/save.php` | logged_in | Create or update a wall post |
| `wall/delete` | `actions/wall/delete.php` | logged_in | Delete a wall post (owner only) |
```

### 7. Views

```markdown
## Key Views

| View | Purpose |
|------|---------|
| `object/hjwall` | Renders a wall post in lists |
| `object/hjwall/full` | Full view of a wall post |
| `forms/wall/save` | Form for creating/editing wall posts |
| `resources/wall/all` | All wall posts page |
| `resources/wall/owner` | Owner's wall page |

## View Extensions

| Extends | Adds | Purpose |
|---------|------|---------|
| `page/elements/topbar` | `wall/topbar_link` | Wall icon in topbar |
| `groups/tool_latest` | `wall/group_widget` | Show recent posts in group |
```

### 8. Dependencies

```markdown
## Dependencies

### Required Plugins
- `hypeApps` (>= 4.0) — provides menu service
- `hypeUI` (>= 4.0) — UI components

### Composer Dependencies
- `elgg/elgg ^4.3`
- `league/commonmark ^2.0` — Markdown rendering
```

### 9. Migration Notes

```markdown
## Migration Notes

### Migrated from 3.x to 4.x on 2026-04-12

**Changes applied:**
- Removed `start.php`, `manifest.xml`, `activate.php`
- Generated `elgg-plugin.php` from registrations in `start.php`
- Moved schema setup from `activate.php` to `Bootstrap::activate()`
- Split monolithic `Hooks.php` into `Menus/Entity.php`, `Menus/Owner.php`, `Hooks/Permissions.php`
- Updated all hook callbacks to single-arg signature: `function(\Elgg\Hook $hook)`
- Renamed `Zend\Mail` → `Laminas\Mail` in notification handler
- Added `'capabilities'` to entity registration (replaces individual hook registrations)

**Manual fixes required:**
- Post-migration verifier flagged `elgg_set_plugin_setting()` in `actions/wall/admin.php` — replaced with `$plugin->setSetting()`
- Security sweep flagged `md5()` usage in `lib/functions.php` for filename hashing — kept (non-cryptographic use, documented)

**Known issues:**
- The `wall/group_widget` view extension uses a deprecated parameter format that should be updated for 5.x

**Test results:**
- PHPUnit: 23/23 passing
- Playwright: 8/8 passing
- Docker activation: ✓
- Site render: ✓
```

### 10. Future Migration Hints

```markdown
## For Future Migrations

### 4.x → 5.x checklist
- [ ] Rename `'hooks'` key → `'events'` key
- [ ] Change `\Elgg\Hook` → `\Elgg\Event` in `HypeWall\Menus\*`, `HypeWall\Hooks\*`
- [ ] Rename `HypeWall\Hooks\` directory → `HypeWall\Events\`
- [ ] Add `UserPageOwnerGatekeeper` middleware to owner routes
- [ ] Migrate any remaining private settings to metadata (none currently)
- [ ] Convert `prepare_form_vars()` → `PrepareFields` class

### 5.x → 6.x checklist
- [ ] Convert `views/default/wall/init.js` from AMD to ES module
- [ ] Replace `elgg_define_js()` → `elgg_register_esm()`
- [ ] Add `'restorable' => true` to entity capabilities (enables soft-delete)
```

## Documentation Generation Strategy

The agent should generate `ARCHITECTURE.md` by:

1. **Parsing `elgg-plugin.php`** — extract entities, routes, hooks, events, actions, views
2. **Scanning `classes/`** — identify Bootstrap, menu handlers, event handlers
3. **Reading `composer.json`** — extract dependencies
4. **Walking `views/default/`** — identify resource views and view extensions
5. **Reading git log** — extract recent migration commits to populate "Migration Notes"
6. **Comparing with previous `ARCHITECTURE.md`** — identify what changed in this version step

The output should be readable Markdown, not just data dumps. Use tables for structured data, prose for context.

## Update Cadence

`ARCHITECTURE.md` is updated:
- After every successful migration step (Phase 2.8)
- When new entities, routes, or major features are added
- When dependencies change
- Never as part of the same commit as code changes — always a separate `docs:` commit

## Validation

The migration toolkit could include a `--docs-check` flag (future enhancement) that:
- Verifies `ARCHITECTURE.md` exists
- Confirms it documents the current `elgg-plugin.php` state
- Flags drift between documented and actual structure
