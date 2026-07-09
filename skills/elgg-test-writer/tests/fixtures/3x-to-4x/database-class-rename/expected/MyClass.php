<?php

namespace MyPlugin;

use Elgg\Application\Database;
use Elgg\Application\Database as ElggDb;
use Elgg\Database\QueryBuilder; // should NOT be renamed

class MyClass {
    public function __construct(
        private \Elgg\Application\Database $db,
    ) {}
}
