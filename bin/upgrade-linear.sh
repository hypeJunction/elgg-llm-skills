#!/usr/bin/env bash
# upgrade-linear.sh — run an Elgg site through sequential major-version upgrades
#
# Run this on the server where the site is installed, with access to the production database.
# The site root must be a git repository with migration branches already prepared (Part A of
# elgg-site-upgrade). Upgrades one major version at a time, in order, with DB backup before
# each step.
#
# Usage:
#   bin/upgrade-linear.sh --project /path/to/site [--from 5] [--to 6] [--yes]
#
# Options:
#   --project DIR     Site root (required). Must contain elgg-config/settings.php.
#   --from N          Starting Elgg major version. Auto-detected from vendor/elgg/elgg if omitted.
#   --to N            Target major version (default: 6).
#   --yes             Skip per-step confirmation prompts.
#   --dry-run         Print what would be done without executing.
#   --backup-dir DIR  Where to store DB backups (default: <project>/../elgg-backups/).
#   --site-url URL    Override site URL for curl verification (auto-detected from settings.php).
#
# Branch auto-detection (for each target version N, tried in order):
#   1. ELGG_BRANCH_N env var (explicit override)
#   2. migrate/elgg-N.x
#   3. migrate/N.x
#   4. elgg-N.x
#   5. N.x
#   For version 2 specifically: also tries master and main as fallbacks.
#
# Examples of branch names the auto-detection handles:
#   elgg-migrate default : migrate/elgg-3.x, migrate/4.x, migrate/5.x, migrate/6.x
#   semver style         : 3.x, 4.x, 5.x, 6.x
#   prefixed style       : elgg-3.x, elgg-4.x

set -euo pipefail

# ---------------------------------------------------------------------------
# Defaults
# ---------------------------------------------------------------------------
PROJECT=""
FROM_VER=""
TO_VER=6
DRY_RUN=0
YES=0
BACKUP_DIR=""
SITE_URL_OVERRIDE=""

# Per-version branch overrides (empty = auto-detect from the git repo)
ELGG_BRANCH_2="${ELGG_BRANCH_2:-}"
ELGG_BRANCH_3="${ELGG_BRANCH_3:-}"
ELGG_BRANCH_4="${ELGG_BRANCH_4:-}"
ELGG_BRANCH_5="${ELGG_BRANCH_5:-}"
ELGG_BRANCH_6="${ELGG_BRANCH_6:-}"
ELGG_BRANCH_7="${ELGG_BRANCH_7:-}"

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
while [[ $# -gt 0 ]]; do
    case "$1" in
        --project)    PROJECT="$2"; shift 2 ;;
        --from)       FROM_VER="$2"; shift 2 ;;
        --to)         TO_VER="$2"; shift 2 ;;
        --yes|-y)     YES=1; shift ;;
        --dry-run)    DRY_RUN=1; shift ;;
        --backup-dir) BACKUP_DIR="$2"; shift 2 ;;
        --site-url)   SITE_URL_OVERRIDE="$2"; shift 2 ;;
        -h|--help)
            sed -n '2,28p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "ERROR: unknown argument: $1" >&2
            exit 2
            ;;
    esac
done

# ---------------------------------------------------------------------------
# Validation
# ---------------------------------------------------------------------------
if [[ -z "$PROJECT" ]]; then
    echo "ERROR: --project is required" >&2
    exit 2
fi
PROJECT="$(realpath "$PROJECT")"
if [[ ! -f "$PROJECT/elgg-config/settings.php" ]]; then
    echo "ERROR: $PROJECT/elgg-config/settings.php not found" >&2
    exit 2
fi
if [[ -z "$(git -C "$PROJECT" rev-parse --git-dir 2>/dev/null || true)" ]]; then
    echo "ERROR: $PROJECT is not a git repository" >&2
    exit 2
fi

BACKUP_DIR="${BACKUP_DIR:-$PROJECT/../elgg-backups}"
mkdir -p "$BACKUP_DIR"
BACKUP_DIR="$(realpath "$BACKUP_DIR")"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
log()  { echo "[$(date '+%H:%M:%S')] $*"; }
info() { echo ""; log "==> $*"; }
warn() { echo "[$(date '+%H:%M:%S')] WARN: $*" >&2; }
fail() { echo "[$(date '+%H:%M:%S')] FATAL: $*" >&2; exit 1; }
run()  {
    if [[ $DRY_RUN -eq 1 ]]; then
        echo "  [dry-run] $*"
    else
        eval "$@"
    fi
}

confirm() {
    local msg="$1"
    if [[ $YES -eq 1 || $DRY_RUN -eq 1 ]]; then
        log "Confirmed: $msg"
        return 0
    fi
    echo ""
    echo "  $msg"
    echo -n "  Proceed? [y/N] "
    read -r answer
    if [[ "$answer" =~ ^[Yy]$ ]]; then
        return 0
    fi
    echo "  Aborted."
    return 1
}

