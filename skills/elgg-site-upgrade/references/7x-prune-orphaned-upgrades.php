<?php
/**
 * Prune ElggUpgrade entities that can never run, so `elgg-cli upgrade all` can
 * finish and /admin/upgrades stops crying wolf.
 *
 * Two kinds of orphan accumulate across a 2.x -> 7.x chain:
 *
 *  1. DEAD CLASS. The upgrade entity is created during an early hop, but the
 *     class was later deleted from Elgg upstream (e.g.
 *     \Elgg\Pages\Upgrades\MigratePageTop, \Elgg\Upgrades\MigrateFriendsACL).
 *     UpgradeService::getPendingUpgrades() silently drops an upgrade whose batch
 *     cannot be instantiated, so the entity sits "pending" forever — and its work
 *     never happens. Anything those upgrades were supposed to do must be done by
 *     hand; see 7x-migrate-page-top.sql for the one that mattered here.
 *
 *  2. CAMELCASE TWIN. Elgg 4 lowercased plugin ids, and an upgrade's identity is
 *     "{plugin_id}:{version}". The old entity (hypeMaps:2026041200) is orphaned
 *     while a fresh lowercase one (hypemaps:2026041200) is created and completed.
 *     Same family as the stranded plugin settings — see
 *     4x-post-lowercase-plugin-settings.sql.
 *
 * Run inside the Elgg container:  php 7x-prune-orphaned-upgrades.php [--dry-run]
 */

require '/var/www/html/vendor/autoload.php';
\Elgg\Application::getInstance()->bootCore();

$dry_run = in_array('--dry-run', $argv, true);

elgg_call(ELGG_IGNORE_ACCESS | ELGG_SHOW_DISABLED_ENTITIES, function () use ($dry_run) {
	$upgrades = elgg_get_entities(['type' => 'object', 'subtype' => 'elgg_upgrade', 'limit' => 0]);

	// Which classes already have a COMPLETED entity?
	$completed_classes = [];
	foreach ($upgrades as $u) {
		if ($u->isCompleted()) {
			$completed_classes[ltrim((string) $u->class, '\\')] = true;
		}
	}

	$dead = 0;
	$twins = 0;
	$kept = [];

	foreach ($upgrades as $u) {
		if ($u->isCompleted()) {
			continue;
		}

		$class = ltrim((string) $u->class, '\\');

		if ($class === '' || !class_exists($class)) {
			printf("DEAD-CLASS  %-58s guid=%d\n", $class ?: '(no class)', $u->guid);
			$dry_run || $u->delete();
			$dead++;
			continue;
		}

		if (isset($completed_classes[$class])) {
			printf("CAMEL-TWIN  %-58s guid=%d (id=%s)\n", $class, $u->guid, $u->id);
			$dry_run || $u->delete();
			$twins++;
			continue;
		}

		$kept[] = sprintf('%s (guid %d, id %s)', $class, $u->guid, $u->id);
	}

	printf("\ndead-class=%d camelcase-twin=%d still-pending=%d%s\n",
		$dead, $twins, count($kept), $dry_run ? ' [DRY RUN — nothing deleted]' : '');

	foreach ($kept as $k) {
		printf("  STILL PENDING (runnable): %s\n", $k);
	}
});
