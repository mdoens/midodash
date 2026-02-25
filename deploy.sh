#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
COOLIFY_URL="https://coolify.barcelona2.doens.nl"
APP_UUID="mw0ks0s8sc8cw0csocwksskk"

# Lees COOLIFY_TOKEN uit .env.local als niet al gezet
if [ -z "${COOLIFY_TOKEN:-}" ] && [ -f "${SCRIPT_DIR}/.env.local" ]; then
    COOLIFY_TOKEN=$(grep -m1 '^COOLIFY_TOKEN=' "${SCRIPT_DIR}/.env.local" | cut -d= -f2- | tr -d "'" | tr -d '"')
fi
: "${COOLIFY_TOKEN:?Set COOLIFY_TOKEN in .env.local of als env var}"

# Commit message is verplicht
if [ -z "${1:-}" ]; then
    echo "FOUT: Commit message is verplicht."
    echo "Gebruik: ./deploy.sh \"feat: mijn wijziging\""
    exit 1
fi

# Commit en push
echo "==> Git push..."
git add -A
if git diff --cached --quiet; then
    echo "    Geen wijzigingen."
else
    git commit -m "$1" --no-verify
    git push origin main
fi

# Deploy triggeren
echo "==> Deploy triggeren..."
RESPONSE=$(curl -s --max-time 10 -X POST \
    -H "Authorization: Bearer ${COOLIFY_TOKEN}" \
    -H "Content-Type: application/json" \
    -d "{\"uuid\":\"${APP_UUID}\"}" \
    "${COOLIFY_URL}/api/v1/deploy")

DEPLOY_UUID=$(echo "$RESPONSE" | python3 -c "import sys,json; print(json.load(sys.stdin)['deployments'][0]['deployment_uuid'])" 2>/dev/null)

if [ -z "$DEPLOY_UUID" ]; then
    echo "    FOUT: deploy niet gestart"
    echo "    $RESPONSE"
    exit 1
fi

echo "    Deployment: ${DEPLOY_UUID}"

# Wacht op resultaat
echo "==> Wachten op deployment..."
for i in $(seq 1 30); do
    sleep 10
    STATUS=$(curl -s --max-time 10 \
        -H "Authorization: Bearer ${COOLIFY_TOKEN}" \
        "${COOLIFY_URL}/api/v1/deployments/${DEPLOY_UUID}" \
        | python3 -c "import sys,json; print(json.load(sys.stdin).get('status','?'))" 2>/dev/null)

    case "$STATUS" in
        finished)
            echo "    Deployment gelukt!"
            echo "==> https://mido.barcelona2.doens.nl"
            exit 0
            ;;
        failed)
            echo "    Deployment MISLUKT!"
            echo "    Bekijk logs: ${COOLIFY_URL}"
            exit 1
            ;;
        *)
            printf "    [%02d/30] %s...\n" "$i" "$STATUS"
            ;;
    esac
done

echo "    Timeout — check Coolify dashboard."
exit 1
