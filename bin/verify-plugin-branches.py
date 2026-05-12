#!/usr/bin/env python3
"""
verify-plugin-branches.py  <plugin_dir> [--json]

Checks every migrate/elgg-N.x branch (and main) of a plugin repo for:
  1. Branch existence
  2. Linear migration (each branch based on the previous one)
  3. composer.json: correct elgg/elgg + php constraints
  4. No start.php on Elgg 4+ branches
  5. Tests exist (tests/*Test.php)
  6. Docker infra exists (docker/)
  7. README.md mentions the target Elgg major version
  8. main branch == migrate/elgg-7.x tip

Outputs NDJSON (one JSON object per line) or a pretty report.
Exit code: 0 = no issues, 1 = issues found, 2 = fatal (not a git repo).
"""
import json
import os
import re
import subprocess
import sys
from pathlib import Path

# ── expected values per branch ───────────────────────────────────────────────
BRANCHES = [
    "migrate/elgg-3.x",
    "migrate/elgg-4.x",
    "migrate/elgg-5.x",
    "migrate/elgg-6.x",
    "migrate/elgg-7.x",
]

EXPECTED_ELGG = {
    "migrate/elgg-3.x": {"pattern": r"^3\.|^\^3\.|^~3\.", "label": "3.x"},
    "migrate/elgg-4.x": {"pattern": r"^4\.|^\^4\.|^~4\.", "label": "4.x"},
    "migrate/elgg-5.x": {"pattern": r"^5\.|^\^5\.|^~5\.", "label": "5.x"},
    "migrate/elgg-6.x": {"pattern": r"^6\.|^\^6\.|^~6\.", "label": "6.x"},
    "migrate/elgg-7.x": {"pattern": r"^7\.|^\^7\.|^~7\.", "label": "7.x"},
}

# Minimum acceptable PHP minor version per branch (from Elgg release notes)
MIN_PHP = {
    "migrate/elgg-3.x": (7, 2),
    "migrate/elgg-4.x": (7, 4),
    "migrate/elgg-5.x": (8, 1),
    "migrate/elgg-6.x": (8, 2),
    "migrate/elgg-7.x": (8, 3),
}

PREV_BRANCH = {
    "migrate/elgg-3.x": None,
    "migrate/elgg-4.x": "migrate/elgg-3.x",
    "migrate/elgg-5.x": "migrate/elgg-4.x",
    "migrate/elgg-6.x": "migrate/elgg-5.x",
    "migrate/elgg-7.x": "migrate/elgg-6.x",
}


# ── helpers ───────────────────────────────────────────────────────────────────
def run(cmd, cwd, check=False):
    r = subprocess.run(cmd, cwd=cwd, capture_output=True, text=True)
    if check:
        r.check_returncode()
    return r


def branch_exists(branch, cwd):
    r = run(["git", "show-ref", "--verify", f"refs/heads/{branch}"], cwd)
    if r.returncode == 0:
        return True
    r = run(["git", "show-ref", "--verify", f"refs/remotes/origin/{branch}"], cwd)
    return r.returncode == 0


def is_ancestor(a, b, cwd):
    """Return True if commit/branch a is an ancestor of b."""
    r = run(["git", "merge-base", "--is-ancestor", a, b], cwd)
    return r.returncode == 0


def git_show(branch, path, cwd):
    """Return file contents from a branch, or None if not found."""
    r = run(["git", "show", f"{branch}:{path}"], cwd)
    return r.stdout if r.returncode == 0 else None


def git_ls_files(branch, cwd):
    """Return list of all file paths tracked on branch."""
    r = run(["git", "ls-tree", "-r", "--name-only", branch], cwd)
    if r.returncode != 0:
        return []
    return r.stdout.splitlines()


def parse_php_min(constraint):
    """Extract minimum PHP version tuple from a constraint string like '>=8.1'."""
    m = re.search(r"(\d+)\.(\d+)", constraint)
    if m:
        return (int(m.group(1)), int(m.group(2)))
    return None


def issue(plugin, branch, kind, severity, description, detail=""):
    return {
        "plugin": plugin,
        "branch": branch,
        "type": kind,
        "severity": severity,
        "description": description,
        "detail": detail,
    }


