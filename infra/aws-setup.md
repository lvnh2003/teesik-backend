# 🏗️ AWS Infrastructure Setup Guide — Teesik

> Hướng dẫn từng bước tạo hạ tầng AWS cho Teesik (Frontend + Backend).
> Thực hiện theo thứ tự từ trên xuống.

---

## 📋 Prerequisites

- [x] AWS Account (free tier: https://aws.amazon.com/free)
- [x] AWS CLI installed (`brew install awscli` / `pip install awscli`)
- [x] Configured AWS CLI: `aws configure`

---

## 1. IAM — Tạo user cho GitHub Actions

```bash
# Tạo IAM user
aws iam create-user --user-name github-actions-teesik

# Attach policy (sử dụng file infra/iam-policy.json)
aws iam put-user-policy \
  --user-name github-actions-teesik \
  --policy-name TeesikCICDPolicy \
  --policy-document file://infra/iam-policy.json

# Tạo access key
aws iam create-access-key --user-name github-actions-teesik
# ⚠️ LƯU LẠI Access Key ID và Secret Access Key
```

→ Lưu `AccessKeyId` và `SecretAccessKey` vào GitHub Secrets.

---

## 2. ECR — Docker Container Registry

```bash
# Tạo ECR repository cho backend
aws ecr create-repository \
  --repository-name teesik-backend \
  --region ap-southeast-1 \
  --image-scanning-configuration scanOnPush=true

# Kết quả trả về repositoryUri, ví dụ:
# 123456789012.dkr.ecr.ap-southeast-1.amazonaws.com/teesik-backend
```

→ Lưu `ECR_REPOSITORY=teesik-backend` vào GitHub Secrets.

---

## 3. ECS — Cluster & Service

### 3a. Tạo Cluster

```bash
aws ecs create-cluster \
  --cluster-name teesik \
  --region ap-southeast-1
```

### 3b. Tạo CloudWatch Log Group

```bash
aws logs create-log-group \
  --log-group-name /ecs/teesik-backend \
  --region ap-southeast-1
```

### 3c. Tạo Task Execution Role

```bash
# Tạo role
aws iam create-role \
  --role-name ecsTaskExecutionRole \
  --assume-role-policy-document '{
    "Version": "2012-10-17",
    "Statement": [{
      "Effect": "Allow",
      "Principal": { "Service": "ecs-tasks.amazonaws.com" },
      "Action": "sts:AssumeRole"
    }]
  }'

# Attach policy
aws iam attach-role-policy \
  --role-name ecsTaskExecutionRole \
  --policy-arn arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy

# Thêm quyền đọc SSM Parameters (cho secrets)
aws iam attach-role-policy \
  --role-name ecsTaskExecutionRole \
  --policy-arn arn:aws:iam::aws:policy/AmazonSSMReadOnlyAccess
```

### 3d. Lưu Secrets vào SSM Parameter Store

```bash
# Laravel APP_KEY
aws ssm put-parameter \
  --name "/teesik/APP_KEY" \
  --value "base64:YOUR_APP_KEY_HERE" \
  --type SecureString \
  --region ap-southeast-1

# Database credentials
aws ssm put-parameter \
  --name "/teesik/DB_HOST" \
  --value "teesik-db.xxxxxx.ap-southeast-1.rds.amazonaws.com" \
  --type SecureString

aws ssm put-parameter \
  --name "/teesik/DB_USERNAME" \
  --value "teesik_admin" \
  --type SecureString

aws ssm put-parameter \
  --name "/teesik/DB_PASSWORD" \
  --value "YOUR_STRONG_PASSWORD" \
  --type SecureString
```

### 3e. Register Task Definition

> Trước tiên, sửa `infra/ecs-task-def.json`:
> - Thay `YOUR_ACCOUNT_ID` bằng AWS Account ID thực tế
> - Cập nhật region nếu khác `ap-southeast-1`

```bash
aws ecs register-task-definition \
  --cli-input-json file://infra/ecs-task-def.json \
  --region ap-southeast-1
```

### 3f. Tạo Service

> ⚠️ Bước này cần có **VPC, Subnets, Security Group, và ALB** trước.
> Xem bước 4 (Networking) trước.

```bash
aws ecs create-service \
  --cluster teesik \
  --service-name teesik-backend-task-service-y7o253dx  \
  --task-definition teesik-backend-task \
  --desired-count 1 \
  --launch-type FARGATE \
  --deployment-configuration "maximumPercent=200,minimumHealthyPercent=100" \
  --network-configuration "awsvpcConfiguration={
    subnets=[subnet-00e1368a48ef8eb7e,subnet-0e0aba7ce2ae88d26,subnet-08e9e29967bea667a],
    securityGroups=[sg-0063be8153053acb8],
    assignPublicIp=ENABLED
  }" \
  --load-balancers "targetGroupArn=arn:aws:elasticloadbalancing:ap-southeast-1:767397940250:targetgroup/teesik-backend-tg/c2763f6834b6e51f,containerName=teesik-backend,containerPort=80" \
  --region ap-southeast-1
```

→ Lưu vào GitHub Secrets:
- `ECS_CLUSTER=teesik`
- `ECS_SERVICE=teesik-backend-service`
- `ECS_TASK_DEFINITION=teesik-backend-task`

---

## 4. Networking — VPC, ALB, Security Groups

### Sử dụng Default VPC (đơn giản nhất)

```bash
# Lấy Default VPC ID
aws ec2 describe-vpcs --filters "Name=isDefault,Values=true" \
  --query "Vpcs[0].VpcId" --output text

# Lấy Subnets
aws ec2 describe-subnets \
  --filters "Name=vpc-id,Values=vpc-0dfae049e8efda1a7" \
  --query "Subnets[*].SubnetId" --output text
```

### Tạo Security Group cho ALB

```bash
aws ec2 create-security-group \
  --group-name teesik-alb-sg \
  --description "ALB for Teesik" \
  --vpc-id vpc-0dfae049e8efda1a7

# Mở port 80 và 443
aws ec2 authorize-security-group-ingress \
  --group-id sg-0adee4ef82479e9a1 \
  --protocol tcp --port 80 --cidr 0.0.0.0/0

aws ec2 authorize-security-group-ingress \
  --group-id sg-0adee4ef82479e9a1 \
  --protocol tcp --port 443 --cidr 0.0.0.0/0
```

### Tạo Security Group cho ECS Tasks

```bash
aws ec2 create-security-group \
  --group-name teesik-ecs-sg \
  --description "ECS Tasks for Teesik" \
  --vpc-id vpc-0dfae049e8efda1a7

# Mở port 80 chỉ cho phép từ ALB
aws ec2 authorize-security-group-ingress \
  --group-id sg-0063be8153053acb8 \
  --protocol tcp --port 80 --source-group sg-0adee4ef82479e9a1
  # ALB security group
```

### Tạo Application Load Balancer

```bash
# Tạo ALB
aws elbv2 create-load-balancer \
  --name teesik-alb \
  --subnets subnet-xxxxxx subnet-yyyyyy \
  --security-groups sg-xxxxxx \
  --type application

# Tạo Target Group (cho ECS)
aws elbv2 create-target-group \
  --name teesik-backend-tg \
  --protocol HTTP \
  --port 80 \
  --vpc-id vpc-xxxxxx \
  --target-type ip \
  --health-check-path /api/health \
  --health-check-interval-seconds 30

# Tạo Listener
aws elbv2 create-listener \
  --load-balancer-arn arn:aws:elasticloadbalancing:... \
  --protocol HTTP \
  --port 80 \
  --default-actions Type=forward,TargetGroupArn=arn:aws:elasticloadbalancing:...
```

---

## 5. S3 — Frontend Hosting

```bash
# Tạo S3 bucket
aws s3 mb s3://teesik-frontend --region ap-southeast-1

# Bật static website hosting
aws s3 website s3://teesik-frontend \
  --index-document index.html \
  --error-document 404.html

# Bucket policy cho public read (CloudFront sẽ handle)
aws s3api put-bucket-policy \
  --bucket teesik-frontend \
  --policy '{
    "Version": "2012-10-17",
    "Statement": [{
      "Sid": "CloudFrontAccess",
      "Effect": "Allow",
      "Principal": { "Service": "cloudfront.amazonaws.com" },
      "Action": "s3:GetObject",
      "Resource": "arn:aws:s3:::teesik-frontend/*"
    }]
  }'
```

→ Lưu `S3_BUCKET=teesik-frontend` vào GitHub Secrets.

---

## 6. CloudFront — CDN

> Tạo qua AWS Console dễ hơn CLI cho CloudFront.
> **Console → CloudFront → Create Distribution**:

| Setting | Value |
|:---|:---|
| Origin domain | `teesik-frontend.s3.ap-southeast-1.amazonaws.com` |
| Origin access | Origin Access Control (OAC) |
| Viewer protocol policy | Redirect HTTP to HTTPS |
| Allowed HTTP methods | GET, HEAD |
| Cache policy | CachingOptimized |
| Default root object | `index.html` |
| Custom error pages | 403 → `/index.html` (200), 404 → `/404.html` (404) |

→ Lưu `CLOUDFRONT_DISTRIBUTION_ID=E1ABCDEF...` vào GitHub Secrets.

---

## 7. RDS — MySQL Database (Optional)

> Chi phí: ~$15/tháng (db.t3.micro). Free tier có 12 tháng miễn phí.

```bash
aws rds create-db-instance \
  --db-instance-identifier teesik-db \
  --engine mysql \
  --engine-version 8.0 \
  --db-instance-class db.t3.micro \
  --allocated-storage 20 \
  --master-username teesik_admin \
  --master-user-password YOUR_STRONG_PASSWORD \
  --db-name teesik \
  --vpc-security-group-ids sg-zzzzzz \
  --availability-zone ap-southeast-1a \
  --backup-retention-period 7 \
  --no-publicly-accessible
```

> ⚠️ Security Group `sg-zzzzzz` phải cho phép port 3306 từ ECS security group.

---

## 8. Route 53 — Domain (Optional)

> Cần có tên miền. Có thể mua trên AWS Route 53 hoặc dùng domain bên ngoài.

```bash
# Tạo Hosted Zone
aws route53 create-hosted-zone \
  --name teesik.vn \
  --caller-reference $(date +%s)

# Trỏ frontend → CloudFront
# Trỏ api.teesik.vn → ALB
# (Tạo A record ALIAS qua Console dễ hơn)
```

---

## 9. GitHub Secrets — Tổng kết

### Frontend repo (`teesik`)

| Secret Name | Giá trị |
|:---|:---|
| `AWS_ACCESS_KEY_ID` | Từ bước 1 |
| `AWS_SECRET_ACCESS_KEY` | Từ bước 1 |
| `AWS_REGION` | `ap-southeast-1` |
| `S3_BUCKET` | `teesik-frontend` |
| `CLOUDFRONT_DISTRIBUTION_ID` | Từ bước 6 |
| `NEXT_PUBLIC_API_URL` | `https://api.teesik.vn/api` |

### Backend repo (`teesik-backend`)

| Secret Name | Giá trị |
|:---|:---|
| `AWS_ACCESS_KEY_ID` | Từ bước 1 |
| `AWS_SECRET_ACCESS_KEY` | Từ bước 1 |
| `AWS_REGION` | `ap-southeast-1` |
| `ECR_REPOSITORY` | `teesik-backend` |
| `ECS_CLUSTER` | `teesik` |
| `ECS_SERVICE` | `teesik-backend-task-service-y7o253dx ` |
| `ECS_TASK_DEFINITION` | `teesik-backend-task` |

---

## 💰 Chi phí ước tính (tối thiểu)

| Service | Free Tier | Sau Free Tier |
|:---|:---|:---|
| ECS Fargate (0.25 vCPU, 0.5GB) | ❌ | ~$10/tháng |
| ECR | 500MB free | ~$0.10/GB |
| ALB | ❌ | ~$16/tháng |
| S3 | 5GB free | ~$0.023/GB |
| CloudFront | 1TB free/tháng | ~$0.085/GB |
| RDS (db.t3.micro) | 12 tháng free | ~$15/tháng |
| Route 53 | ❌ | $0.50/zone + $0.40/1M queries |
| **Tổng (sau free tier)** | | **~$42/tháng** |

> 💡 **Tip**: Dùng Fargate Spot giảm ~70% chi phí ECS cho non-critical workloads.
  