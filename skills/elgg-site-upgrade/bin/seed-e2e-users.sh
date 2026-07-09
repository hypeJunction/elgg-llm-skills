#!/usr/bin/env bash
# seed-e2e-users.sh — provision the two accounts the Playwright suite logs in as.
#
# WHY: the e2e specs assume an admin and a normal member who actually *belong* to
# the site — they own a profile, they are members of a public group and of a
# members-only group, so the authed specs can assert on "my groups", the Join
# control, group discussion listings and owner blocks.
#
# Suites tend to hardcode usernames lifted from an ANONYMISED dev dump. Those
# accounts do not exist in the chain-migrated production database, so every
# authenticated spec fails on missing state rather than on a migration defect. And
# you must never reset a real member's password to run tests. So: create two
# dedicated, clearly-named accounts and give them the state the specs expect.
#
# Idempotent. Safe to re-run. NEVER point this at production.
#
# Usage:
#   ELGG_APP_CONTAINER=myapp-1 seed-e2e-users.sh
#   ADMIN_USER=x ADMIN_PASS=y seed-e2e-users.sh --container myapp-1
set -euo pipefail

CONTAINER="${ELGG_APP_CONTAINER:-}"
ADMIN_USER="${ADMIN_USER:-migverify_admin}"
ADMIN_PASS="${ADMIN_PASS:-Verify12345!}"
NORMAL_USER="${NORMAL_USER:-migverify_user}"
NORMAL_PASS="${NORMAL_PASS:-Verify12345!}"

while [ $# -gt 0 ]; do
  case "$1" in
    --container) CONTAINER="$2"; shift 2 ;;
    *) echo "unknown arg: $1" >&2; exit 2 ;;
  esac
done

[ -n "$CONTAINER" ] || { echo "ERROR: set ELGG_APP_CONTAINER (or pass --container)" >&2; exit 2; }
docker inspect "$CONTAINER" >/dev/null 2>&1 || { echo "ERROR: container $CONTAINER not running" >&2; exit 2; }

# Refuse to run against anything that looks like the live site.
site_url="$(docker exec "$CONTAINER" sh -c 'cd /var/www/html && php -r "
require \"vendor/autoload.php\";
\Elgg\Application::getInstance()->bootCore();
echo elgg_get_site_url();
"' 2>/dev/null || true)"
case "$site_url" in
  *localhost*|*127.0.0.1*) : ;;
  *) echo "REFUSING: site url is '$site_url' — this seeder is for local stacks only." >&2; exit 3 ;;
esac

script=$(mktemp)
cat > "$script" <<'PHP'
<?php
require '/var/www/html/vendor/autoload.php';
\Elgg\Application::getInstance()->bootCore();

[$_s, $admin_user, $admin_pass, $normal_user, $normal_pass] = $argv;

/** Create-or-update a user, then return it. */
$upsert = function (string $username, string $pass, bool $admin): \ElggUser {
    $user = elgg_get_user_by_username($username);
    if (!$user) {
        $user = new \ElggUser();
        $user->username = $username;
        $user->name = $admin ? 'Migration Verifier (admin)' : 'Migration Verifier';
        $user->email = $username . '@example.invalid';
        $user->save();
    }
    $user->setPassword($pass);
    $user->setValidationStatus(true, 'seed-e2e-users');
    $user->enable();

    // Profile fields the profile specs assert on.
    $user->briefdescription = 'Automated end-to-end verification account.';
    $user->save();

    if ($admin && !$user->isAdmin()) {
        $user->makeAdmin();
    }
    return $user;
};

$results = elgg_call(ELGG_IGNORE_ACCESS, function () use ($upsert, $admin_user, $admin_pass, $normal_user, $normal_pass) {
    $admin = $upsert($admin_user, $admin_pass, true);
    $normal = $upsert($normal_user, $normal_pass, false);

    // Join both users to every enabled group that has discussions, so the authed
    // group/discussion specs have something to read. Membership is what unlocks a
    // members_only group's content — see Gatekeeper::assertAccessibleGroup().
    $groups = elgg_get_entities([
        'type' => 'group',
        'limit' => 0,
    ]);

    $joined = 0;
    foreach ($groups as $group) {
        $has_discussions = elgg_get_entities([
            'type' => 'object',
            'subtype' => 'discussion',
            'container_guid' => $group->guid,
            'count' => true,
        ]);
        if (!$has_discussions) {
            continue;
        }
        foreach ([$admin, $normal] as $u) {
            if (!$group->isMember($u)) {
                $group->join($u);
                $joined++;
            }
        }
    }

    return [$admin, $normal, $joined, count($groups)];
});

[$admin, $normal, $joined, $group_count] = $results;
printf("admin  = %s (guid %d, admin=%s)\n", $admin->username, $admin->guid, $admin->isAdmin() ? 'yes' : 'no');
printf("normal = %s (guid %d, admin=%s)\n", $normal->username, $normal->guid, $normal->isAdmin() ? 'yes' : 'no');
printf("joined %d group memberships across %d groups\n", $joined, $group_count);
PHP

docker cp "$script" "$CONTAINER:/var/www/html/seed-e2e-users.php" >/dev/null
rm -f "$script"
# mktemp gives 0600 owned by the host uid; docker cp preserves it, so www-data
# would get "Could not open input file".
docker exec "$CONTAINER" chmod 0644 /var/www/html/seed-e2e-users.php
docker exec -u www-data "$CONTAINER" sh -c \
  "cd /var/www/html && php seed-e2e-users.php '$ADMIN_USER' '$ADMIN_PASS' '$NORMAL_USER' '$NORMAL_PASS'"
rc=$?
docker exec "$CONTAINER" rm -f /var/www/html/seed-e2e-users.php
exit $rc
