<?php

namespace MyPlugin;

use Elgg\Database;
use Elgg\Database as ElggDb;
use Elgg\Database\QueryBuilder; // should NOT be renamed

class MyClass {
    public function __construct(
        private \Elgg\Database $db,
    ) {}
}