# Return the git branch name to check out for target Elgg major version N.
# Priority: explicit ELGG_BRANCH_N env var → probe candidates in the git repo.
branch_for_version() {
    local ver="$1"
    local varname="ELGG_BRANCH_${ver}"
    local override="${!varname:-}"
    if [[ -n "$override" ]]; then
        echo "$override"
        return 0
    fi

    # Candidate branch names in preference order (covers elgg-migrate convention,
    # semver-style, plain major, and legacy master/main for 2.x)
    local -a candidates=(
        "migrate/elgg-${ver}.x"
        "migrate/${ver}.x"
        "elgg-${ver}.x"
        "${ver}.x"
    )
    if [[ "$ver" == "2" ]]; then
        candidates+=("master" "main")
    fi

    for branch in "${candidates[@]}"; do
        if git -C "$PROJECT" rev-parse --verify "origin/$branch" &>/dev/null \
        || git -C "$PROJECT" rev-parse --verify "$branch" &>/dev/null; then
            echo "$branch"
            return 0
        fi
    done

    return 1  # caller will report the failure
}

# ---------------------------------------------------------------------------
# Detect current Elgg version
# ---------------------------------------------------------------------------
detect_version() {
    local composer_json="$PROJECT/vendor/elgg/elgg/composer.json"
    if [[ ! -f "$composer_json" ]]; then
        fail "Cannot auto-detect Elgg version: $composer_json not found. Pass --from N explicitly."
    fi
    php -r "
        \$j = json_decode(file_get_contents('$composer_json'), true);
        \$v = \$j['version'] ?? \$j['extra']['branch-alias']['dev-master'] ?? '';
        if (preg_match('/^(\d+)\./', \$v, \$m)) { echo \$m[1]; } else { exit(1); }
    " || fail "Could not parse Elgg version from $composer_json"
}

# ---------------------------------------------------------------------------
# Read DB credentials from settings.php
# ---------------------------------------------------------------------------
read_settings() {
    php -r "
        global \$CONFIG;
        \$CONFIG = new stdClass;
        require '$PROJECT/elgg-config/settings.php';
        echo json_encode([
            'host'   => \$CONFIG->dbhost   ?? 'localhost',
            'name'   => \$CONFIG->dbname   ?? '',
            'user'   => \$CONFIG->dbuser   ?? '',
            'pass'   => \$CONFIG->dbpass   ?? '',
            'prefix' => \$CONFIG->dbprefix ?? 'elgg_',
            'port'   => \$CONFIG->dbport   ?? '3306',
            'wwwroot'=> \$CONFIG->wwwroot  ?? '',
        ]);
    " 2>/dev/null
}

# Parse JSON settings into shell vars (DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PREFIX, SITE_URL)
load_db_settings() {
    local json
    json="$(read_settings)" || fail "Failed to read settings.php"
    DB_HOST="$(  echo "$json" | php -r "echo json_decode(file_get_contents('php://stdin'),true)['host'];")"
    DB_PORT="$(  echo "$json" | php -r "echo json_decode(file_get_contents('php://stdin'),true)['port'];")"
    DB_NAME="$(  echo "$json" | php -r "echo json_decode(file_get_contents('php://stdin'),true)['name'];")"
    DB_USER="$(  echo "$json" | php -r "echo json_decode(file_get_contents('php://stdin'),true)['user'];")"
    DB_PASS="$(  echo "$json" | php -r "echo json_decode(file_get_contents('php://stdin'),true)['pass'];")"
    DB_PREFIX="$(echo "$json" | php -r "echo json_decode(file_get_contents('php://stdin'),true)['prefix'];")"
    SITE_URL="$( echo "$json" | php -r "echo json_decode(file_get_contents('php://stdin'),true)['wwwroot'];")"
    if [[ -n "$SITE_URL_OVERRIDE" ]]; then
        SITE_URL="$SITE_URL_OVERRIDE"
    fi
}

