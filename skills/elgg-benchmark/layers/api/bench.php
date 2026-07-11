<?php
/**
 * API-layer benchmark — runs the most-used Elgg query shapes through the public
 * API (elgg_get_entities / elgg_get_metadata / elgg_count_entities / ...) against
 * a natively-seeded site and reports, per shape:
 *   - Handler_read_next / _key / _rnd_next : rows the engine walked (deterministic)
 *   - median wall-clock over N iterations   : with caches cleared each run
 *
 * Run it from an installed Elgg root (the same way elgg-cli boots):
 *   php bench.php [iterations] > results.json
 *
 * Emits one JSON object to stdout. Run once before and once after the change under
 * test (report.php diffs the two files).
 */

$root = getcwd();
require_once $root . '/vendor/autoload.php';

$app = \Elgg\Application::getInstance();
$app->start();

$iterations = (int) ($argv[1] ?? 30);
if ($iterations < 1) {
	$iterations = 30;
}

$svc = _elgg_services();
$read = $svc->db->getConnection('read');

/** Force fresh SQL: clear every layer that could serve a cached result. */
$clear_caches = function () use ($svc) {
	$svc->queryCache->clear();
	$svc->metadataCache->clear();
	$svc->entityCache->clear();
	$svc->accessCache->clear();
};

$flush_status = function () use ($read) {
	$read->executeStatement('FLUSH STATUS');
};

$handler_counts = function () use ($read) {
	$rows = $read->executeQuery("SHOW SESSION STATUS LIKE 'Handler_read%'")->fetchAllAssociative();
	$out = [];
	foreach ($rows as $r) {
		$out[$r['Variable_name']] = (int) $r['Value'];
	}
	return $out;
};

/**
 * Sum of actual SQL execution time (ms) for whatever ran since the last reset,
 * from performance_schema — this is DB query duration, isolated from the PHP
 * framework overhead that the wall-clock also carries. TIMER_WAIT is picoseconds.
 */
$reset_db_timer = function () use ($read) {
	$read->executeStatement('TRUNCATE performance_schema.events_statements_summary_by_digest');
};
$db_time_ms = function () use ($read) {
	$pico = $read->executeQuery(
		'SELECT COALESCE(SUM(SUM_TIMER_WAIT), 0) FROM performance_schema.events_statements_summary_by_digest'
	)->fetchOne();
	return (float) $pico / 1e9;
};

/** Measure one shape: Handler snapshot + SQL query duration + wall-clock median. */
$measure = function (callable $fn) use ($iterations, $clear_caches, $flush_status, $handler_counts, $reset_db_timer, $db_time_ms) {
	// deterministic snapshot on a single clean run
	$clear_caches();
	$flush_status();
	$result = $fn();
	$h = $handler_counts();

	// actual SQL time: run the shape's queries R times and average (cache cleared
	// each rep so real SQL executes). Cache clears are in-memory and issue no SQL,
	// so only the shape's statements land in the digest window.
	$reps = 10;
	$clear_caches();
	$reset_db_timer();
	for ($i = 0; $i < $reps; $i++) {
		$clear_caches();
		$fn();
	}
	$db_ms = $db_time_ms() / $reps;

	// warm up, then time N clean iterations (full elgg_get_* wall-clock)
	for ($i = 0; $i < 3; $i++) {
		$clear_caches();
		$fn();
	}
	$times = [];
	for ($i = 0; $i < $iterations; $i++) {
		$clear_caches();
		$t = hrtime(true);
		$fn();
		$times[] = (hrtime(true) - $t) / 1e6; // ms
	}
	sort($times);
	$median = $times[intdiv(count($times), 2)];
	$p95 = $times[min(count($times) - 1, (int) ceil(0.95 * count($times)) - 1)];

	return [
		'rows' => is_array($result) ? count($result) : $result,
		'handler_read_next' => $h['Handler_read_next'] ?? null,
		'handler_read_key' => $h['Handler_read_key'] ?? null,
		'handler_read_rnd_next' => $h['Handler_read_rnd_next'] ?? null,
		'db_ms' => round($db_ms, 3),
		'median_ms' => round($median, 3),
		'p95_ms' => round($p95, 3),
	];
};

