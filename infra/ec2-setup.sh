#!/bin/bash
# ══════════════════════════════════════════════════════════════════════════════
# EC2 Bootstrap Script — Teesik Backend
# ══════════════════════════════════════════════════════════════════════════════
# Run this on a fresh Amazon Linux 2023 / Ubuntu 22.04 EC2 instance
# Usage: sudo bash ec2-setup.sh
# ══════════════════════════════════════════════════════════════════════════════

set -euo pipefail

echo "════════════════════════════════════════════════════════"
echo "  🚀 Teesik Backend — EC2 Setup"
echo "════════════════════════════════════════════════════════"

# ─── Detect OS ────────────────────────────────────────────────────────────────
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$ID
else
    echo "❌ Cannot detect OS. Exiting."
    exit 1
fi

echo "📋 Detected OS: $OS"

# ─── Update system ────────────────────────────────────────────────────────────
echo "📦 Updating system packages..."
if [[ "$OS" == "amzn" || "$OS" == "amazon" ]]; then
    yum update -y
elif [[ "$OS" == "ubuntu" || "$OS" == "debian" ]]; then
    apt-get update && apt-get upgrade -y
fi

# ─── Install Docker ──────────────────────────────────────────────────────────
echo "🐳 Installing Docker..."
if [[ "$OS" == "amzn" || "$OS" == "amazon" ]]; then
    yum install -y docker
    systemctl start docker
    systemctl enable docker
    usermod -aG docker ec2-user
elif [[ "$OS" == "ubuntu" || "$OS" == "debian" ]]; then
    apt-get install -y ca-certificates curl gnupg
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/$OS/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg
    echo \
      "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/$OS \
      $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
      tee /etc/apt/sources.list.d/docker.list > /dev/null
    apt-get update
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    usermod -aG docker ubuntu
fi

# ─── Install Docker Compose (standalone) ─────────────────────────────────────
echo "🐳 Installing Docker Compose..."
COMPOSE_VERSION=$(curl -s https://api.github.com/repos/docker/compose/releases/latest | grep tag_name | cut -d '"' -f 4)
curl -L "https://github.com/docker/compose/releases/download/${COMPOSE_VERSION}/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
chmod +x /usr/local/bin/docker-compose
ln -sf /usr/local/bin/docker-compose /usr/bin/docker-compose

# ─── Install Nginx ───────────────────────────────────────────────────────────
echo "🌐 Installing Nginx..."
if [[ "$OS" == "amzn" || "$OS" == "amazon" ]]; then
    yum install -y nginx
elif [[ "$OS" == "ubuntu" || "$OS" == "debian" ]]; then
    apt-get install -y nginx
fi
systemctl enable nginx

# ─── Install Certbot (Let's Encrypt SSL) ─────────────────────────────────────
echo "🔒 Installing Certbot for SSL..."
if [[ "$OS" == "amzn" || "$OS" == "amazon" ]]; then
    yum install -y certbot python3-certbot-nginx
elif [[ "$OS" == "ubuntu" || "$OS" == "debian" ]]; then
    apt-get install -y certbot python3-certbot-nginx
fi

# ─── Install useful tools ────────────────────────────────────────────────────
echo "🔧 Installing useful tools..."
if [[ "$OS" == "amzn" || "$OS" == "amazon" ]]; then
    yum install -y git htop mysql
elif [[ "$OS" == "ubuntu" || "$OS" == "debian" ]]; then
    apt-get install -y git htop mysql-client awscli
fi

# ─── Setup swap (important for t3.micro with 1GB RAM) ────────────────────────
echo "💾 Setting up 1GB swap..."
if [ ! -f /swapfile ]; then
    fallocate -l 1G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
    # Optimize swap behavior
    echo 'vm.swappiness=10' >> /etc/sysctl.conf
    echo 'vm.vfs_cache_pressure=50' >> /etc/sysctl.conf
    sysctl -p
    echo "✅ Swap enabled (1GB)"
else
    echo "⏭️  Swap already exists, skipping"
fi

# ─── Create app directory ────────────────────────────────────────────────────
echo "📁 Creating app directory..."
mkdir -p /opt/teesik
mkdir -p /opt/teesik/mysql-data
mkdir -p /opt/teesik/mysql-backup
mkdir -p /opt/teesik/logs

# ─── Setup Nginx config ─────────────────────────────────────────────────────
echo "⚙️  Configuring Nginx..."
cat > /etc/nginx/conf.d/teesik-api.conf << 'NGINX_CONF'
server {
    listen 80;
    server_name api.teesik.store;

    # Redirect to HTTPS (after SSL is set up)
    # Uncomment after running certbot:
    # return 301 https://$server_name$request_uri;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # Timeouts
        proxy_connect_timeout 60s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;

        # CORS headers (Laravel handles most, but just in case)
        add_header X-Frame-Options "SAMEORIGIN" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header X-XSS-Protection "1; mode=block" always;
    }

    # Health check endpoint
    location /health {
        proxy_pass http://127.0.0.1:8080/health;
        access_log off;
    }

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml;

    # Rate limiting
    limit_req_zone $binary_remote_addr zone=api:10m rate=30r/s;
    location /api/ {
        limit_req zone=api burst=50 nodelay;
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # Block common attacks
    location ~ /\.(?!well-known) {
        deny all;
    }
}
NGINX_CONF

# Test and reload nginx
nginx -t && systemctl restart nginx

# ─── Setup daily backup cron ─────────────────────────────────────────────────
echo "⏰ Setting up daily backup cron..."
cat > /etc/cron.d/teesik-backup << 'CRON'
# Daily MySQL backup at 3:00 AM
0 3 * * * root /opt/teesik/backup-db.sh >> /opt/teesik/logs/backup.log 2>&1
CRON

# ─── Firewall ────────────────────────────────────────────────────────────────
echo "🔥 Note: Configure Security Group in AWS Console:"
echo "   - SSH (22)  : Your IP only"
echo "   - HTTP (80) : 0.0.0.0/0"
echo "   - HTTPS(443): 0.0.0.0/0"

echo ""
echo "════════════════════════════════════════════════════════"
echo "  ✅ EC2 Setup Complete!"
echo "════════════════════════════════════════════════════════"
echo ""
echo "  Next steps:"
echo "  1. Copy docker-compose.prod.yml to /opt/teesik/"
echo "  2. Create .env.production in /opt/teesik/"
echo "  3. Run: cd /opt/teesik && docker-compose -f docker-compose.prod.yml up -d"
echo "  4. Setup SSL: sudo certbot --nginx -d api.teesik.store"
echo "  5. Update Route 53 A record → EC2 Elastic IP"
echo ""
