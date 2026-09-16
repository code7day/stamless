#!/bin/bash

# ==============================================================================
# SCRIPT DE PROMOCIÓN A PRODUCCIÓN - STAMLESS (STAGE -> PROD EN SERVIDOR)
# ==============================================================================

# 1. Parsear argumentos de consola (Detectar modo verbose -v y migrate:fresh -m)
VERBOSE=false
while getopts "v" opt; do
    case $opt in
        v) VERBOSE=true ;;
        *) echo "Uso: $0 [-v]" && exit 1 ;;
    esac
done

ENV_FILE=".env"
if [ -f "$ENV_FILE" ]; then
    SERVER_ALIAS=$(grep -E "^(STAMLESS|GENESIS|BEEZYNC)_DEPLOY_SERVER=" "$ENV_FILE" | head -n1 | cut -d'=' -f2 | tr -d "'\"")
    PROJECT_BASE=$(grep -E "^(STAMLESS|GENESIS|BEEZYNC)_DEPLOY_DOMAIN=" "$ENV_FILE" | head -n1 | cut -d'=' -f2 | tr -d "'\"")
    IS_SUBDOMAIN=$(grep -E "^(STAMLESS|GENESIS|BEEZYNC)_DEPLOY_SUBDOMAIN=" "$ENV_FILE" | head -n1 | cut -d'=' -f2 | tr -d "'\"")
fi

[ -z "$SERVER_ALIAS" ] && SERVER_ALIAS="server-webapps"
[ -z "$PROJECT_BASE" ] && PROJECT_BASE="stamless.com"
[ -z "$IS_SUBDOMAIN" ] && IS_SUBDOMAIN=false

DOMAIN_NO_TLD=$(echo "$PROJECT_BASE" | rev | cut -d'.' -f2- | rev | tr '.' '_')

if [ "$IS_SUBDOMAIN" = "true" ] || [ "$IS_SUBDOMAIN" = true ]; then
    STAGE_FOLDER="stage-${DOMAIN_NO_TLD}"
    PROD_FOLDER="${DOMAIN_NO_TLD}"
else
    STAGE_FOLDER="stage_${DOMAIN_NO_TLD}"
    PROD_FOLDER="${DOMAIN_NO_TLD}"
fi

STAGE_PATH="/var/www/vhosts/${STAGE_FOLDER}/"
PROD_PATH="/var/www/vhosts/${PROD_FOLDER}/"
WWW_USER="www-data"

echo "========================================================================"
echo "🚀 PROMOCIÓN A PRODUCCIÓN - STAMLESS"
echo "========================================================================"
echo "Servidor:    $SERVER_ALIAS"
echo "Origen:      $STAGE_PATH"
echo "Destino:     $PROD_PATH"
echo "========================================================================"
echo ""
read -p "❓ ¿Confirmas la publicación de STAGE a PRODUCCIÓN en $SERVER_ALIAS? (s/N): " CONFIRM
if [[ ! "$CONFIRM" =~ ^[sS]$ ]]; then
    echo -e "\033[1;31m❌ Promoción cancelada por el usuario.\033[0m"
    exit 1
fi

echo "📦 Sincronizando Stage hacia Producción en $SERVER_ALIAS..."
ssh -t $SERVER_ALIAS << EOF
set -e

STAGE_PATH="${STAGE_PATH}"
PROD_PATH="${PROD_PATH}"
PROD_FOLDER="${PROD_FOLDER}"
WWW_USER="${WWW_USER}"

if [ ! -d "\$STAGE_PATH" ]; then
    echo "❌ Error: El directorio de stage \$STAGE_PATH no existe."
    exit 1
fi

sudo mkdir -p "\$PROD_PATH"
sudo chown -R \$WWW_USER:\$WWW_USER "\$PROD_PATH"

echo "📂 Copiando archivos de Stage a Producción..."
sudo rsync -a --no-perms --no-owner --no-group --delete \
    --exclude='/.env' \
    --exclude='/storage/' \
    --exclude='/public/storage' \
    --exclude='/public/hot' \
    --exclude='/bootstrap/cache/*.php' \
    "\$STAGE_PATH" "\$PROD_PATH"

cd "\$PROD_PATH"

sudo mkdir -p bootstrap/cache storage/framework/{cache,sessions,views} storage/app/public/{assets,media,forms}
sudo chown -R \$WWW_USER:\$WWW_USER bootstrap/cache storage
sudo chmod -R 775 storage bootstrap/cache

if [ ! -f ".env" ]; then
    echo "⚠️  Copiando .env base para Producción desde Stage..."
    sudo -u \$WWW_USER cp "\${STAGE_PATH}.env" .env
    sudo chmod 640 .env
fi

echo "⚡ Optimizando Producción..."
sudo -u \$WWW_USER php artisan optimize:clear > /dev/null 2>&1 || true
sudo -u \$WWW_USER php artisan config:cache
sudo -u \$WWW_USER php artisan route:cache
sudo -u \$WWW_USER php artisan view:cache
sudo -u \$WWW_USER php artisan filament:optimize > /dev/null 2>&1 || true
sudo -u \$WWW_USER php artisan storage:link > /dev/null 2>&1 || true
sudo -u \$WWW_USER php artisan livewire:publish --assets > /dev/null 2>&1 || true

echo "🗄️  Ejecutando migraciones en Producción..."
sudo -u \$WWW_USER php artisan migrate --force

if command -v fix-perms >/dev/null 2>&1; then
    sudo fix-perms "\$PROD_FOLDER" > /dev/null 2>&1 || true
fi

EOF

if [ $? -ne 0 ]; then
    echo -e "\033[1;31m❌ [ERROR]: Falló la promoción a producción en el servidor.\033[0m"
    exit 1
fi

echo ""
echo "🎉 ======================================================================"
echo "🎉 ¡Stamless ha sido publicado en PRODUCCIÓN con éxito!"
echo "🎉 Destino: $PROD_PATH"
echo "🎉 ======================================================================"
