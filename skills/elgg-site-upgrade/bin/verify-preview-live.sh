#!/usr/bin/env bash
# verify-preview-live.sh — crawl the deployed 7.x preview, anonymously and as a
# logged-in member, asserting per page: expected status, no PHP fatal behind a
# 200, and no Elgg CRITICAL raised while serving it.
#
# Runs ON THE SERVER against the container's own port, bypassing the nginx
# basic-auth gate (whose password exists only as an apr1 hash). nginx is not what
# the migration changed; Elgg is.
#
# The Host header is mandatory: the container is bound to 127.0.0.1 and Elgg
# rejects a request whose Host does not match wwwroot with a 400.
#
# A temporary member account is created for the authenticated pass and DELETED
# afterwards, so the preview's real user set is left exactly as it was. The
# authenticated pass matters: /discussion/all was a hard 500 for logged-in
# non-admins while anonymous visitors saw a clean 200.
#
# Usage:
#   bin/verify-preview-live.sh                 # full anon + authed crawl
#   bin/verify-preview-live.sh --anon-only
#
# Exit: 0 all good · 1 at least one page failed
set -uo pipefail

source "$(dirname "$0")/preview-7x/config.sh"

APP_C="${PREVIEW_APP_CONTAINER:?set PREVIEW_APP_CONTAINER}"
PORT="${ELGG_PORT:-8287}"
HOSTHDR="${PREVIEW_HOST:?set PREVIEW_HOST}"
PREFIX="${PREVIEW_PREFIX:-}"
TMP_USER="preview_verify_$$"
TMP_PASS="Verify$$!aA"
ANON_ONLY=0
[ "${1:-}" = "--anon-only" ] && ANON_ONLY=1

ensure_ssh

# Everything below runs remotely in one shot: a round trip per URL over ssh would
# take minutes, and the log scan has to bracket each request.
remote_script=$(cat <<REMOTE
set -u
APP_C='$APP_C'; PORT='$PORT'; HOSTHDR='$HOSTHDR'; PREFIX='$PREFIX'
TMP_USER='$TMP_USER'; TMP_PASS='$TMP_PASS'; ANON_ONLY='$ANON_ONLY'

BASE="http://127.0.0.1:\$PORT"
# X-Forwarded-Prefix is what nginx sends; without it forwarded-prefix.php stays
# inert, Elgg's base path disagrees with the request, and every SEF rewrite 404s.
H=(-H "Host: \$HOSTHDR" -H "X-Forwarded-Proto: https" -H "X-Forwarded-Prefix: \$PREFIX")
FATAL_RE='Fatal error|Uncaught|ArgumentCountError|TypeError|Too few arguments|Call to undefined|must be of type|Infinite recursion'

fails=0
crawl() { # label cookiejar url expected
  local label="\$1" jar="\$2" url="\$3" expect="\$4"
  local body code
  body="\$(mktemp)"
  code=\$(curl -s "\${H[@]}" \${jar:+-b "\$jar"} -o "\$body" -w '%{http_code}' "\$BASE\$url")
  local fatal=""
  grep -qE "\$FATAL_RE" "\$body" && fatal=" FATAL-IN-BODY"
  local ok=0
  case "\$expect" in
    2xx) [ "\$code" -ge 200 ] && [ "\$code" -lt 300 ] && ok=1 ;;
    ok)  [ "\$code" -lt 400 ] && ok=1 ;;
    gate) { [ "\$code" = 302 ] || [ "\$code" = 303 ] || [ "\$code" = 403 ]; } && ok=1 ;;
    *)   [ "\$code" = "\$expect" ] && ok=1 ;;
  esac
  if [ "\$ok" = 1 ] && [ -z "\$fatal" ]; then
    printf '  ok   %-6s %-4s %s\n' "\$label" "\$code" "\$url"
  else
    printf '  FAIL %-6s %-4s %s (expected %s)%s\n' "\$label" "\$code" "\$url" "\$expect" "\$fatal"
    fails=\$((fails+1))
  fi
  rm -f "\$body"
}

echo "── anonymous"
for u in / /activity /members /blog/all /news/all /groups/all /courses/all \
         /discussion/all /topics/all /gallery /folders/all /file/all \
         /bookmarks/all /glossary/all /search?q=massage /login /register /forgotpassword; do
  crawl anon "" "\$u" 2xx
done
for u in /admin /admin/plugins; do crawl anon "" "\$u" gate; done
crawl anon "" /this-route-does-not-exist-xyz 404

# hypeseo SEF pretty URLs. These are the canary for the subpath deployment: the
# rewritten request only routes when nginx forwards X-Forwarded-Prefix, and every
# one of them 404s silently when it does not.
# Set SEF_PROBE_PATHS to a couple of known pretty URLs on your site.
for u in \${SEF_PROBE_PATHS:-}; do crawl sef "" "\$u" 200; done

if [ "\$ANON_ONLY" = 1 ]; then
  echo; echo "anon-only: fails=\$fails"; exit \$([ \$fails -eq 0 ] && echo 0 || echo 1)
