#!/usr/bin/env bash
#
# gen-render-smoke.sh <plugin-dir>
#
# Stamps the render-smoke PHPUnit integration test into <plugin-dir>/tests/.
# The test asserts every route the plugin registers (read from its own
# elgg-plugin.php via ElggPlugin::getStaticConfig('routes')) answers an HTTP
# status < 500 — anonymous and authenticated — against a running Elgg stack.
#
# Idempotent:
#   - tests/phpunit/integration/RenderSmokeTest.php is (re)generated every run
#     (it is deterministic — plugin id is the only substitution).
#   - tests/phpunit.xml and tests/bootstrap.php are scaffolded ONLY if absent,
#     so an existing test harness is never clobbered.
#
# Run the stamped test (inside / against a running stack):
#   RENDER_SMOKE_USER=admin RENDER_SMOKE_PASS=... \
#   vendor/bin/phpunit --configuration mod/<plugin>/tests/phpunit.xml --no-coverage
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TPL="$SCRIPT_DIR/../templates/render-smoke.test.tpl"

die() { echo "gen-render-smoke: $*" >&2; exit 1; }

PLUGIN_DIR="${1:-}"
[ -n "$PLUGIN_DIR" ] || die "usage: gen-render-smoke.sh <plugin-dir>"
[ -d "$PLUGIN_DIR" ] || die "no such directory: $PLUGIN_DIR"
[ -f "$TPL" ] || die "template missing: $TPL"

PLUGIN_DIR="$(cd "$PLUGIN_DIR" && pwd)"
[ -f "$PLUGIN_DIR/elgg-plugin.php" ] || die "not an Elgg plugin (no elgg-plugin.php): $PLUGIN_DIR"

# Plugin id: prefer composer.json "name" basename, else the directory name.
# Always lowercased (Elgg 4+ matches plugin ids case-sensitively / lowercase).
PLUGIN_ID=""
if [ -f "$PLUGIN_DIR/composer.json" ]; then
	PLUGIN_ID="$(grep -oE '"name"[[:space:]]*:[[:space:]]*"[^"]+"' "$PLUGIN_DIR/composer.json" \
		| head -1 | sed -E 's/.*"name"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/' \
		| sed -E 's#.*/##')"
fi
[ -n "$PLUGIN_ID" ] || PLUGIN_ID="$(basename "$PLUGIN_DIR")"
PLUGIN_ID="$(printf '%s' "$PLUGIN_ID" | tr '[:upper:]' '[:lower:]')"

TESTS_DIR="$PLUGIN_DIR/tests"
INT_DIR="$TESTS_DIR/phpunit/integration"
mkdir -p "$INT_DIR"

# 1) Always (re)generate the render-smoke test — deterministic output.
OUT="$INT_DIR/RenderSmokeTest.php"
sed "s/__PLUGIN_ID__/${PLUGIN_ID}/g" "$TPL" > "$OUT"
echo "wrote  $OUT  (plugin id: $PLUGIN_ID)"

# 2) Scaffold phpunit.xml only if absent.
PHPUNIT_XML="$TESTS_DIR/phpunit.xml"
if [ ! -f "$PHPUNIT_XML" ]; then
	cat > "$PHPUNIT_XML" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="bootstrap.php" colors="true">
    <php>
        <env name="ELGG_DB_PREFIX" value="elgg_"/>
        <env name="ELGG_DB_HOST" value="db"/>
        <env name="ELGG_DB_NAME" value="elgg"/>
        <env name="ELGG_DB_USER" value="elgg"/>
        <env name="ELGG_DB_PASS" value="elgg"/>
    </php>
    <testsuites>
        <testsuite name="integration">
            <directory>phpunit/integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML
	echo "wrote  $PHPUNIT_XML  (scaffolded)"
else
	echo "kept   $PHPUNIT_XML  (already present)"
fi

# 3) Scaffold bootstrap.php only if absent.
BOOTSTRAP="$TESTS_DIR/bootstrap.php"
if [ ! -f "$BOOTSTRAP" ]; then
	sed "s/__PLUGIN_ID__/${PLUGIN_ID}/g" > "$BOOTSTRAP" <<'PHP'
<?php

$elggRoot = '/var/www/html';

require_once $elggRoot . '/vendor/autoload.php';

$testClassesDir = $elggRoot . '/vendor/elgg/elgg/engine/tests/classes';
spl_autoload_register(function ($class) use ($testClassesDir) {
    $file = $testClassesDir . '/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

\Elgg\Application::getInstance()->bootCore();

if (function_exists('_elgg_services')) {
    _elgg_services()->plugins->generateEntities();
    $boot_plugin = elgg_get_plugin_from_id('__PLUGIN_ID__');
    if ($boot_plugin) {
        if (!$boot_plugin->isEnabled()) {
            $boot_plugin->enable();
        }
        if (!$boot_plugin->isActive()) {
            try { $boot_plugin->activate(); } catch (\Throwable $e) {}
        }
        try {
            $boot_plugin->init();
        } catch (\Throwable $e) {}
    }
}
PHP
	echo "wrote  $BOOTSTRAP  (scaffolded)"
else
	echo "kept   $BOOTSTRAP  (already present)"
fi

echo "done."
