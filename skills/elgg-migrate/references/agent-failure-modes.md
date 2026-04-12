# Agent failure modes, escalation, and recovery

Cross-cutting guidance that applies to `elgg-migrate`, `elgg-plugin-fleet`,
and `elgg-site-upgrade`. Read this once at the start of any migration
session; refer back when you're stuck or tempted to cut corners.

## Cost of failure (not every rule is equal)

The Iron Laws read as if every violation is equally bad. They're not.
Knowing the asymmetry matters because under pressure you'll cut corners
somewhere — cut them where the cost is low, not where it's catastrophic.

**Unrecoverable (never cut):** Iron Laws 1, 2, 3, 4, 7. Skipping a major
version corrupts every downstream migration in ways that surface months
later. Migrating without a branch means no way to bisect a regression.
Proceeding without Docker verification means the migration's "success" is
theater. Skipping pre-migration tests means you have no way to detect
regressions after the fact. Version-knowledge leakage means the plugin
works in your environment and fails in someone else's.

**Expensive but recoverable:** Iron Laws 5, 6, 8, 9. Closures in
elgg-plugin.php fail loudly on activation — you'll catch it. Directory
case mismatch fails loudly too. A missed security finding is a real risk
but usually survivable; security debt is a known quantity, not a hidden
bomb. Missing ARCHITECTURE.md is expensive for future work, not for
current work.

**Cosmetic:** Iron Law 10 (coding style). A style regression is annoying,
not dangerous. If you have to defer one gate because you're out of time,
defer this one — never defer tests or Docker verification.

When gates conflict (say, the security sweep and the Docker gate both need
attention and you only have bandwidth for one), work from unrecoverable
toward cosmetic. Tests and Docker first, security and verification second,
style last.

## When to stop and escalate

This workflow is designed to succeed on common cases. Some cases are not
common, and forging ahead on them produces fake progress — a "migrated"
plugin that's actually broken. Stop and surface the block to the human
(don't just silently skip) when:

- You've tried three or more distinct approaches to fix the same activation
  failure and none worked. Each attempt teaches you something; three
  failures means you're missing information the code doesn't contain.
- The plugin uses an API that doesn't appear in any manifest rule, you
  can't find a reference migration anywhere upstream, and the target
  version's docs don't obviously say what to use instead.
- A pre-migration test fails against the *current* version in a way you
  can't explain — meaning the plugin was already broken and you don't
  know whether the break is intentional.
- You find yourself wanting to use `--no-guard`, comment out a failing
  test, or bypass a gate "just this once" to unblock progress. That urge
  is the signal — the gate exists *because* the case you're hitting is
  the case it was built for.
- The security review surfaces a pattern you don't fully understand
  (especially around authentication, authorization, or data flow).
- A gate passes but something feels wrong — the page renders but looks
  subtly different, tests pass but faster than they should, the migration
  diff is smaller than you expected. Anomalies the gates weren't designed
  to catch are exactly the ones worth investigating.
- Data migration is needed (schema change, metadata reshape) and there's
  no existing `Elgg\Upgrade\Batch` pattern that fits the case.

Escalation means: commit what you have, open a beads issue describing what
you tried and why it didn't work, and hand off to the human. Don't delete
work, don't "come back to it later" implicitly, don't fake a pass.

## Agent failure modes (invisible to the gates)

The acceptance gates catch problems in the code. They don't catch problems
in the agent doing the migration. These are the failure modes worth
naming explicitly, because they're silent:

**Hallucinated APIs.** Making up functions like `elgg_get_plugin_entity()`
that sound plausible but don't exist in any version. Guard: when you're
about to use an API that doesn't appear in the manifest rules, grep the
target version's `vendor/elgg/elgg` for it first. If you can't find it,
you're inventing.

**Cross-version knowledge leakage.** Using `\Elgg\Event` during a 3→4
migration because you "know" it's the modern way — but 4.x wants
`\Elgg\Hook`. Guard: always run `--verify` after rules. Exit code 3 is
this failure, and ignoring it is how the leakage lands in production.

**Fabricated gate results.** Claiming "tests passed" without running them,
or running them and skimming the output without reading the summary line.
Guard: when you report a gate passed, the report should include the
actual command you ran and the actual output — if it's summarized, you
didn't check.

**Skipping upstream checks because "probably nothing there."** The
upstream check is the highest-leverage step in the whole workflow.
Skipping it means doing hours of work that someone else has already done.
Guard: the check is fast, run it every time, even when you're sure.

**Guessing `composer.json` fields from memory.** The required-fields table
in `elgg-migrate/SKILL.md` exists because these fields are easy to get
wrong. Read the table — don't approximate.

**Conflating hook and event semantics.** They're different across versions
(4.x has both, 5.x merges them, 3.x uses hooks with Hook type hints).
Guard: before registering a handler, check which key (`'hooks'` vs
`'events'`) the target version expects, and which type hint its handlers
take.

**Losing the plot under context pressure.** On long migrations, the agent
starts skipping steps to "get to the end." Guard: if you find yourself
shortcutting, commit what you have, push it, and hand off to a fresh
session with a beads issue describing exactly where you stopped.

## Recovery playbook

When things go wrong — and they will — the right move is almost never
"start over." These are the recovery patterns for common failure shapes:

**AST rules produced broken code.** Don't edit the broken output. Instead,
`git reset --hard HEAD~1` to the commit before the automated pass, then
re-run `bin/migrate.php` with `--dry-run` first to see exactly what the
rules want to change. If a specific rule is wrong, note it for the
learning loop and hand-apply the correct transformation.

**Activation fails in Docker with an opaque error.** Tail the Apache error
log (`docker compose exec elgg tail -f /var/log/apache2/error.log`) and
re-trigger activation. The real error is usually in the log, not in the
PHP exception message. Cross-reference against `references/common-mistakes.md`
— most activation failures in practice are entries in that table.

**Tests pass in pre-migration but fail after adaptation.** The failure is
usually in the adaptation, not the migration. Diff the adapted test
against the original and the reference migration (if one exists). If the
reference plugin's tests use a different pattern, adopt that pattern.

**Site activates but renders partially (blank page, missing CSS, broken
links).** Run the full Docker gate checks even if you think it worked —
the simplecache CSS check and the error log grep exist because these
failures are subtle. Blank CSS is almost always css-crush failing
silently; missing links are usually routes that didn't migrate.

**Mid-migration context exhaustion (session running out of room).** This
is recoverable if you act before you've forgotten state. Commit what
works (even incomplete), push to remote, update the beads issue with: the
branch name, the last gate that passed, the next gate to attempt, and any
known issues. A fresh session can resume from that state without
re-deriving it.

**Fleet-wide disaster (multiple plugins broken).** Don't roll everything
back. File a beads issue per broken plugin, mark them blocked, and
continue with the unblocked work. Iron Law 4 of `elgg-plugin-fleet` (fail
fast, fix forward) is specifically for this case — stopping the whole
fleet on one hard case is worse than leaving that plugin behind.

**Accidental destructive git operation.** `git reflog` is your friend. Any
commit made in the last 90 days is still in the reflog even after `reset
--hard`. Recover the SHA, `git branch recovery-<name> <sha>`, and
cherry-pick what you need. Don't panic and don't overwrite anything else
until you've recovered.