# ── main verification ─────────────────────────────────────────────────────────
def verify(plugin_dir):
    plugin_dir = Path(plugin_dir).resolve()
    plugin = plugin_dir.name
    cwd = str(plugin_dir)

    # Verify it is a git repo
    if run(["git", "rev-parse", "--git-dir"], cwd).returncode != 0:
        print(f"FATAL: {plugin_dir} is not a git repository", file=sys.stderr)
        sys.exit(2)

    issues = []

    for branch in BRANCHES:
        major = branch.split("elgg-")[1]  # e.g. "6.x"
        major_num = major.split(".")[0]    # e.g. "6"

        if not branch_exists(branch, cwd):
            issues.append(issue(plugin, branch, "missing_branch", 2,
                                f"Branch {branch} does not exist"))
            continue

        # ── 1. Linearity ─────────────────────────────────────────────────────
        prev = PREV_BRANCH[branch]
        if prev and branch_exists(prev, cwd):
            if not is_ancestor(prev, branch, cwd):
                issues.append(issue(plugin, branch, "linearity", 1,
                                    f"{branch} is not based on {prev}",
                                    "Run: git log --oneline --graph migrate/elgg-*.x"))

        # ── 2. composer.json ─────────────────────────────────────────────────
        raw_composer = git_show(branch, "composer.json", cwd)
        if raw_composer is None:
            issues.append(issue(plugin, branch, "missing_composer", 1,
                                "composer.json not found"))
            continue

        try:
            composer = json.loads(raw_composer)
        except json.JSONDecodeError as e:
            issues.append(issue(plugin, branch, "invalid_composer", 1,
                                "composer.json is not valid JSON", str(e)))
            continue

        req = composer.get("require", {})

        # elgg/elgg present and matches target major
        elgg_ver = req.get("elgg/elgg", "")
        exp = EXPECTED_ELGG[branch]
        if not elgg_ver:
            issues.append(issue(plugin, branch, "missing_elgg_dep", 1,
                                "elgg/elgg missing from composer.json require"))
        elif not re.search(exp["pattern"], elgg_ver):
            issues.append(issue(plugin, branch, "wrong_elgg_version", 2,
                                f"elgg/elgg='{elgg_ver}' does not match Elgg {exp['label']}",
                                f"Expected pattern: {exp['pattern']}"))

        # php constraint present and >= minimum
        php_ver = req.get("php", "")
        if not php_ver:
            issues.append(issue(plugin, branch, "missing_php_constraint", 2,
                                "PHP version constraint missing from composer.json"))
        else:
            actual = parse_php_min(php_ver)
            expected_min = MIN_PHP[branch]
            if actual and actual < expected_min:
                issues.append(issue(plugin, branch, "php_constraint_too_low", 2,
                                    f"php='{php_ver}' is below minimum {expected_min[0]}.{expected_min[1]} for Elgg {major}",
                                    f"Expected: >={expected_min[0]}.{expected_min[1]}"))

        # ── 3. No start.php on 4.x+ ──────────────────────────────────────────
        if major_num != "3":
            if git_show(branch, "start.php", cwd) is not None:
                issues.append(issue(plugin, branch, "has_start_php", 1,
                                    "start.php must be deleted for Elgg 4+ (causes activation rejection)"))

        # ── 4. Tests exist ───────────────────────────────────────────────────
        all_files = git_ls_files(branch, cwd)
        test_files = [f for f in all_files if f.startswith("tests/") and f.endswith("Test.php")]
        if not test_files:
            issues.append(issue(plugin, branch, "missing_tests", 2,
                                "No tests/*Test.php files found",
                                "All source classes should have corresponding integration/unit tests"))

        # ── 5. Docker infra exists ───────────────────────────────────────────
        docker_files = [f for f in all_files if f.startswith("docker/")]
        if not docker_files:
            issues.append(issue(plugin, branch, "missing_docker", 2,
                                "No docker/ directory found",
                                "Per-plugin Docker test stack required on every migrate branch"))

        # ── 6. README version ────────────────────────────────────────────────
        readme = git_show(branch, "README.md", cwd)
        if readme:
            # Look for "Elgg N" or "elgg N" or badge with version
            if not re.search(rf"elgg[\s\-/]?{major_num}", readme, re.IGNORECASE):
                issues.append(issue(plugin, branch, "readme_version_mismatch", 3,
                                    f"README.md may not mention Elgg {major_num}",
                                    "Docs should reflect the target Elgg version"))

        # ── 7. Source coverage (basic: every class file has a test) ──────────
        class_files = [f for f in all_files
                       if f.startswith("classes/") and f.endswith(".php")]
        if class_files and not test_files:
            issues.append(issue(plugin, branch, "zero_test_coverage", 1,
                                f"{len(class_files)} class file(s) found but zero test files",
                                "Write PHPUnit tests for all source classes"))

    # ── 8. main branch == 7.x ────────────────────────────────────────────────
    for main_name in ("main", "master"):
        if branch_exists(main_name, cwd):
            if branch_exists("migrate/elgg-7.x", cwd):
                if not is_ancestor("migrate/elgg-7.x", main_name, cwd):
                    issues.append(issue(plugin, main_name, "main_not_7x", 1,
                                        f"{main_name} branch does not include migrate/elgg-7.x",
                                        "Run: git checkout main && git merge migrate/elgg-7.x"))
            break

    return issues


# ── entry point ───────────────────────────────────────────────────────────────
if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(f"Usage: {sys.argv[0]} <plugin_dir>", file=sys.stderr)
        sys.exit(1)

    results = verify(sys.argv[1])
    for item in results:
        print(json.dumps(item))

    sys.exit(1 if results else 0)