# ---------------------------------------------------------------------------
# DB backup — full dump of all tables and data, compressed, integrity-verified
# ---------------------------------------------------------------------------
backup_db() {
    local label="$1"
    local outfile="$BACKUP_DIR/elgg-${label}-$(date +%Y%m%d-%H%M%S).sql.gz"
    log "Backing up $DB_NAME → $outfile"

    if [[ $DRY_RUN -eq 1 ]]; then
        echo "  [dry-run] mysqldump ... $DB_NAME | gzip > $outfile"
        return 0
    fi

    # --no-tablespaces avoids needing PROCESS privilege (otherwise mysqldump
    # fails with "Access denied for PROCESS" on MySQL 5.7.31+ / MariaDB 10.5+).
    # --single-transaction gives a consistent snapshot without locking tables.
    # --routines --triggers includes stored procedures and triggers.
    mysqldump \
        -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" \
        --skip-ssl \
        --no-tablespaces \
        --single-transaction \
        --routines \
        --triggers \
        --quick \
        "$DB_NAME" | gzip > "$outfile"

    local dump_rc="${PIPESTATUS[0]}"
    if [[ $dump_rc -ne 0 ]]; then
        warn "mysqldump exited $dump_rc — backup may be incomplete: $outfile"
        return 1
    fi

    # Verify the gzip archive is not corrupt
    if ! gzip -t "$outfile" 2>/dev/null; then
        warn "Backup file failed gzip integrity check: $outfile"
        return 1
    fi

    local size tables
    size="$(du -sh "$outfile" | cut -f1)"
    tables="$(zcat "$outfile" | grep -c '^CREATE TABLE' || true)"
    log "Backup complete: $size, $tables tables → $outfile"
}

# ---------------------------------------------------------------------------
# Maintenance mode (append/remove a clearly-marked block in settings.php)
# ---------------------------------------------------------------------------
MAINT_MARKER="# upgrade-linear maintenance mode"

enable_maintenance() {
    log "Enabling maintenance mode"
    if grep -q "$MAINT_MARKER" "$PROJECT/elgg-config/settings.php" 2>/dev/null; then
        return 0  # already set
    fi
    if [[ $DRY_RUN -eq 0 ]]; then
        printf "\n%s\n\$CONFIG->elgg_maintenance_mode = 1;\n" "$MAINT_MARKER" \
            >> "$PROJECT/elgg-config/settings.php"
    else
        echo "  [dry-run] append maintenance mode to settings.php"
    fi
}

disable_maintenance() {
    log "Disabling maintenance mode"
    if [[ $DRY_RUN -eq 0 ]]; then
        # Remove the marker line and the $CONFIG line that follows it
        sed -i "/$MAINT_MARKER/{N;d}" "$PROJECT/elgg-config/settings.php"
    else
        echo "  [dry-run] remove maintenance mode from settings.php"
    fi
}

# ---------------------------------------------------------------------------
# Git: check out migration branch and pull latest
# ---------------------------------------------------------------------------
checkout_branch() {
    local branch="$1"
    log "Checking out branch: $branch"
    run "git -C '$PROJECT' fetch --quiet origin"
    run "git -C '$PROJECT' checkout '$branch'"
    run "git -C '$PROJECT' pull --quiet origin '$branch' || true"
}

# ---------------------------------------------------------------------------
# Composer install — three-tier fallback for migration scenarios:
#   1. install (from lock file, exact versions)
#   2. install --ignore-platform-reqs (when PHP extensions / version mismatch)
#   3. update (when lock file is stale or out of sync with composer.json)
# ---------------------------------------------------------------------------
run_composer() {
    log "Running composer install"
    if [[ $DRY_RUN -eq 1 ]]; then
        echo "  [dry-run] composer install --no-interaction --no-progress --optimize-autoloader --working-dir='$PROJECT'"
        return 0
    fi

    local common_flags="--no-interaction --no-progress --optimize-autoloader --working-dir=$PROJECT"

    if composer install $common_flags 2>&1; then
        return 0
    fi
    log "Retrying with --ignore-platform-reqs"
    if composer install $common_flags --ignore-platform-reqs 2>&1; then
        return 0
    fi
    log "Lock file appears stale — running composer update"
    composer update $common_flags --ignore-platform-reqs 2>&1
}

# ---------------------------------------------------------------------------
# Known schema pre-fixes per migration step
# ---------------------------------------------------------------------------
schema_prefix() {
    local from="$1" to="$2"
    local step="${from}_${to}"

    case "$step" in
        5_6)
            # Elgg 6.x queries e.deleted during bootstrap before Phinx can add the column.
            # MySQL 5.7 doesn't support ADD COLUMN IF NOT EXISTS — guard with SHOW COLUMNS.
            log "Applying schema pre-fix: add entities.deleted column (5→6)"
            if [[ $DRY_RUN -eq 1 ]]; then
                echo "  [dry-run] ALTER TABLE ${DB_PREFIX}entities ADD COLUMN deleted/time_deleted"
            else
                local sql
                sql="SET @col_exists=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='${DB_PREFIX}entities' AND COLUMN_NAME='deleted');SET @sql=IF(@col_exists=0,'ALTER TABLE \`${DB_PREFIX}entities\` ADD COLUMN \`deleted\` ENUM(''yes'',''no'') NOT NULL DEFAULT ''no'', ADD COLUMN \`time_deleted\` INT(11) NOT NULL DEFAULT 0','SELECT 1');PREPARE stmt FROM @sql;EXECUTE stmt;DEALLOCATE PREPARE stmt;"
                echo "$sql" | mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" \
                    --skip-ssl "$DB_NAME"
            fi
            ;;
        *)
            # No pre-fix needed for this step
            ;;
    esac
}

