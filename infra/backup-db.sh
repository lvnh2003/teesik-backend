#!/bin/bash
# ══════════════════════════════════════════════════════════════════════════════
# MySQL Daily Backup Script — Teesik
# ══════════════════════════════════════════════════════════════════════════════
# Runs via cron daily at 3:00 AM (see ec2-setup.sh)
# Backs up MySQL → local file → uploads to S3
# Retains 7 days of backups locally, S3 lifecycle handles remote retention
# ══════════════════════════════════════════════════════════════════════════════

set -euo pipefail

# ─── Config ──────────────────────────────────────────────────────────────────
BACKUP_DIR="/opt/teesik/mysql-backup"
S3_BUCKET="teesik-db-backups"
S3_PREFIX="mysql"
RETENTION_DAYS=7
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="teesik_${TIMESTAMP}.sql.gz"
CONTAINER_NAME="teesik-db"

# Load env vars
if [ -f /opt/teesik/.env.production ]; then
    source /opt/teesik/.env.production
fi

DB_NAME="${DB_DATABASE:-teesik}"
DB_USER="${DB_USERNAME:-root}"
DB_PASS="${DB_ROOT_PASSWORD:-${DB_PASSWORD:-}}"

echo "[$(date)] Starting backup..."

# ─── Create backup directory ─────────────────────────────────────────────────
mkdir -p "$BACKUP_DIR"

# ─── Dump database ──────────────────────────────────────────────────────────
echo "[$(date)] Dumping database '$DB_NAME'..."
docker exec "$CONTAINER_NAME" mysqldump \
    -u"$DB_USER" \
    -p"$DB_PASS" \
    --single-transaction \
    --routines \
    --triggers \
    --databases "$DB_NAME" | gzip > "${BACKUP_DIR}/${BACKUP_FILE}"

BACKUP_SIZE=$(du -h "${BACKUP_DIR}/${BACKUP_FILE}" | cut -f1)
echo "[$(date)] Backup created: ${BACKUP_FILE} (${BACKUP_SIZE})"

# ─── Upload to S3 ───────────────────────────────────────────────────────────
echo "[$(date)] Uploading to S3..."
if aws s3 cp "${BACKUP_DIR}/${BACKUP_FILE}" "s3://${S3_BUCKET}/${S3_PREFIX}/${BACKUP_FILE}" --storage-class STANDARD_IA; then
    echo "[$(date)] ✅ Uploaded to s3://${S3_BUCKET}/${S3_PREFIX}/${BACKUP_FILE}"
else
    echo "[$(date)] ⚠️  S3 upload failed. Backup is still available locally."
fi

# ─── Cleanup old local backups ───────────────────────────────────────────────
echo "[$(date)] Cleaning up backups older than ${RETENTION_DAYS} days..."
find "$BACKUP_DIR" -name "teesik_*.sql.gz" -mtime +${RETENTION_DAYS} -delete

echo "[$(date)] ✅ Backup complete!"
