# Elgg Coding Standards

Elgg-style coding standards for migrated plugin code. The standards evolve with each major version. Migrated plugins MUST conform to the standards for their target version.

## Base Standard

All Elgg code follows **PSR-12** (Extended Coding Style) as the foundation, with Elgg-specific extensions documented below.

## Universal Rules (all versions)

### Indentation and Formatting

- **Tabs for indentation**, spaces for alignment (Elgg core convention)
- Line length: soft limit 120 chars, hard limit 200
- One blank line between methods
- Two blank lines between class declarations in the same file (rare — prefer one class per file)
- No trailing whitespace
- Files end with a single newline

### Naming Conventions

| Item | Convention | Example |
|------|------------|---------|
| Classes | PascalCase | `BlogEntity`, `UserMenu` |
| Methods | camelCase | `getName()`, `setOwnerGuid()` |
| Properties | snake_case (legacy) or camelCase (modern) | `$owner_guid`, `$createdAt` |
| Constants | UPPER_SNAKE_CASE | `ACCESS_PUBLIC`, `MAX_TITLE_LENGTH` |
| Functions (procedural) | snake_case with prefix | `myplugin_get_entity()` |
| Hooks/events | colon-separated | `'permissions_check:object'` |
| Translation keys | colon-separated | `'item:object:blog'` |
| Files | match class name | `BlogEntity.php` |

### Documentation

- All public methods MUST have PHPDoc
- Use `@param`, `@return`, `@throws` tags
- One-line summary, blank line, longer description if needed
- No `@author` tags (use git blame)
- No `@since` tags except in core

```php
/**
 * Get the entity's display name.
 *
 * Falls back to the title if no name is set.
 *
 * @return string
 */
public function getDisplayName(): string {
    // ...
}
```

## Version-Specific Rules

### Elgg 3.x

- PSR-2 base style
- Type hints **optional** but recommended
- Return type hints **optional**
- Property type hints **NOT supported** (PHP 7.2 minimum)
- Use `array` type hint where applicable
- Visibility keywords required on all properties and methods (`public`, `protected`, `private`)

```php
class BlogEntity extends \ElggObject {
    public function getTitle() {
        return $this->title;
    }

    public function setOwner($guid) {
        $this->owner_guid = (int) $guid;
    }
}
```

### Elgg 4.x

- PSR-12 base style
- `declare(strict_types=1);` REQUIRED at top of all class files
- Return type hints REQUIRED on all methods
- Property type hints RECOMMENDED (PHP 7.4 minimum)
- Constructor property promotion AVAILABLE (PHP 8.0+) but not required
- Nullable types use `?Type` syntax

```php
<?php

declare(strict_types=1);

namespace MyPlugin;

class BlogEntity extends \ElggObject
{
    public function getTitle(): string
    {
        return (string) $this->title;
    }

    public function setOwner(int $guid): void
    {
        $this->owner_guid = $guid;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        $time = $this->published_at;
        return $time ? new \DateTimeImmutable("@{$time}") : null;
    }
}
```

### Elgg 5.x

Everything from 4.x plus:

- Constructor property promotion PREFERRED for value objects
- Union types AVAILABLE (PHP 8.0+)
- Named arguments where they improve readability
- Match expressions over switch statements where applicable
- First-class callable syntax: `$fn = $object->method(...)`

```php
<?php

declare(strict_types=1);

namespace MyPlugin\Events;

use Elgg\Event;

final class EntityLifecycle
{
    public function __construct(
        private readonly \Elgg\Logger $logger,
    ) {}

    public function onCreate(Event $event): bool
    {
        $entity = $event->getObject();

        $action = match($entity->getSubtype()) {
            'blog' => 'published',
            'comment' => 'commented',
            default => 'created',
        };

        $this->logger->info("{$action}: {$entity->guid}");
        return true;
    }
}
```

### Elgg 6.x

Everything from 5.x plus:

- Readonly properties for value objects
- Enums for fixed value sets (PHP 8.1+)
- `never` return type for functions that always throw
- First-class enums in match expressions

```php
<?php

declare(strict_types=1);

namespace MyPlugin;

enum BlogStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}

final readonly class BlogState
{
    public function __construct(
        public BlogStatus $status,
        public \DateTimeImmutable $updatedAt,
    ) {}
}
```

## Elgg-Specific Conventions

### File Structure

```php
<?php

declare(strict_types=1);  // 4.x+

namespace MyPlugin\Hooks;  // PSR-4 namespace matching directory

use Elgg\Hook;  // Use statements alphabetized
use ElggEntity;

/**
 * Class-level docblock describing purpose.
 */
final class Permissions
{
    // Constants first
    private const MAX_TITLE_LENGTH = 200;

    // Properties second
    private \Elgg\Logger $logger;

    // Constructor third
    public function __construct(\Elgg\Logger $logger)
    {
        $this->logger = $logger;
    }

    // Public methods fourth
    public static function canEdit(Hook $hook): bool
    {
        // ...
    }

    // Protected methods
    // Private methods last
}
```