# ---------------------------------------------------------------------------
# Run Elgg upgrade (cd into project so elgg-cli resolves paths from CWD)
# ---------------------------------------------------------------------------
run_upgrade() {
    log "Running elgg-cli upgrade async"
    run "(cd '$PROJECT' && php vendor/bin/elgg-cli upgrade async -v)"
    log "Flushing caches"
    run "(cd '$PROJECT' && php vendor/bin/elgg-cli cache:clear)" || true
}

# ---------------------------------------------------------------------------
# Verify the site loads
# ---------------------------------------------------------------------------
verify_site() {
    local url="${SITE_URL%/}/"
    log "Verifying site at $url"
    if [[ $DRY_RUN -eq 1 ]]; then
        echo "  [dry-run] curl -sS -o /dev/null -w '%{http_code}' $url"
        return 0
    fi
    local code
    code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 30 "$url" 2>/dev/null || echo 000)"
    if [[ "$code" =~ ^(200|301|302|303|307|308)$ ]]; then
        log "Site OK — HTTP $code"
    else
        warn "Site returned HTTP $code — check error logs"
        return 1
    fi
}

# ---------------------------------------------------------------------------
# Execute one migration step: from → to
# ---------------------------------------------------------------------------
do_step() {
    local from="$1" to="$2"
    local branch
    branch="$(branch_for_version "$to")"
    if [[ -z "$branch" ]]; then
        fail "Cannot find a migration branch for Elgg ${to}.x in $PROJECT. Tried: migrate/elgg-${to}.x, migrate/${to}.x, elgg-${to}.x, ${to}.x. Set ELGG_BRANCH_${to}=<branch> to override."
    fi

    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "  Step: Elgg ${from}.x → ${to}.x  (branch: $branch)"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

    confirm "Step Elgg ${from}.x → ${to}.x: backup DB, checkout $branch, run upgrade" || return 1

    # Abort helper: always disables maintenance before returning failure
    abort_step() {
        warn "$1"
        disable_maintenance 2>/dev/null || true
        warn "DB backup is at $BACKUP_DIR"
        return 1
    }

    info "1/7 Backup"
    backup_db "before-${to}x" || { warn "Backup failed — aborting step"; return 1; }

    info "2/7 Enable maintenance mode"
    enable_maintenance

    info "3/7 Checkout $branch"
    checkout_branch "$branch" || { abort_step "git checkout failed"; return 1; }

    info "4/7 Composer install"
    run_composer || { abort_step "composer install failed"; return 1; }

    info "5/7 Schema pre-fix"
    schema_prefix "$from" "$to" || { abort_step "Schema pre-fix failed"; return 1; }

    info "6/7 Upgrade"
    run_upgrade || { abort_step "elgg-cli upgrade failed"; return 1; }

    info "7/7 Verify"
    if ! verify_site; then
        warn "Verification failed after upgrade ${from}→${to}."
        disable_maintenance
        warn "DB backup is at $BACKUP_DIR"
        return 1
    fi

    disable_maintenance
    log "Step ${from}→${to} complete."
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
load_db_settings

if [[ -z "$FROM_VER" ]]; then
    FROM_VER="$(detect_version)"
    log "Auto-detected current Elgg version: ${FROM_VER}.x"
fi

if [[ $FROM_VER -ge $TO_VER ]]; then
    log "Site is already at Elgg ${FROM_VER}.x, target is ${TO_VER}.x — nothing to do."
    exit 0
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Elgg Linear Upgrade"
echo "  Project: $PROJECT"
echo "  Path:    Elgg ${FROM_VER}.x → Elgg ${TO_VER}.x"
echo "  DB:      ${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME}"
echo "  Site:    ${SITE_URL}"
echo "  Backups: $BACKUP_DIR"
if [[ $DRY_RUN -eq 1 ]]; then
echo "  Mode:    DRY RUN (no changes will be made)"
fi
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

for (( ver=FROM_VER; ver<TO_VER; ver++ )); do
    do_step "$ver" "$((ver + 1))" || {
        echo ""
        echo "ERROR: Step ${ver}→$((ver+1)) failed. Upgrade halted." >&2
        echo "       Remaining steps: $((ver+1)) → ${TO_VER}" >&2
        echo "       DB backup before this step: $BACKUP_DIR" >&2
        exit 1
    }
done

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  All steps complete. Site is now at Elgg ${TO_VER}.x."
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