// ---------------------------------------------------------------------------
// Discover concrete seeded values so shapes hit real data of the right shape.
// ---------------------------------------------------------------------------
$discover = function () use ($svc, $read) {
	$prefix = $svc->config->dbprefix;
	return elgg_call(ELGG_IGNORE_ACCESS, function () use ($read, $prefix) {
		// most populous object subtype
		$subtype = $read->executeQuery(
			"SELECT subtype FROM {$prefix}entities WHERE type = 'object' AND subtype IS NOT NULL
			 GROUP BY subtype ORDER BY COUNT(*) DESC LIMIT 1"
		)->fetchOne();

		// an owner with the most objects of that subtype
		$owner_guid = $read->executeQuery(
			"SELECT owner_guid FROM {$prefix}entities WHERE type='object' AND subtype = ?
			 GROUP BY owner_guid ORDER BY COUNT(*) DESC LIMIT 1",
			[$subtype]
		)->fetchOne();

		// the most common metadata (name,value) carried by objects of that subtype
		$md = $read->executeQuery(
			"SELECT m.name, m.value FROM {$prefix}metadata m
			 INNER JOIN {$prefix}entities e ON e.guid = m.entity_guid
			 WHERE e.type='object' AND e.subtype = ?
			 GROUP BY m.name, m.value ORDER BY COUNT(*) DESC LIMIT 1",
			[$subtype]
		)->fetchAssociative();

		// an entity that carries several metadata rows, and a user
		$entity_guid = (int) $read->executeQuery(
			"SELECT entity_guid FROM {$prefix}metadata GROUP BY entity_guid ORDER BY COUNT(*) DESC LIMIT 1"
		)->fetchOne();
		$user_guid = (int) $read->executeQuery(
			"SELECT guid FROM {$prefix}entities WHERE type='user' LIMIT 1"
		)->fetchOne();
		$some_guids = array_map('intval', $read->executeQuery(
			"SELECT guid FROM {$prefix}entities WHERE type='object' LIMIT 20"
		)->fetchFirstColumn());

		return [
			'subtype' => $subtype,
			'owner_guid' => (int) $owner_guid,
			'md_name' => $md['name'] ?? 'title',
			'md_value' => $md['value'] ?? '',
			'entity_guid' => $entity_guid,
			'user_guid' => $user_guid,
			'guids' => $some_guids,
		];
	});
};

$d = $discover();

// Run everything as a normal logged-in user so the access WHERE clauses are
// exercised (realistic), not bypassed.
if ($d['user_guid']) {
	$svc->session_manager->setLoggedInUser(get_entity($d['user_guid']));
}

// ---------------------------------------------------------------------------
// The shapes (head of the catalog; `md` flags the (entity_guid, name) hitters).
// ---------------------------------------------------------------------------
$shapes = [
	['id' => 'entities:type+subtype', 'md' => false, 'fn' => fn() => elgg_get_entities([
		'type' => 'object', 'subtype' => $d['subtype'], 'limit' => 20,
	])],
	['id' => 'entities:type=user', 'md' => false, 'fn' => fn() => elgg_get_entities([
		'type' => 'user', 'limit' => 20,
	])],
	['id' => 'entities:guids', 'md' => false, 'fn' => fn() => elgg_get_entities([
		'guids' => $d['guids'], 'limit' => false,
	])],
	['id' => 'entities:type+subtype+owner', 'md' => false, 'fn' => fn() => elgg_get_entities([
		'type' => 'object', 'subtype' => $d['subtype'], 'owner_guid' => $d['owner_guid'], 'limit' => 20,
	])],
	['id' => 'count_entities:type+subtype', 'md' => false, 'fn' => fn() => elgg_count_entities([
		'type' => 'object', 'subtype' => $d['subtype'],
	])],
	['id' => 'entities:type+subtype+md_name', 'md' => true, 'fn' => fn() => elgg_get_entities([
		'type' => 'object', 'subtype' => $d['subtype'], 'metadata_name' => $d['md_name'], 'limit' => 20,
	])],
	['id' => 'entities:type+subtype+md_nvp', 'md' => true, 'fn' => fn() => elgg_get_entities([
		'type' => 'object', 'subtype' => $d['subtype'],
		'metadata_name_value_pairs' => ['name' => $d['md_name'], 'value' => $d['md_value']],
		'limit' => 20,
	])],
	['id' => 'entities:type+subtype+owner+md_nvp', 'md' => true, 'fn' => fn() => elgg_get_entities([
		'type' => 'object', 'subtype' => $d['subtype'], 'owner_guid' => $d['owner_guid'],
		'metadata_name_value_pairs' => ['name' => $d['md_name'], 'value' => $d['md_value']],
		'limit' => 20,
	])],
	['id' => 'count_entities:type+subtype+md_nvp', 'md' => true, 'fn' => fn() => elgg_count_entities([
		'type' => 'object', 'subtype' => $d['subtype'],
		'metadata_name_value_pairs' => ['name' => $d['md_name'], 'value' => $d['md_value']],
	])],
	['id' => 'metadata:guid+md_name', 'md' => true, 'fn' => fn() => elgg_get_metadata([
		'guid' => $d['entity_guid'], 'metadata_name' => $d['md_name'],
	])],
	['id' => 'metadata:guid', 'md' => true, 'fn' => fn() => elgg_get_metadata([
		'guid' => $d['entity_guid'], 'limit' => false,
	])],
];

$results = [];
foreach ($shapes as $shape) {
	$results[] = ['id' => $shape['id'], 'md' => $shape['md']] + $measure($shape['fn']);
}

echo json_encode([
	'iterations' => $iterations,
	'discovered' => $d,
	'server' => $read->executeQuery('SELECT VERSION()')->fetchOne(),
	'shapes' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