fi

echo
echo "── provisioning a temporary member (deleted at the end)"
# A PHP file, not an inline -r: this string has already been through a local
# heredoc and an ssh shell, and every extra layer of quoting mangles it.
cat > /tmp/mkuser.php <<'PHPEOF'
<?php
require '/var/www/html/vendor/autoload.php';
\Elgg\Application::getInstance()->bootCore();
[\$_s, \$username, \$password] = \$argv;
\$u = elgg_call(ELGG_IGNORE_ACCESS, function () use (\$username, \$password) {
    \$u = new ElggUser();
    \$u->username = \$username;
    \$u->name = 'Preview Verifier';
    \$u->email = \$username . '@example.invalid';
    \$u->save();
    \$u->setPassword(\$password);
    \$u->setValidationStatus(true, 'verify-preview-live');
    \$u->enable();
    \$u->save();
    return \$u;
});
echo \$u->guid;
PHPEOF
docker cp /tmp/mkuser.php "\$APP_C:/var/www/html/mkuser.php" >/dev/null
docker exec "\$APP_C" chmod 0644 /var/www/html/mkuser.php
GUID=\$(docker exec -u www-data "\$APP_C" sh -c "cd /var/www/html && php mkuser.php '\$TMP_USER' '\$TMP_PASS' 2>/dev/null")
docker exec "\$APP_C" rm -f /var/www/html/mkuser.php
rm -f /tmp/mkuser.php
[ -n "\$GUID" ] || { echo "  FAILED to create the temp user"; exit 1; }
echo "  created \$TMP_USER (guid \$GUID)"

JAR=\$(mktemp)
LOGIN=\$(curl -s "\${H[@]}" -c "\$JAR" "\$BASE/login")
TS=\$(printf '%s' "\$LOGIN" | grep -o 'name="__elgg_ts" value="[0-9]*"' | grep -o '[0-9]*' | head -1)
TOK=\$(printf '%s' "\$LOGIN" | grep -o 'name="__elgg_token" value="[^"]*"' | sed 's/.*value="//;s/"//' | head -1)
CODE=\$(curl -s "\${H[@]}" -b "\$JAR" -c "\$JAR" -o /dev/null -w '%{http_code}' -X POST "\$BASE/action/login" \
  --data-urlencode "username=\$TMP_USER" --data-urlencode "password=\$TMP_PASS" \
  --data-urlencode "__elgg_ts=\$TS" --data-urlencode "__elgg_token=\$TOK")
SETTINGS=\$(curl -s "\${H[@]}" -b "\$JAR" -o /dev/null -w '%{http_code}' "\$BASE/settings/user")
if [ "\$SETTINGS" != "200" ]; then
  echo "  FAIL login did not take (POST \$CODE, /settings/user \$SETTINGS)"; fails=\$((fails+1))
else
  echo "  logged in as \$TMP_USER"
  echo
  echo "── authenticated member"
  for u in / /activity /dashboard /members /discussion/all /topics/all /blog/all \
           /gallery /folders/all /file/all /messages/inbox /messages/add "/blog/add/\$GUID" \
           "/file/add/\$GUID" "/bookmarks/add/\$GUID" /settings/user "/profile/\$TMP_USER"; do
    crawl member "\$JAR" "\$u" 2xx
  done
  crawl member "\$JAR" /admin gate
fi
rm -f "\$JAR"

echo
echo "── removing the temporary member"
cat > /tmp/rmuser.php <<'PHPEOF'
<?php
require '/var/www/html/vendor/autoload.php';
\Elgg\Application::getInstance()->bootCore();
[\$_s, \$username] = \$argv;
elgg_call(ELGG_IGNORE_ACCESS, function () use (\$username) {
    \$u = elgg_get_user_by_username(\$username);
    echo \$u && \$u->delete() ? "deleted\n" : "already gone\n";
});
PHPEOF
docker cp /tmp/rmuser.php "\$APP_C:/var/www/html/rmuser.php" >/dev/null
docker exec "\$APP_C" chmod 0644 /var/www/html/rmuser.php
docker exec -u www-data "\$APP_C" sh -c "cd /var/www/html && php rmuser.php '\$TMP_USER' 2>/dev/null" | sed 's/^/  /'
docker exec "\$APP_C" rm -f /var/www/html/rmuser.php
rm -f /tmp/rmuser.php

echo
echo "── Elgg CRITICAL entries raised during this crawl"
crit=\$(docker logs --since 10m "\$APP_C" 2>&1 | grep -o 'ELGG.CRITICAL: [^{"]*' | sort -u)
if [ -n "\$crit" ]; then
  printf '%s\n' "\$crit" | sed 's/^/  /'
  fails=\$((fails+1))
else
  echo "  none"
fi

echo
echo "verify-preview-live: fails=\$fails"
exit \$([ \$fails -eq 0 ] && echo 0 || echo 1)
REMOTE
)

printf '%s' "$remote_script" | remote 'bash -s'
