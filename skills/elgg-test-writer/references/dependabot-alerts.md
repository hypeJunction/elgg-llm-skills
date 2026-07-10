---
name: dependabot-alerts
description: How to query GitHub Dependabot alerts for an Elgg plugin during pre-flight, triage findings, and decide what to fix during the migration.
---

# GitHub Dependabot Alerts (pre-flight check)

GitHub Dependabot continuously scans a repository's dependency graph against the
GitHub Advisory Database and opens alerts when it finds known vulnerabilities.
For migrations, the alert list on the source branch is a free baseline of
**security debt that the plugin is shipping today** — most of it gets fixed
naturally by bumping deps for the new Elgg major, but the agent must verify
that, not assume it.

This is the third leg of the dependency-security story:

| Tool | Where it runs | What it sees |
|------|---------------|--------------|
| `composer audit` (`--audit` flag) | Inside the migrate container, against the resolved `composer.lock` | Packagist advisories DB, current lock state |
| `SecuritySweep` (`--security` flag) | Inside the migrate container, against plugin source | Pattern matches — eval, SQLi, XSS, etc. |
| **Dependabot alerts** (this doc) | GitHub-side, against the default branch's manifests | GitHub Advisory DB (broader than Packagist), npm/yarn coverage, CodeQL alerts if enabled, transitive resolutions |

The three overlap but none subsumes the others. A plugin can pass `composer
audit` and still have open Dependabot alerts (npm deps, transitive resolutions,
or advisories not yet in Packagist), and the reverse is also true (alerts that
were dismissed but the underlying dep is still vulnerable).

## When to run the check

During **Phase 1 pre-flight**, immediately after the upstream-GitHub
duplicate-migration discovery — you've already used `gh` to look at branches
and forks, so checking alerts is one more `gh api` call against the same repo.

Capture the alert list once, before any migration work begins. After migration,
re-check on the migration branch (Dependabot evaluates manifests on push) to
confirm the bump cleared the alerts.

## Prerequisites

- `gh` CLI authenticated (`gh auth status` should show a logged-in user).
- The plugin lives on GitHub. If it doesn't, this check is **N/A** — log a
  one-line note ("plugin not on GitHub — skipping Dependabot baseline") and
  move on.
- The authenticated user has read access to security alerts on the repo
  (owner, member with `security_events` scope, or public repo with public
  alerts).

If `gh api` returns `403`, the user doesn't have permission to read alerts.
Note it as `Dependabot alerts: access denied` and continue — this is
informational, not a gate.

## Querying alerts

```bash
# Resolve owner/repo from the cloned plugin's git remote
OWNER_REPO=$(git -C "$PLUGIN_DIR" remote get-url origin \
  | sed -E 's#(git@github.com:|https://github.com/)##; s#\.git$##')

# All open alerts, JSON
gh api "repos/${OWNER_REPO}/dependabot/alerts" --paginate \
  -q '[.[] | select(.state=="open")]'

# Compact summary table — severity, package, advisory, manifest path
gh api "repos/${OWNER_REPO}/dependabot/alerts" --paginate \
  -q '.[] | select(.state=="open") |
       [.security_advisory.severity,
        .dependency.package.name,
        .security_advisory.ghsa_id,
        .dependency.manifest_path]
       | @tsv'

# Critical + high only — the ones that actually block the migration story
gh api "repos/${OWNER_REPO}/dependabot/alerts" --paginate \
  -q '.[] | select(.state=="open" and
       (.security_advisory.severity=="critical" or
        .security_advisory.severity=="high"))'
```

If the repo has Dependabot disabled, the API returns `404` with
`"Dependabot alerts are disabled for this repository"`. Note it and move on —
turning Dependabot on isn't the migration's responsibility.

## Triage

Walk the open alerts once and classify each:

| Class | What it looks like | What to do |
|-------|-------------------|------------|
| **Resolved by Elgg major bump** | Vulnerable dep is a transitive of `elgg/elgg` or one of its bundled libraries (Symfony, Doctrine, Guzzle, Knp, Laminas) | Note in commit message; verify cleared after Phase 2 composer update |
| **Resolved by plugin-level dep bump** | Plugin's own `require` pins an old version of a package that has a fixed release compatible with the new PHP/Elgg minimum | Bump during composer.json work in Phase 2; verify cleared on push |
| **Resolved by removing abandoned dep** | Already flagged by `composer audit` as abandoned; the alert is on the same package | Replace or remove during migration; cross-link to existing audit finding |
| **Not resolvable in this step** | npm dep with no fix yet, or composer dep with a fix that requires a future Elgg major | Document in `ARCHITECTURE.md` migration notes; file a follow-up bead |
| **Already dismissed but still firing** | Alert state is `open` but a comment thread shows prior triage decision | Re-read the dismissal context — if it's still valid, dismiss again with a comment after migration |

The principle: every open critical/high alert at migration time is **either
addressed by this migration or explicitly carried forward with a documented
reason**. "Carried forward silently" is not an option.

## Recording the baseline

Persist the alert summary alongside the rest of the per-job state:

```bash
gh api "repos/${OWNER_REPO}/dependabot/alerts" --paginate \
  > "$ELGG_MIGRATE_STATE/jobs/${PLUGIN_ID}-${SHORT_SHA}/dependabot-baseline.json"
```

After the migration commit lands and Dependabot has rescanned, re-fetch and
diff:

```bash
gh api "repos/${OWNER_REPO}/dependabot/alerts" --paginate \
  > "$ELGG_MIGRATE_STATE/jobs/${PLUGIN_ID}-${SHORT_SHA}/dependabot-postmigration.json"

jq -r '[.[] | select(.state=="open") | .number] | sort' \
  "$ELGG_MIGRATE_STATE/jobs/${PLUGIN_ID}-${SHORT_SHA}/dependabot-baseline.json" \
  > /tmp/before.txt

jq -r '[.[] | select(.state=="open") | .number] | sort' \
  "$ELGG_MIGRATE_STATE/jobs/${PLUGIN_ID}-${SHORT_SHA}/dependabot-postmigration.json" \
  > /tmp/after.txt

diff /tmp/before.txt /tmp/after.txt
```

A clean migration usually shows alerts moving from `open` → `fixed` (Dependabot
auto-resolves once the manifest no longer pins the vulnerable version).

## What this is NOT

- **Not an acceptance gate.** Plugins hosted off GitHub can't run this check;
  promoting it to a strict gate would punish them.
- **Not a substitute for `--audit`.** `composer audit` runs offline against
  the resolved lockfile inside the container — it's faster and works on every
  plugin, GitHub-hosted or not.
- **Not a substitute for the LLM security review.** Dependabot only knows
  about *published advisories* against *named packages*. It cannot find
  business logic flaws, IDOR, or migration-introduced auth gaps.

The point of the check is to stop the migration from accidentally shipping
known dependency CVEs forward — nothing more.
