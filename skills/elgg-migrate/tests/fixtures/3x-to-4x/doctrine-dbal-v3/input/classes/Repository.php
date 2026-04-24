<?php

use Elgg\Database\QueryBuilder;

class Repository {

    public function findOne(QueryBuilder $qb): ?object {
        $row = $qb->fetch();
        return $row ? (object) $row : null;
    }

    public function findAll(QueryBuilder $qb): array {
        return $qb->fetchAll();
    }

    public function getFirstColumn(QueryBuilder $qb): mixed {
        return $qb->fetchColumn();
    }

    public function getCount(QueryBuilder $qb): int {
        // This should also be flagged — uses fetchColumn which is renamed
        return (int) $qb->fetchColumn(0);
    }
}
