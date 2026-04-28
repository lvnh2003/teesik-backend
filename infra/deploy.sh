#!/bin/bash
# ══════════════════════════════════════════════════════════════════════════════
# Deploy Script — Teesik Backend (EC2)
# ══════════════════════════════════════════════════════════════════════════════
# Usage: bash deploy.sh [image_tag]
# Called by GitHub Actions or manually on EC2
# ══════════════════════════════════════════════════════════════════════════════

set -euo pipefail

APP_DIR="/opt/teesik"
COMPOSE_FILE="docker-compose.prod.yml"
ECR_REGISTRY="751871643624.dkr.ecr.ap-southeast-1.amazonaws.com"
ECR_REPO="teesik-backend"
IMAGE_TAG="${1:-latest}"

echo "════════════════════════════════════════════════════════"
echo "  🚀 Deploying Teesik Backend"
echo "  Image: ${ECR_REGISTRY}/${ECR_REPO}:${IMAGE_TAG}"
echo "════════════════════════════════════════════════════════"

cd "$APP_DIR"

# ─── Login to ECR ────────────────────────────────────────────────────────────
echo "🔐 Logging in to ECR..."
aws ecr get-login-password --region ap-southeast-1 | \
    docker login --username AWS --password-stdin "$ECR_REGISTRY"

# ─── Pull new image ──────────────────────────────────────────────────────────
echo "📥 Pulling new image..."
docker pull "${ECR_REGISTRY}/${ECR_REPO}:${IMAGE_TAG}"

# ─── Tag as latest for docker-compose ────────────────────────────────────────
docker tag "${ECR_REGISTRY}/${ECR_REPO}:${IMAGE_TAG}" "${ECR_REPO}:latest"

# ─── Stop old container (keep DB running) ────────────────────────────────────
echo "🔄 Restarting app container..."
docker-compose -f "$COMPOSE_FILE" stop app
docker-compose -f "$COMPOSE_FILE" rm -f app

# ─── Start with new image ───────────────────────────────────────────────────
docker-compose -f "$COMPOSE_FILE" up -d app

# ─── Wait for health check ──────────────────────────────────────────────────
echo "⏳ Waiting for health check..."
MAX_RETRIES=30
RETRY=0
while [ $RETRY -lt $MAX_RETRIES ]; do
    if curl -sf http://localhost:8080/health-check.php > /dev/null 2>&1; then
        echo "✅ App is healthy!"
        break
    fi
    RETRY=$((RETRY + 1))
    echo "   Attempt $RETRY/$MAX_RETRIES..."
    sleep 2
done

if [ $RETRY -ge $MAX_RETRIES ]; then
    echo "❌ Health check failed after $MAX_RETRIES attempts!"
    echo "📋 Container logs:"
    docker-compose -f "$COMPOSE_FILE" logs --tail=50 app
    exit 1
fi

# ─── Cleanup old images ─────────────────────────────────────────────────────
echo "🧹 Cleaning up old images..."
docker image prune -f

echo ""
echo "════════════════════════════════════════════════════════"
echo "  ✅ Deployment Complete!"
echo "  Tag: ${IMAGE_TAG}"
echo "════════════════════════════════════════════════════════"
