#!/usr/bin/env bash
set -Eeuo pipefail

# Webtrees deployment: GitHub main -> Hostinger.
# Safe defaults: dry-run, no rsync deletion, data/config/media are excluded from code sync.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="${ROOT_DIR}/deploy-config"
DRY_RUN=1
if [[ "${1:-}" == "--live" ]]; then DRY_RUN=0; fi
if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
  echo "Usage: ./deploy.sh [--dry-run|--live]"
  echo "Default is dry-run. --live performs backup and transfers files."
  exit 0
fi

if [[ ! -f "$CONFIG_FILE" ]]; then
  echo "Missing deploy-config. Copy deploy-config.example and fill it in:"
  echo "  cp deploy-config.example deploy-config"
  exit 1
fi
# shellcheck source=/dev/null
source "$CONFIG_FILE"

required=(SSH_HOST SSH_USER SSH_PORT APP_REMOTE PUBLIC_REMOTE BACKUP_REMOTE DB_NAME DB_USER DB_PASSWORD)
for var in "${required[@]}"; do
  if [[ -z "${!var:-}" ]]; then echo "Configuration missing: $var" >&2; exit 1; fi
done

REPO="${REPO:-lisandromelo/webtrees}"
BRANCH="${BRANCH:-main}"
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-3306}"
SSH_OPTS=(-p "$SSH_PORT" -o StrictHostKeyChecking=accept-new)
RSYNC_SSH="ssh -p $SSH_PORT -o StrictHostKeyChecking=accept-new"

if ! command -v git >/dev/null || ! command -v rsync >/dev/null || ! command -v ssh >/dev/null; then
  echo "Required local tools: git, rsync, ssh" >&2; exit 1
fi

if [[ -n "$(git -C "$ROOT_DIR" status --porcelain)" ]]; then
  echo "Warning: working tree has local changes. Deployment source will be fetched from GitHub branch $BRANCH, not your local modifications."
fi

TMP_DIR="$(mktemp -d)"
cleanup(){ rm -rf "$TMP_DIR"; }
trap cleanup EXIT
SOURCE_DIR="$TMP_DIR/source"

echo "Fetching source archive from GitHub: $REPO / $BRANCH"
if ! curl -fsSL "https://codeload.github.com/$REPO/tar.gz/refs/heads/$BRANCH" -o "$TMP_DIR/source.tar.gz"; then
  echo "Could not download GitHub archive. Check repository, branch, and internet access." >&2; exit 1
fi
mkdir "$SOURCE_DIR"
tar -xzf "$TMP_DIR/source.tar.gz" --strip-components=1 -C "$SOURCE_DIR"

# Validate expected Webtrees structure before touching the server.
for path in index.php app modules_v4 public; do
  [[ -e "$SOURCE_DIR/$path" ]] || { echo "Unexpected source archive: missing $path" >&2; exit 1; }
done

echo
echo "Target server: $SSH_USER@$SSH_HOST:$SSH_PORT"
echo "Private application path: $APP_REMOTE"
echo "Public web root: $PUBLIC_REMOTE"
echo "Backup path: $BACKUP_REMOTE"
echo "Source: GitHub $BRANCH"
echo "Mode: $([[ $DRY_RUN -eq 1 ]] && echo DRY-RUN || echo LIVE)"
echo

if [[ $DRY_RUN -eq 0 ]]; then
  echo "Creating remote backup (application/public files + MySQL dump)..."
  BACKUP_STAMP="$(date +%Y%m%d-%H%M%S)"
  ssh "${SSH_OPTS[@]}" "$SSH_USER@$SSH_HOST" \
    "set -e; mkdir -p '$BACKUP_REMOTE'; \
     tar -czf '$BACKUP_REMOTE/webtrees-app-$BACKUP_STAMP.tar.gz' \
       --exclude='data/media' --exclude='public/data/media' \
       -C '$APP_REMOTE' .; \
     tar -czf '$BACKUP_REMOTE/webtrees-public-$BACKUP_STAMP.tar.gz' \
       --exclude='data/media' --exclude='data/media/***' \
       -C '$PUBLIC_REMOTE' .; \
     mysqldump --single-transaction --quick -h '$DB_HOST' -P '$DB_PORT' \
       -u '$DB_USER' --password='$DB_PASSWORD' '$DB_NAME' \
       | gzip > '$BACKUP_REMOTE/webtrees-db-$BACKUP_STAMP.sql.gz'; \
     test -s '$BACKUP_REMOTE/webtrees-db-$BACKUP_STAMP.sql.gz'"
  echo "Backup completed."
fi

echo "Rsync private application files (preserving data/, public/, configuration and local files):"
rsync -avzn --itemize-changes -e "$RSYNC_SSH" \
  --exclude='/data/' --exclude='/public/' --exclude='/.git/' --exclude='/docker/' \
  --exclude='/deploy-config' --exclude='/deploy-config.example' --exclude='/deploy.sh' --exclude='/DEPLOY-HOSTINGER.md' \
  "$SOURCE_DIR/" "$SSH_USER@$SSH_HOST:$APP_REMOTE/"

echo
echo "Rsync public files (preserving data/media and avoiding deletions):"
rsync -avzn --itemize-changes -e "$RSYNC_SSH" \
  --exclude='/data/media' --exclude='/data/media/***' \
  "$SOURCE_DIR/public/" "$SSH_USER@$SSH_HOST:$PUBLIC_REMOTE/"

if [[ $DRY_RUN -eq 1 ]]; then
  echo
  echo "DRY-RUN only. No remote files were changed."
  echo "Review the file list above. To execute the deploy after validation: ./deploy.sh --live"
else
  echo
  echo "Executing rsync transfers..."
  rsync -avz --itemize-changes -e "$RSYNC_SSH" \
    --exclude='/data/' --exclude='/public/' --exclude='/.git/' --exclude='/docker/' \
    --exclude='/deploy-config' --exclude='/deploy-config.example' --exclude='/deploy.sh' \
    "$SOURCE_DIR/" "$SSH_USER@$SSH_HOST:$APP_REMOTE/"
  rsync -avz --itemize-changes -e "$RSYNC_SSH" \
    --exclude='/data/media' --exclude='/data/media/***' \
    "$SOURCE_DIR/public/" "$SSH_USER@$SSH_HOST:$PUBLIC_REMOTE/"
  echo "Deployment files transferred. No database migrations or deletion operations were run."
fi
