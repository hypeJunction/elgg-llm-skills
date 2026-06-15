# Agent failure modes, escalation, and recovery

Cross-cutting guidance that applies to `elgg-migrate` and
`elgg-site-upgrade`. Read this once at the start of any migration
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

**Trusting AST rules to fix function bodies.** The signature-rewrite rules
(notably `hook-callback-signatures-4x`) only touch parameter lists. They
do not rewrite usages of the renamed parameter inside the function body.
After running the rule, code like `switch ($event)` or `if ($event ===
'create')` is now switching on / comparing an `Elgg\Event` object instead
of the old string parameter — silent, no parse error, the case never
matches at runtime. Guard: after every signature-rewrite rule run, grep
the rewritten files for `switch ($event` and `if ($event ` and
`$event ==` and rewrite to `$event->getName()` (and `$hook` likewise for
hook handlers — `$hook->getValue()`, `$hook->getParam(...)`).

**Losing the plot under context pressure.** On long migrations, the agent
starts skipping steps to "get to the end." Guard: if you find yourself
shortcutting, commit what you have, push it, and hand off to a fresh
session with a beads issue describing exactly where you stopped.

## Freelance migration anti-patterns

These are failure modes specific to long-running sessions where the agent
stops following the skill's process and starts inventing its own. They are
the "I'll just do it faster" failures. Every one of them silently violates
multiple Iron Laws and produces commits that look like migrations but
aren't. The 17 acceptance gates exist to catch them — skip the gates and
the freelance becomes invisible until much later.

**Doing N→N+1 and N→N+2 in the same session.** Symptom: the agent edits
plugin files with both N+1 and N+2 APIs in the same commit ("just do 4x
and 5x together — they're so similar"). Violates Iron Laws 1 and 7. Each
major step has its own AST rules, its own pre-migration tests, its own
gate run, and its own *self-consistent* API surface. Trying to land at
"4.x+" is meaningless — there is no such version. A file with both
`\Elgg\Hook` and `\Elgg\Event` type hints is broken, not "compatible."
Guard: branch is named `migrate/elgg-N.x` for exactly ONE N; if you
notice yourself reaching for N+1 APIs while you should be on N, stop —
the migration you're doing is N→N+1, not whatever's in your head.

**Rsync'ing migrated files across migrate branches.** Symptom: a single
"migration" commit lands on both `migrate/elgg-4.x` and
`migrate/elgg-5.x` by copying the same files. The agent thinks "they're
the same plugin." They're not — each branch represents a *different
Elgg version's API surface*. Rsync skips: the 4→5 AST rules
(hook→event renames), the 4.x→5.x verifier (catches 5.x APIs
accidentally used while on 4.x and vice versa), the pre-migration tests
adapted for the new API, and the 17 acceptance gates for that branch.
The 4.x commit is *probably* valid; the 5.x commit isn't a migration,
it's a copy. Guard: every migrate branch is its own session with its own
gate report. If the same plugin appears in commit histories of two
adjacent branches with identical diffs, redo the second one properly.

**Migrating site-embedded customs as if they were plugin repos.** A site
repo (e.g. a site monorepo) holds custom plugins
git-tracked inside `mod/`. The skill is for plugin repos — separate
repos with their own `docker/elgg{N}/` infra, their own `migrate/elgg-N.x`
branches based on the previous one, their own `tests/`. Editing customs
in-place on a site repo's `migrate/4.x` branch (which is a *site*
branch) skips Docker infra per branch, branch linearity, plugin-repo
verify, plugin-level beads tracking, and the entire "one plugin × one
version cell" model. Symptom: the agent commits "fix(4.x): migrate
community_spam_tools" on the site repo's `migrate/4.x` instead of on
`community_spam_tools` plugin repo's `migrate/elgg-4.x`. Guard: before
editing any custom, ask: does this plugin have its own repo? If no, the
correct first step is to **extract it to one** (with `git filter-repo`
preserving history if useful), then run the skill there. Site repo
just composer-includes the migrated plugin.

**Treating "site activates with N plugins" as a migration success metric.**
The fleet-level worktrees (`/tmp/<site>-{2..6}x`) verify whether the
site BOOTS with a given composer-resolved plugin set. They are an
input to `elgg-site-upgrade`, not output of `elgg-migrate`. A plugin
that boots in the site stack but has no pre-migration tests, no
`--verify --security` pass, no PHPCS, no ARCHITECTURE.md, and no gate
report is not migrated — it's *included*. Guard: "did the gates pass
for this plugin × this version step" is the only definition of migrated.
Site-level boot is necessary but not sufficient.

**Filing migration commits without a gate report.** The skill's
subagent contract says: every migration commit must report PASS/FAIL/
SKIP-WITH-REASON on each of the 17 acceptance gates. A commit message
that says "migrate(4.x): community_spam_tools to elgg-plugin.php"
without listing what gates ran is missing the only evidence that the
migration actually happened. Guard: if you can't list which gates
passed for the commit you're about to make, the commit is premature —
go back and run the gates first.

**"Just one more plugin" creep.** When the user says "continue," the
agent assumes "do another plugin like the last one" rather than
"resume from the documented state with proper gates." On a long
session this compounds: each "continue" adds another freelance
migration to the pile. The "speed" feels productive but every commit
is a future cleanup task. Guard: "continue" after a checkpoint means
"proceed with the *next gate* of the *current plugin*" by default,
NOT "start the next plugin." Confirm scope explicitly before moving
to a new plugin.

**Substituting custom verification for the skill's verification.** The
agent writes its own `verify-something.sh` to check the site boots
and reports OK/FAIL counts. The skill ships `bin/elgg-migrate-verify`
that runs the documented gates and produces a structured report. The
custom script measures different things, will diverge over time, and
its output isn't recognized by the skill's downstream tooling.
Guard: if you're tempted to write a verifier, first check whether
`bin/elgg-migrate-verify` already does what you need. If it doesn't,
extend that script — don't write a parallel one.

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
re-deriving it. **A partial commit with a clear `WIP:` message
("WIP: activation fails with X, needs investigation") is recoverable —
an uncommitted buffer you planned to "clean up at the end" is not.**
Don't ever leave a plugin's working directory dirty across sessions.

**A neighbor plugin is broken (multiple plugins look broken).** Don't
roll back, and don't try to fix neighbors as part of the current
plugin's migration — that's a sweep, which is forbidden (see memory:
*One plugin at a time*). File a separate beads issue for each broken
neighbor, mark the current plugin blocked on whichever neighbor is in
its way, and stop. The next session picks up the highest-priority
unblocked plugin.

**Accidental destructive git operation.** `git reflog` is your friend. Any
commit made in the last 90 days is still in the reflog even after `reset
--hard`. Recover the SHA, `git branch recovery-<name> <sha>`, and
cherry-pick what you need. Don't panic and don't overwrite anything else
until you've recovered.
