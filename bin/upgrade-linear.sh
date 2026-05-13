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
#   --project DIR     Site root (required). Must contain vendor/ and elgg-config/settings.php.
#   --from N          Starting Elgg major version. Auto-detected from vendor/elgg/elgg if omitted.
#   --to N            Target major version (default: 6).
#   --yes             Skip per-step confirmation prompts.
#   --dry-run         Print what would be done without executing.
#   --backup-dir DIR  Where to store DB backups (default: <project>/../elgg-backups/).
#   --site-url URL    Override site URL for curl verification (auto-detected from settings.php).
#
# Branch naming defaults (override via env vars):
#   ELGG_BRANCH_2=main   ELGG_BRANCH_3=migrate/elgg-3.x   ELGG_BRANCH_4=migrate/4.x
#   ELGG_BRANCH_5=migrate/5.x   ELGG_BRANCH_6=migrate/6.x   ELGG_BRANCH_7=migrate/7.x

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

# Branch name for each Elgg major version
ELGG_BRANCH_2="${ELGG_BRANCH_2:-main}"
ELGG_BRANCH_3="${ELGG_BRANCH_3:-migrate/elgg-3.x}"
ELGG_BRANCH_4="${ELGG_BRANCH_4:-migrate/4.x}"
ELGG_BRANCH_5="${ELGG_BRANCH_5:-migrate/5.x}"
ELGG_BRANCH_6="${ELGG_BRANCH_6:-migrate/6.x}"
ELGG_BRANCH_7="${ELGG_BRANCH_7:-migrate/7.x}"

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
if [[ ! -d "$PROJECT/vendor" ]]; then
    echo "ERROR: $PROJECT/vendor not found; run composer install first" >&2
    exit 2
fi
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

branch_for_version() {
    local ver="$1"
    local varname="ELGG_BRANCH_${ver}"
    echo "${!varname:-}"
}

# ---------------------------------------------------------------------------
# Detect current Elgg version
# ---------------------------------------------------------------------------
detect_version() {
    local composer_json="$PROJECT/vendor/elgg/elgg/composer.json"
    if [[ ! -f "$composer_json" ]]; then
        fail "Cannot find $composer_json to detect current Elgg version"
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
# DB backup
# ---------------------------------------------------------------------------
backup_db() {
    local label="$1"
    local outfile="$BACKUP_DIR/elgg-${label}-$(date +%Y%m%d-%H%M%S).sql.gz"
    log "Backing up $DB_NAME → $outfile"
    run "mysqldump -h '$DB_HOST' -P '$DB_PORT' -u '$DB_USER' -p'$DB_PASS' \
        --skip-ssl --single-transaction --quick '$DB_NAME' | gzip > '$outfile'"
    if [[ $DRY_RUN -eq 0 ]]; then
        local size
        size="$(du -sh "$outfile" 2>/dev/null | cut -f1)"
        log "Backup complete: $size"
    fi
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
# Composer install (falls back to --ignore-platform-reqs if platform check fails)
# ---------------------------------------------------------------------------
run_composer() {
    log "Running composer install"
    if [[ $DRY_RUN -eq 1 ]]; then
        echo "  [dry-run] composer install --no-interaction --no-progress --optimize-autoloader --working-dir='$PROJECT'"
        return 0
    fi
    if ! composer install --no-interaction --no-progress --optimize-autoloader \
        --working-dir="$PROJECT" 2>&1; then
        log "Retrying with --ignore-platform-reqs"
        composer install --no-interaction --no-progress --optimize-autoloader \
            --ignore-platform-reqs --working-dir="$PROJECT" 2>&1
    fi
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
        fail "No branch configured for Elgg $to (set ELGG_BRANCH_${to})"
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
