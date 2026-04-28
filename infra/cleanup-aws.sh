#!/bin/bash
# ══════════════════════════════════════════════════════════════════════════════
# Cleanup Old AWS Resources — Teesik
# ══════════════════════════════════════════════════════════════════════════════
# ⚠️  RUN ONLY AFTER EC2 is fully working and verified (24-48 hours)
# ⚠️  Each command is idempotent — safe to run multiple times
# ══════════════════════════════════════════════════════════════════════════════

set -euo pipefail

REGION="ap-southeast-1"
CLUSTER="teesik"

echo "════════════════════════════════════════════════════════"
echo "  🧹 Cleanup Old AWS Resources"
echo "════════════════════════════════════════════════════════"
echo ""
echo "  ⚠️  This will DELETE resources and STOP billing."
echo "  ⚠️  Make sure EC2 backend is working first!"
echo ""
read -p "Continue? (yes/no): " confirm
if [ "$confirm" != "yes" ]; then
    echo "Cancelled."
    exit 0
fi

# ─── 1. Check & Delete NAT Gateway ──────────────────────────────────────────
echo ""
echo "═══ 1. NAT Gateways ═══"
NAT_GWS=$(aws ec2 describe-nat-gateways \
    --region "$REGION" \
    --filter "Name=state,Values=available" \
    --query "NatGateways[*].NatGatewayId" \
    --output text 2>/dev/null || echo "")

if [ -n "$NAT_GWS" ] && [ "$NAT_GWS" != "None" ]; then
    echo "   Found NAT Gateways: $NAT_GWS"
    for ngw in $NAT_GWS; do
        echo "   💸 NAT Gateway $ngw costs ~$32/month!"
        read -p "   Delete $ngw? (yes/no): " del
        if [ "$del" == "yes" ]; then
            aws ec2 delete-nat-gateway --nat-gateway-id "$ngw" --region "$REGION"
            echo "   ✅ Deleted $ngw"
        fi
    done
else
    echo "   ✅ No NAT Gateways found. Good!"
fi

# ─── 2. Delete ECS Service ──────────────────────────────────────────────────
echo ""
echo "═══ 2. ECS Services ═══"
ECS_SERVICES=$(aws ecs list-services \
    --cluster "$CLUSTER" \
    --region "$REGION" \
    --query "serviceArns[*]" \
    --output text 2>/dev/null || echo "")

if [ -n "$ECS_SERVICES" ] && [ "$ECS_SERVICES" != "None" ]; then
    for svc in $ECS_SERVICES; do
        SVC_NAME=$(echo "$svc" | rev | cut -d'/' -f1 | rev)
        echo "   Found service: $SVC_NAME"
        read -p "   Scale to 0 and delete $SVC_NAME? (yes/no): " del
        if [ "$del" == "yes" ]; then
            # Scale to 0 first
            aws ecs update-service --cluster "$CLUSTER" --service "$SVC_NAME" \
                --desired-count 0 --region "$REGION" > /dev/null
            echo "   ⏳ Scaled to 0, waiting for tasks to stop..."
            sleep 10
            # Delete service
            aws ecs delete-service --cluster "$CLUSTER" --service "$SVC_NAME" \
                --force --region "$REGION" > /dev/null
            echo "   ✅ Deleted service: $SVC_NAME"
        fi
    done
else
    echo "   ✅ No active ECS services."
fi

# ─── 3. Delete ECS Cluster ──────────────────────────────────────────────────
echo ""
echo "═══ 3. ECS Cluster ═══"
read -p "   Delete ECS cluster '$CLUSTER'? (yes/no): " del
if [ "$del" == "yes" ]; then
    aws ecs delete-cluster --cluster "$CLUSTER" --region "$REGION" > /dev/null 2>&1 || true
    echo "   ✅ Deleted cluster: $CLUSTER"
fi

# ─── 4. Delete ALB ──────────────────────────────────────────────────────────
echo ""
echo "═══ 4. Load Balancers ═══"
ALBS=$(aws elbv2 describe-load-balancers \
    --region "$REGION" \
    --query "LoadBalancers[?contains(LoadBalancerName, 'teesik')].LoadBalancerArn" \
    --output text 2>/dev/null || echo "")

