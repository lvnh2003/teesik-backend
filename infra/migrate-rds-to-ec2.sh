#!/bin/bash
# ══════════════════════════════════════════════════════════════════════════════
# Migrate RDS → EC2 MySQL — One-time Script
# ══════════════════════════════════════════════════════════════════════════════
# Prerequisites:
#   1. EC2 MySQL container must be running (docker-compose.prod.yml)
#   2. EC2 must have network access to RDS (same VPC or public access)
#   3. mysql client installed on EC2
# ══════════════════════════════════════════════════════════════════════════════

set -euo pipefail

# ─── Config ──────────────────────────────────────────────────────────────────
# RDS source (fill in before running)
RDS_HOST="${RDS_HOST:-your-rds-endpoint.ap-southeast-1.rds.amazonaws.com}"
RDS_PORT="${RDS_PORT:-3306}"
RDS_USER="${RDS_USER:-teesik_admin}"
RDS_PASS="${RDS_PASS:-}"
RDS_DB="${RDS_DB:-teesik}"

# EC2 MySQL target
EC2_CONTAINER="teesik-db"
EC2_USER="${DB_USERNAME:-teesik_admin}"
EC2_PASS="${DB_PASSWORD:-}"
EC2_ROOT_PASS="${DB_ROOT_PASSWORD:-}"
EC2_DB="${DB_DATABASE:-teesik}"

DUMP_FILE="/tmp/teesik_rds_dump.sql"

echo "════════════════════════════════════════════════════════"
echo "  📦 Migrate RDS → EC2 MySQL"
echo "════════════════════════════════════════════════════════"
echo ""
echo "  Source: ${RDS_HOST}:${RDS_PORT}/${RDS_DB}"
echo "  Target: Docker container '${EC2_CONTAINER}'/${EC2_DB}"
echo ""

# ─── Confirm ─────────────────────────────────────────────────────────────────
read -p "⚠️  This will OVERWRITE the target database. Continue? (yes/no): " confirm
if [ "$confirm" != "yes" ]; then
    echo "Cancelled."
    exit 0
fi

# ─── Step 1: Dump from RDS ──────────────────────────────────────────────────
echo ""
echo "📤 Step 1: Dumping from RDS..."
mysqldump \
    -h "$RDS_HOST" \
    -P "$RDS_PORT" \
    -u "$RDS_USER" \
    -p"$RDS_PASS" \
    --single-transaction \
    --routines \
    --triggers \
    --set-gtid-purged=OFF \
    "$RDS_DB" > "$DUMP_FILE"

DUMP_SIZE=$(du -h "$DUMP_FILE" | cut -f1)
echo "   ✅ Dump complete: ${DUMP_FILE} (${DUMP_SIZE})"

# ─── Step 2: Import to EC2 MySQL ────────────────────────────────────────────
echo ""
echo "📥 Step 2: Importing to EC2 MySQL container..."
docker exec -i "$EC2_CONTAINER" mysql \
    -u root \
    -p"$EC2_ROOT_PASS" \
    "$EC2_DB" < "$DUMP_FILE"

echo "   ✅ Import complete!"

# ─── Step 3: Verify ─────────────────────────────────────────────────────────
echo ""
echo "🔍 Step 3: Verifying migration..."
echo "   Tables in EC2 MySQL:"
docker exec "$EC2_CONTAINER" mysql \
    -u root \
    -p"$EC2_ROOT_PASS" \
    -e "USE ${EC2_DB}; SHOW TABLES;" 2>/dev/null | tail -n +2

echo ""
echo "   Row counts:"
docker exec "$EC2_CONTAINER" mysql \
    -u root \
    -p"$EC2_ROOT_PASS" \
    -e "
    SELECT table_name, table_rows
    FROM information_schema.tables
    WHERE table_schema = '${EC2_DB}'
    ORDER BY table_rows DESC;" 2>/dev/null

# ─── Cleanup ─────────────────────────────────────────────────────────────────
echo ""
read -p "🧹 Delete dump file? (yes/no): " cleanup
if [ "$cleanup" == "yes" ]; then
    rm -f "$DUMP_FILE"
    echo "   Dump file deleted."
fi

echo ""
echo "════════════════════════════════════════════════════════"
echo "  ✅ Migration Complete!"
echo "════════════════════════════════════════════════════════"
echo ""
echo "  Next steps:"
echo "  1. Test the application thoroughly"
echo "  2. Update DNS to point to EC2"
echo "  3. Monitor for 24-48 hours"
echo "  4. Only then delete the RDS instance"
echo ""