### Hook/Event Handler Conventions

- Handlers MUST be `public static` (called by reference in elgg-plugin.php)
- Handler methods named after the action: `register`, `prepare`, `onCreate`, `canEdit`
- Single-argument signature: `function(\Elgg\Hook $hook)` (4.x) or `function(\Elgg\Event $event)` (5.x+)
- Always typed return values

```php
public static function register(\Elgg\Hook $hook): array
{
    $return = $hook->getValue();
    $entity = $hook->getEntityParam();

    if (!$entity instanceof BlogEntity) {
        return $return;
    }

    $return[] = \ElggMenuItem::factory([
        'name' => 'edit',
        'href' => $entity->getURL() . '/edit',
        'text' => elgg_echo('edit'),
    ]);

    return $return;
}
```

### Action Files

- Located in `actions/<plugin>/<action>.php`
- 4.x+: MUST return a response object (`elgg_ok_response()`, `elgg_error_response()`)
- 4.x+: NEVER use `forward()`, `register_error()`, `system_message()`
- Validate input at the top, perform action, return response

```php
<?php
// actions/myplugin/save.php

declare(strict_types=1);

$title = elgg_get_input('title');
$description = elgg_get_input('description');

if (empty($title)) {
    return elgg_error_response(elgg_echo('myplugin:title_required'), '', 422);
}

$entity = new \MyPlugin\Entity();
$entity->title = $title;
$entity->description = $description;

if (!$entity->save()) {
    return elgg_error_response(elgg_echo('myplugin:save_failed'));
}

return elgg_ok_response([
    'guid' => $entity->guid,
    'url' => $entity->getURL(),
], elgg_echo('myplugin:saved'));
```

### View Files

- Located in `views/default/<plugin>/<view>.php`
- ALWAYS escape user data: use `elgg_format_element()`, `elgg_view()`, or `htmlspecialchars()`
- NEVER `echo $vars['title']` directly without escaping
- Use `elgg_echo()` for translations
- Resource views (`views/default/resources/`) for full pages (4.x+)

```php
<?php
// views/default/myplugin/item.php

$entity = elgg_extract('entity', $vars);
if (!$entity instanceof \MyPlugin\Entity) {
    return;
}

echo elgg_view('object/elements/full', [
    'entity' => $entity,
    'title' => $entity->getDisplayName(),
    'summary' => elgg_view('object/elements/summary', ['entity' => $entity]),
]);
```

## Linting & Auto-Fixing

### Setup PHP_CodeSniffer for Elgg

Elgg core ships its own PHPCS ruleset. Plugins should reference it:

```bash
# Install dev dependencies in plugin
composer require --dev squizlabs/php_codesniffer

# Run against plugin source
vendor/bin/phpcs --standard=PSR12 classes/ actions/ views/

# Auto-fix what's auto-fixable
vendor/bin/phpcbf --standard=PSR12 classes/ actions/ views/
```

### Recommended `.phpcs.xml` for plugins

```xml
<?xml version="1.0"?>
<ruleset name="MyPlugin">
    <description>Coding standard for MyPlugin (Elgg 4.x)</description>

    <file>classes</file>
    <file>actions</file>
    <file>lib</file>

    <exclude-pattern>vendor/*</exclude-pattern>
    <exclude-pattern>tests/*</exclude-pattern>

    <rule ref="PSR12"/>

    <!-- Elgg-specific overrides -->
    <rule ref="Generic.WhiteSpace.DisallowSpaceIndent"/>
    <rule ref="Generic.WhiteSpace.ScopeIndent">
        <properties>
            <property name="indent" value="4"/>
            <property name="tabIndent" value="true"/>
        </properties>
    </rule>

    <!-- Require strict types in 4.x+ -->
    <rule ref="Generic.PHP.RequireStrictTypes"/>
</ruleset>
```

### Integration with Migration Workflow

After each migration step, run linting:

```bash
# Inside Docker
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  vendor/bin/phpcs --standard=mod/<plugin-id>/.phpcs.xml mod/<plugin-id>/

# Auto-fix
docker compose -f docker/elgg{N}/docker-compose.yml exec elgg \
  vendor/bin/phpcbf --standard=mod/<plugin-id>/.phpcs.xml mod/<plugin-id>/

# Commit style fixes separately from logic changes
cd <plugin-path>
git add -A
git commit -m "style: PSR-12 compliance for Elgg {TARGET}.x"
```

## Pre-Migration Style Baseline

Before starting a migration, capture the current style state:

```bash
# Generate baseline report
vendor/bin/phpcs --standard=PSR12 classes/ actions/ > /tmp/style-baseline.txt

# After migration, compare
vendor/bin/phpcs --standard=PSR12 classes/ actions/ > /tmp/style-after.txt
diff /tmp/style-baseline.txt /tmp/style-after.txt
```

Migration should improve or maintain style compliance — never regress it.
