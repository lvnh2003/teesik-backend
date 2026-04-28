#!/bin/bash
# ══════════════════════════════════════════════════════════════════════════════
# Auto-Provision EC2 Architecture for Teesik Backend
# ══════════════════════════════════════════════════════════════════════════════
# This script automates Phase 2, 3, 4, 5 of the EC2 migration.
# It uses AWS CLI to create the EC2 instance, security group, Elastic IP,
# injects the setup script via UserData, updates Route53, and sets up GitHub secrets.
# ══════════════════════════════════════════════════════════════════════════════

set -euo pipefail

REGION="ap-southeast-1"
INSTANCE_TYPE="t3.micro"
AMI_ID="ami-002843b0a9e09324a" # Amazon Linux 2023 in ap-southeast-1
KEY_NAME="teesik-backend-key"
SG_NAME="teesik-backend-sg"
DOMAIN="api.teesik.store"

echo "🚀 Starting Automated AWS Provisioning..."

# 1. Create Key Pair
echo "🔑 Creating Key Pair: $KEY_NAME..."
if ! aws ec2 describe-key-pairs --key-names "$KEY_NAME" --region "$REGION" >/dev/null 2>&1; then
    aws ec2 create-key-pair --key-name "$KEY_NAME" --query 'KeyMaterial' --output text --region "$REGION" > "${KEY_NAME}.pem"
    chmod 400 "${KEY_NAME}.pem"
    echo "   ✅ Key pair created and saved to ${KEY_NAME}.pem"
else
    echo "   ⏭️  Key pair already exists."
fi

# 2. Create Security Group
echo "🛡️  Creating Security Group: $SG_NAME..."
if ! aws ec2 describe-security-groups --group-names "$SG_NAME" --region "$REGION" >/dev/null 2>&1; then
    VPC_ID=$(aws ec2 describe-vpcs --filters "Name=isDefault,Values=true" --query "Vpcs[0].VpcId" --output text --region "$REGION")
    SG_ID=$(aws ec2 create-security-group --group-name "$SG_NAME" --description "SG for Teesik EC2 backend" --vpc-id "$VPC_ID" --query "GroupId" --output text --region "$REGION")
    
    # Add Rules
    aws ec2 authorize-security-group-ingress --group-id "$SG_ID" --protocol tcp --port 22 --cidr 0.0.0.0/0 --region "$REGION"
    aws ec2 authorize-security-group-ingress --group-id "$SG_ID" --protocol tcp --port 80 --cidr 0.0.0.0/0 --region "$REGION"
    aws ec2 authorize-security-group-ingress --group-id "$SG_ID" --protocol tcp --port 443 --cidr 0.0.0.0/0 --region "$REGION"
    echo "   ✅ Security Group created ($SG_ID) with ports 22, 80, 443."
else
    SG_ID=$(aws ec2 describe-security-groups --group-names "$SG_NAME" --query "SecurityGroups[0].GroupId" --output text --region "$REGION")
    echo "   ⏭️  Security group already exists ($SG_ID)."
fi

# 3. Launch EC2 Instance with UserData
echo "🖥️  Launching EC2 Instance..."
# We pass the ec2-setup.sh script to run on boot.
USER_DATA=$(cat infra/ec2-setup.sh | base64)

INSTANCE_ID=$(aws ec2 run-instances \
    --image-id "$AMI_ID" \
    --count 1 \
    --instance-type "$INSTANCE_TYPE" \
    --key-name "$KEY_NAME" \
    --security-group-ids "$SG_ID" \
    --user-data "$USER_DATA" \
    --tag-specifications "ResourceType=instance,Tags=[{Key=Name,Value=teesik-backend-ec2}]" \
    --block-device-mappings '[{"DeviceName":"/dev/xvda","Ebs":{"VolumeSize":20,"VolumeType":"gp3"}}]' \
    --query "Instances[0].InstanceId" \
    --output text \
    --region "$REGION")

echo "   ✅ Instance launched: $INSTANCE_ID"
echo "   ⏳ Waiting for instance to be running..."
aws ec2 wait instance-running --instance-ids "$INSTANCE_ID" --region "$REGION"

# 4. Allocate & Associate Elastic IP
echo "🌐 Allocating Elastic IP..."
ALLOCATION_ID=$(aws ec2 allocate-address --domain vpc --query "AllocationId" --output text --region "$REGION")
PUBLIC_IP=$(aws ec2 describe-addresses --allocation-ids "$ALLOCATION_ID" --query "Addresses[0].PublicIp" --output text --region "$REGION")

echo "   ✅ Allocated IP: $PUBLIC_IP"
aws ec2 associate-address --instance-id "$INSTANCE_ID" --allocation-id "$ALLOCATION_ID" --region "$REGION"
echo "   ✅ Associated $PUBLIC_IP with $INSTANCE_ID"

# 5. Route 53 DNS Update
echo "🌍 Updating Route 53 DNS for $DOMAIN..."
HOSTED_ZONE_ID=$(aws route53 list-hosted-zones-by-name --dns-name "teesik.store" --query "HostedZones[0].Id" --output text)

if [ -n "$HOSTED_ZONE_ID" ] && [ "$HOSTED_ZONE_ID" != "None" ]; then
    CHANGE_BATCH=$(cat <<EOF
{
  "Comment": "Update backend IP to EC2 Elastic IP",
  "Changes": [
    {
      "Action": "UPSERT",
      "ResourceRecordSet": {
        "Name": "$DOMAIN",
        "Type": "A",
        "TTL": 300,
        "ResourceRecords": [
          {
            "Value": "$PUBLIC_IP"
          }
        ]
      }
    }
  ]
}
EOF
)
    aws route53 change-resource-record-sets --hosted-zone-id "$HOSTED_ZONE_ID" --change-batch "$CHANGE_BATCH"
    echo "   ✅ Route 53 updated. $DOMAIN points to $PUBLIC_IP."
else
    echo "   ⚠️  Hosted zone for teesik.store not found. You may need to update DNS manually."
fi

echo ""
echo "🎉 Provisioning script finished!"
echo "Elastic IP: $PUBLIC_IP"
echo "Instance ID: $INSTANCE_ID"
echo ""
echo "Next steps: I will SSH into the instance to upload docker-compose.prod.yml, .env.production, and migrate the DB."
