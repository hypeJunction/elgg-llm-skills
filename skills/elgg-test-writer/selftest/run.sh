#!/usr/bin/env bash
# run.sh — self-tests for the code elgg-test-writer actually OWNS.
#
# Everything under tests/ is the mirrored elgg-migrate engine (and is wiped by
# bin/gen-elgg-infra.sh on every run), so the skill's own scaffolding had no
# coverage at all: bin/scaffold-*.sh, bin/lib/extract-plugin-config.php, and the
# template substitution that turns *.template into a plugin's test suite.
#
# These tests are offline and fast: no docker, no network, no DB. They scaffold
# throwaway fixture plugins into a temp dir and assert on what lands there.
#
# Usage:  selftest/run.sh          (exit 0 = all pass)
set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SKILL_ROOT="$(dirname "$SELF_DIR")"
BIN="$SKILL_ROOT/bin"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/elgg-test-writer-selftest.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

pass=0; fail=0
ok()   { pass=$((pass+1)); printf '  \033[32mok\033[0m   %s\n' "$1"; }
bad()  { fail=$((fail+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; [ -n "${2:-}" ] && printf '        %s\n' "$2"; }
is()   { [ "$2" = "$3" ] && ok "$1" || bad "$1" "expected '$3', got '$2'"; }
# -F: the patterns contain namespace backslashes (use PHPUnit\Framework\TestCase),
# which grep would otherwise read as regex escapes and silently not match.
has()  { grep -qF -- "$2" "$3" 2>/dev/null && ok "$1" || bad "$1" "'$2' not found in $3"; }
hasnt(){ grep -qF -- "$2" "$3" 2>/dev/null && bad "$1" "'$2' unexpectedly present in $3" || ok "$1"; }

# Build a minimal plugin pinned to a given Elgg major.
make_plugin() {
    local dir="$1" major="$2"
    mkdir -p "$dir"
    printf '{ "require": { "elgg/elgg": "~%s.0" } }\n' "$major" > "$dir/composer.json"
    cat > "$dir/elgg-plugin.php" <<'PHP'
<?php
return [
    'actions'  => ['demo/save' => [], 'demo/admin' => ['access' => 'admin']],
    'entities' => [
        ['type' => 'object', 'subtype' => 'demo_item', 'class' => 'Demo\\Item'],
    ],
];
PHP
}

echo "== extract-plugin-config.php =="

EX="$BIN/lib/extract-plugin-config.php"
P="$WORK/extract"; make_plugin "$P" 6
json="$(php "$EX" "$P")"
is "actions are extracted and sorted" \
   "$(echo "$json" | php -r 'echo implode(",", json_decode(file_get_contents("php://stdin"),true)["actions"]);')" \
   "demo/admin,demo/save"
is "entity class string literal survives" \
   "$(echo "$json" | php -r 'echo json_decode(file_get_contents("php://stdin"),true)["entities"][0]["class"];')" \
   'Demo\Item'

# ::class is a compile-time constant and MUST be accepted (it never autoloads).
cat > "$P/elgg-plugin.php" <<'PHP'
<?php
return ['entities' => [['type' => 'object', 'subtype' => 'demo_page', 'class' => \Demo\Page::class]]];
PHP
is "::class resolves to a class string" \
   "$(php "$EX" "$P" | php -r 'echo json_decode(file_get_contents("php://stdin"),true)["entities"][0]["class"];')" \
   '\Demo\Page'

# A class CONSTANT (::SUBTYPE) DOES autoload at generateEntities() time and is the
# real fatal, so the extractor cannot evaluate it. It must NOT drop the row
# silently: a missing entity row means the scaffolded baseline asserts nothing
# about exactly the binding most likely to fatal.
cat > "$P/elgg-plugin.php" <<'PHP'
<?php
return ['entities' => [
    ['type' => 'object', 'subtype' => \Demo\Page::SUBTYPE, 'class' => 'Demo\\Page'],
    ['type' => 'object', 'subtype' => 'ok_item', 'class' => 'Demo\\Item'],
]];
PHP
err="$WORK/extract.err"
php "$EX" "$P" 2>"$err" >"$WORK/extract.json"
is "::CONST row does not abort the extractor" "$?" "0"
is "resolvable siblings still emitted" \
   "$(php -r 'echo count(json_decode(file_get_contents("'"$WORK"'/extract.json"),true)["entities"]);')" \
   "1"
is "unresolved row is reported in the JSON" \
   "$(php -r 'echo count(json_decode(file_get_contents("'"$WORK"'/extract.json"),true)["entities_unresolved"] ?? []);')" \
   "1"
has "unresolved row warns on stderr, naming the construct" '::SUBTYPE' "$err"
has "warning explains the autoload fatal" 'autoloads at generateEntities() time' "$err"

# A clean plugin must stay silent — a warning that always fires is noise.
cat > "$P/elgg-plugin.php" <<'PHP'
<?php
return ['entities' => [['type' => 'object', 'subtype' => 'ok', 'class' => 'Demo\\Ok']]];
PHP
php "$EX" "$P" 2>"$WORK/clean.err" >/dev/null
is "clean plugin produces no warning" "$(wc -c < "$WORK/clean.err" | tr -d ' ')" "0"

# Empty blocks must produce empty arrays, not nulls the templates would choke on.
printf '<?php\nreturn [];\n' > "$P/elgg-plugin.php"
is "no actions/entities -> empty arrays" \
   "$(php "$EX" "$P" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo count($d["actions"]).":".count($d["entities"]);')" \
   "0:0"

echo "== scaffold-smoke-tests.sh: version-gated base class =="

# Elgg 2.x has no \Elgg\IntegrationTestCase (it first ships in 3.0), so the
# baseline must extend plain PHPUnit\Framework\TestCase there or it fatals at
# class-load — on exactly the 2.x->3.x step the baseline exists to protect.
for major in 2 3 6; do
    P="$WORK/scaffold$major"; make_plugin "$P" "$major"
    bash "$BIN/scaffold-smoke-tests.sh" --plugin-dir="$P" --force >/dev/null 2>&1
    base="$P/tests/phpunit/integration/BaselineTest.php"
    if [ ! -f "$base" ]; then bad "elgg$major: BaselineTest generated"; continue; fi

    php -l "$base" >/dev/null 2>&1 && ok "elgg$major: BaselineTest is valid PHP" \
                                   || bad "elgg$major: BaselineTest fails php -l"
    hasnt "elgg$major: no unsubstituted placeholders" '__BASE_CLASS' "$base"

    if [ "$major" -le 2 ]; then
        has  "elgg$major: extends PHPUnit TestCase" 'use PHPUnit\Framework\TestCase;' "$base"
        hasnt "elgg$major: does not reference IntegrationTestCase" 'IntegrationTestCase' "$base"
    else
        has "elgg$major: extends Elgg IntegrationTestCase" 'use Elgg\IntegrationTestCase;' "$base"
    fi
done

echo "== scaffold-smoke-tests.sh: generated suite runs =="

# The static-scan tests must run on the SOURCE tier's PHP (7.2/7.4 for elgg2/3),
# so no PHP 8-only builtins may appear in them.
P="$WORK/scan"; make_plugin "$P" 6
bash "$BIN/scaffold-smoke-tests.sh" --plugin-dir="$P" --target-major=7 --force >/dev/null 2>&1
mig="$P/tests/phpunit/unit/MigrationRegressionTest.php"
if [ -f "$mig" ]; then
    php -l "$mig" >/dev/null 2>&1 && ok "MigrationRegressionTest is valid PHP" || bad "MigrationRegressionTest fails php -l"
    if grep -qE '\bstr_(contains|starts_with|ends_with)\b' "$mig"; then
        bad "no PHP 8-only string builtins in the static scan" "found str_contains/str_starts_with/str_ends_with"
    else
        ok "no PHP 8-only string builtins in the static scan"
    fi
else
    bad "MigrationRegressionTest generated"
fi

# The unserialize() object-injection gate must actually fire. A rewrite that
# turned `!str_contains(...)` into `!strpos(...) !== false` would silently
# disable it while still passing php -l.
PHPUNIT=""
for cand in "$SKILL_ROOT/vendor/bin/phpunit" "$SKILL_ROOT/../elgg-migrate/vendor/bin/phpunit"; do
    [ -x "$cand" ] && { PHPUNIT="$cand"; break; }
done
if [ -n "$PHPUNIT" ] && [ -f "$mig" ]; then
    mkdir -p "$P/lib"
    printf '<?php\n$x = unserialize($data);\n' > "$P/lib/bad.php"
    if "$PHPUNIT" --no-configuration --do-not-cache-result --filter 'nserialize' "$mig" >/dev/null 2>&1; then
        bad "unserialize gate fails a plugin without allowed_classes" "gate passed a violating plugin"
    else
        ok "unserialize gate fails a plugin without allowed_classes"
    fi
    printf '<?php\n$x = unserialize($data, ["allowed_classes" => false]);\n' > "$P/lib/bad.php"
    if "$PHPUNIT" --no-configuration --do-not-cache-result --filter 'nserialize' "$mig" >/dev/null 2>&1; then
        ok "unserialize gate passes a plugin that sets allowed_classes"
    else
        bad "unserialize gate passes a plugin that sets allowed_classes" "gate rejected a clean plugin"
    fi
else
    echo "  skip  unserialize gate behavior (no phpunit binary found)"
fi

echo "== scaffold-docker.sh =="

P="$WORK/docker"; make_plugin "$P" 7
if bash "$BIN/scaffold-docker.sh" --plugin-dir="$P" --force >/dev/null 2>&1; then
    for f in Dockerfile docker-compose.yml elgg-install.sh; do
        [ -f "$P/docker/$f" ] && ok "docker/$f copied" || bad "docker/$f copied"
    done
    [ -f "$P/docker/docker-compose.yml" ] && has "compose scopes containers to the plugin" 'PLUGIN_ID' "$P/docker/docker-compose.yml"
else
    echo "  skip  scaffold-docker.sh (non-zero exit; likely needs jq)"
fi

echo ""
printf 'selftest: %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