if [ -n "$ALBS" ] && [ "$ALBS" != "None" ]; then
    for alb in $ALBS; do
        ALB_NAME=$(aws elbv2 describe-load-balancers \
            --load-balancer-arns "$alb" --region "$REGION" \
            --query "LoadBalancers[0].LoadBalancerName" --output text)
        echo "   Found ALB: $ALB_NAME"
        echo "   💸 ALB costs ~$16-22/month!"
        read -p "   Delete $ALB_NAME? (yes/no): " del
        if [ "$del" == "yes" ]; then
            # Delete listeners first
            LISTENERS=$(aws elbv2 describe-listeners \
                --load-balancer-arn "$alb" --region "$REGION" \
                --query "Listeners[*].ListenerArn" --output text 2>/dev/null || echo "")
            for lis in $LISTENERS; do
                aws elbv2 delete-listener --listener-arn "$lis" --region "$REGION" 2>/dev/null || true
            done
            # Delete ALB
            aws elbv2 delete-load-balancer --load-balancer-arn "$alb" --region "$REGION"
            echo "   ✅ Deleted ALB: $ALB_NAME"
        fi
    done

    # Delete target groups
    TGS=$(aws elbv2 describe-target-groups \
        --region "$REGION" \
        --query "TargetGroups[?contains(TargetGroupName, 'teesik')].TargetGroupArn" \
        --output text 2>/dev/null || echo "")
    for tg in $TGS; do
        aws elbv2 delete-target-group --target-group-arn "$tg" --region "$REGION" 2>/dev/null || true
    done
    echo "   ✅ Cleaned up target groups"
else
    echo "   ✅ No teesik ALBs found."
fi

# ─── 5. Delete RDS (DANGEROUS — only after data migrated) ───────────────────
echo ""
echo "═══ 5. RDS Instance ═══"
RDS_INSTANCES=$(aws rds describe-db-instances \
    --region "$REGION" \
    --query "DBInstances[?contains(DBInstanceIdentifier, 'teesik')].DBInstanceIdentifier" \
    --output text 2>/dev/null || echo "")

if [ -n "$RDS_INSTANCES" ] && [ "$RDS_INSTANCES" != "None" ]; then
    for rds in $RDS_INSTANCES; do
        echo "   Found RDS: $rds"
        echo "   💸 RDS costs ~$15-18/month!"
        echo "   ⚠️  Make sure data is FULLY MIGRATED before deleting!"
        read -p "   Delete $rds (with final snapshot)? (yes/no): " del
        if [ "$del" == "yes" ]; then
            aws rds delete-db-instance \
                --db-instance-identifier "$rds" \
                --final-db-snapshot-identifier "${rds}-final-$(date +%Y%m%d)" \
                --region "$REGION"
            echo "   ✅ Deleting RDS: $rds (final snapshot saved)"
            echo "   ⏳ This takes 5-10 minutes to complete."
        fi
    done
else
    echo "   ✅ No teesik RDS instances found."
fi

# ─── 6. Release Elastic IPs (unused ones) ───────────────────────────────────
echo ""
echo "═══ 6. Elastic IPs ═══"
EIPS=$(aws ec2 describe-addresses \
    --region "$REGION" \
    --query "Addresses[?AssociationId==null].AllocationId" \
    --output text 2>/dev/null || echo "")

if [ -n "$EIPS" ] && [ "$EIPS" != "None" ]; then
    echo "   Found unattached Elastic IPs (each costs $3.65/month):"
    for eip in $EIPS; do
        EIP_ADDR=$(aws ec2 describe-addresses --allocation-ids "$eip" --region "$REGION" \
            --query "Addresses[0].PublicIp" --output text)
        echo "   - $EIP_ADDR ($eip)"
        read -p "   Release $EIP_ADDR? (yes/no): " del
        if [ "$del" == "yes" ]; then
            aws ec2 release-address --allocation-id "$eip" --region "$REGION"
            echo "   ✅ Released $EIP_ADDR"
        fi
    done
else
    echo "   ✅ No unattached Elastic IPs."
fi

echo ""
echo "════════════════════════════════════════════════════════"
echo "  ✅ Cleanup Complete!"
echo "════════════════════════════════════════════════════════"
echo ""
echo "  💰 Check AWS Cost Explorer in 24 hours to verify savings."
echo "  🔍 Also check for any remaining Security Groups"
echo "     that might have been created for ALB/ECS."
echo ""
