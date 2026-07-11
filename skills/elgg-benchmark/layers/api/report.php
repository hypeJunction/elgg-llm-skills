<?php
/**
 * Diff two bench.php result files into a markdown before/after table.
 *
 *   php report.php before.json after.json
 *
 * Leads with the deterministic Handler_read_next delta; median/p95 wall-clock
 * follow as corroboration. Shapes that hit metadata by (entity_guid, name) are
 * flagged MD.
 */

[$self, $before_file, $after_file] = array_pad($argv, 3, null);
if (!$before_file || !$after_file) {
	fwrite(STDERR, "usage: php report.php before.json after.json\n");
	exit(1);
}

$before = json_decode(file_get_contents($before_file), true);
$after = json_decode(file_get_contents($after_file), true);

$by_id = function (array $run) {
	$out = [];
	foreach ($run['shapes'] as $s) {
		$out[$s['id']] = $s;
	}
	return $out;
};
$b = $by_id($before);
$a = $by_id($after);

$pct = function ($from, $to) {
	if ($from === null || $to === null || $from == 0) {
		return '—';
	}
	$p = ($to - $from) / $from * 100;
	return sprintf('%+.0f%%', $p);
};

$d = $before['discovered'];
echo "# API-layer benchmark — before/after `metadata (entity_guid, name)` index\n\n";
echo "Server: `{$before['server']}` · iterations: {$before['iterations']} · ";
echo "seeded (native): subtype `{$d['subtype']}`, metadata `{$d['md_name']}={$d['md_value']}`\n\n";

echo "Query duration = actual SQL time (performance_schema), isolated from PHP.\n\n";
echo "| shape | MD | rows | H.read_next | query ms (SQL) | Δ query | wall ms (PHP) |\n";
echo "|---|:--:|--:|--:|--:|--:|--:|\n";

foreach ($b as $id => $bs) {
	$as = $a[$id] ?? null;
	if (!$as) {
		continue;
	}
	$md = $bs['md'] ? '✅' : '';
	$hn = "{$bs['handler_read_next']} → {$as['handler_read_next']}";
	$db = sprintf('%.2f → %.2f', $bs['db_ms'], $as['db_ms']);
	$db_delta = $pct($bs['db_ms'], $as['db_ms']);
	$wall = sprintf('%.2f → %.2f', $bs['median_ms'], $as['median_ms']);
	echo "| `{$id}` | {$md} | {$bs['rows']} | {$hn} | {$db} | {$db_delta} | {$wall} |\n";
}

// aggregate over the MD shapes (the ones the index targets)
$sum = function (array $run, $key, $md_only) {
	$t = 0.0;
	foreach ($run as $s) {
		if ($md_only && !$s['md']) {
			continue;
		}
		$t += $s[$key];
	}
	return $t;
};
$b_md = $sum($b, 'db_ms', true);
$a_md = $sum($a, 'db_ms', true);
$b_hn = $sum($b, 'handler_read_next', true);
$a_hn = $sum($a, 'handler_read_next', true);

echo "\n**Metadata-hitting shapes (MD) combined:** ";
echo "Handler_read_next " . (int) $b_hn . " → " . (int) $a_hn . " (" . $pct($b_hn, $a_hn) . "), ";
echo sprintf("SQL query time %.2f ms → %.2f ms (%s).\n", $b_md, $a_md, $pct($b_md, $a_md));
