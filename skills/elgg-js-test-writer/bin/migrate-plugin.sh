#!/bin/bash
set -e

# Usage: ./bin/migrate-plugin.sh <plugin-path> <manifest>
# Example: ./bin/migrate-plugin.sh ~/Data/hypejunction/plugins/hypeDropzone rules/2x-to-3x/manifest.json
#          ./bin/migrate-plugin.sh ~/Data/hypejunction/plugins/hypeDropzone rules/3x-to-4x/manifest.json

PLUGIN_PATH="${1:?Usage: migrate-plugin.sh <plugin-path> <manifest>}"
MANIFEST="${2:?Usage: migrate-plugin.sh <plugin-path> <manifest>}"
PLUGIN_NAME=$(basename "$PLUGIN_PATH")
ELGG_MIGRATE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# Derive branch name from manifest path (e.g., rules/3x-to-4x/manifest.json → migrate/elgg-4.x)
TARGET_VERSION=$(basename "$(dirname "$MANIFEST")" | sed 's/.*-to-//')
BRANCH="migrate/elgg-${TARGET_VERSION}"

echo "=== Migrating $PLUGIN_NAME ==="
echo "Plugin: $PLUGIN_PATH"
echo "Manifest: $MANIFEST"
echo "Branch: $BRANCH"
echo

# Step 1: Create migration branch
cd "$PLUGIN_PATH"
if git rev-parse --verify "$BRANCH" >/dev/null 2>&1; then
    echo "Branch $BRANCH already exists, checking out..."
    git checkout "$BRANCH"
else
    echo "Creating branch $BRANCH..."
    git checkout -b "$BRANCH"
fi

# Step 2: Run dry-run analysis
echo
echo "--- ANALYSIS ---"
cd "$ELGG_MIGRATE_ROOT"
php bin/migrate.php "$MANIFEST" "$PLUGIN_PATH" --dry-run 2>&1

# Step 3: Apply automated rules
echo
echo "--- APPLYING ---"
php bin/migrate.php "$MANIFEST" "$PLUGIN_PATH" 2>&1

# Step 4: Verify PHP syntax
echo
echo "--- SYNTAX CHECK ---"
errors=0
for f in $(command find "$PLUGIN_PATH" -name "*.php" -not -path "*/vendor/*"); do
    result=$(php -l "$f" 2>&1)
    if echo "$result" | grep -q "Parse error"; then
        echo "FAIL: $f"
        errors=$((errors + 1))
    fi
done
if [ $errors -eq 0 ]; then
    echo "All PHP files pass syntax check."
else
    echo "ERROR: $errors file(s) have syntax errors!"
fi

# Step 5: Commit
echo
echo "--- COMMITTING ---"
cd "$PLUGIN_PATH"
if git diff --quiet && git diff --cached --quiet; then
    echo "No changes to commit."
else
    git add -A
    git commit -m "migrate(${TARGET_VERSION}): automated AST transformations

Applied by elgg-migrate automated rules.
See elgg-migrate $(basename "$(dirname "$MANIFEST")")/manifest.json for details."
    echo "Committed."
fi

echo
echo "=== Done: $PLUGIN_NAME ==="
echo "Branch: $BRANCH"
echo "Review with: git -C $PLUGIN_PATH log --oneline $BRANCH --not master"
