#!/bin/bash

# ==============================================================================
# SCRIPT DE DESPLIEGUE AUTOMATIZADO - STAMLESS (STAGE / SERVIDOR)
# ==============================================================================

# 1. Parsear argumentos de consola (Detectar modo verbose -v, migrate:fresh -m, sync storage -s)
VERBOSE=false
MIGRATE_FRESH=false
SYNC_STORAGE=false
while getopts "vms" opt; do
    case $opt in
        v) VERBOSE=true ;;
        m) MIGRATE_FRESH=true ;;
        s) SYNC_STORAGE=true ;;
        *) echo "Uso: $0 [-v] [-m] [-s]" && exit 1 ;;
    esac
done

# 2. Control Estricto y Validación de Variables en el .env Local
ENV_FILE=".env"
VARIABLES_MISSING=false
INVALID_FORMAT=false
REASON=""

if [ -f "$ENV_FILE" ]; then
    SERVER_ALIAS=$(grep -E "^(STAMLESS|GENESIS|BEEZYNC)_DEPLOY_SERVER=" "$ENV_FILE" | head -n1 | cut -d'=' -f2 | tr -d "'\"")
    PROJECT_BASE=$(grep -E "^(STAMLESS|GENESIS|BEEZYNC)_DEPLOY_DOMAIN=" "$ENV_FILE" | head -n1 | cut -d'=' -f2 | tr -d "'\"")
    IS_SUBDOMAIN=$(grep -E "^(STAMLESS|GENESIS|BEEZYNC)_DEPLOY_SUBDOMAIN=" "$ENV_FILE" | head -n1 | cut -d'=' -f2 | tr -d "'\"")
    RUN_HORIZON=$(grep -E "^(STAMLESS|GENESIS|BEEZYNC)_DEPLOY_RUN_HORIZON=" "$ENV_FILE" | head -n1 | cut -d'=' -f2 | tr -d "'\"")
    RUN_REVERB=$(grep -E "^(STAMLESS|GENESIS|BEEZYNC)_DEPLOY_RUN_REVERB=" "$ENV_FILE" | head -n1 | cut -d'=' -f2 | tr -d "'\"")
else
    VARIABLES_MISSING=true
    REASON="No se encontró el archivo .env local en el directorio raíz."
fi

# Configurar valores por defecto
[ -z "$SERVER_ALIAS" ] && SERVER_ALIAS="server-webapps"
[ -z "$PROJECT_BASE" ] && PROJECT_BASE="stamless.com"
[ -z "$IS_SUBDOMAIN" ] && IS_SUBDOMAIN=false
[ -z "$RUN_HORIZON" ] && RUN_HORIZON=false
[ -z "$RUN_REVERB" ] && RUN_REVERB=false

# Validar si alguna de las variables críticas está vacía
if [ -z "$SERVER_ALIAS" ] || [ -z "$PROJECT_BASE" ]; then
    VARIABLES_MISSING=true
    REASON="Faltan variables obligatorias de implementación en tu .env local (STAMLESS_DEPLOY_SERVER, STAMLESS_DEPLOY_DOMAIN)."
fi

# Validar consistencia y nomenclatura institucional si no faltan variables
if [ "$VARIABLES_MISSING" = false ]; then
    # Exigir que el servidor siga la estructura server-[NOMBRE_SERVIDOR]
    if [[ ! "$SERVER_ALIAS" =~ ^server-[a-zA-Z0-9_-]+$ ]]; then
        INVALID_FORMAT=true
        REASON="La variable STAMLESS_DEPLOY_SERVER debe seguir la nomenclatura institucional 'server-[NOMBRE_SERVIDOR]' (Ejemplo: STAMLESS_DEPLOY_SERVER=\"server-webapps\")."
    fi
    
    # Exigir estructura de dominio o subdominio real
    if [[ ! "$PROJECT_BASE" =~ ^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]]; then
        INVALID_FORMAT=true
        REASON="La variable STAMLESS_DEPLOY_DOMAIN debe tener un formato de dominio o subdominio válido (Ejemplo: STAMLESS_DEPLOY_DOMAIN=\"stamless.com\")."
    fi

    # Verificar si el alias SSH existe configurado en tu MacBook ~/.ssh/config
    SSH_CONFIG="$HOME/.ssh/config"
    HOST_CONFIGURED=false
    if [ -f "$SSH_CONFIG" ]; then
        if grep -qE "^[[:space:]]*Host[[:space:]]+([^#]*[[:space:]]+)?${SERVER_ALIAS}([[:space:]]+|$)" "$SSH_CONFIG"; then
            HOST_CONFIGURED=true
        fi
    fi

    if [ "$HOST_CONFIGURED" = false ]; then
        INVALID_FORMAT=true
        SUGGESTED_HOSTS=$(grep -iE "^[[:space:]]*Host[[:space:]]+" "$SSH_CONFIG" 2>/dev/null | sed -e 's/^[[:space:]]*Host[[:space:]]*//I' | tr ' ' '\n' | grep -E "^server-" | tr '\n' ' ' | xargs 2>/dev/null)
        if [ -z "$SUGGESTED_HOSTS" ]; then
            SUGGESTED_HOSTS="server-webapps"
        fi
        REASON="El alias de servidor '${SERVER_ALIAS}' no está configurado en tu archivo ~/.ssh/config local.\n      Tus alias de servidor válidos detectados localmente son: \033[1;32m${SUGGESTED_HOSTS}\033[0m"
    fi
fi

# Imprimir pantalla de ayuda (HELP) si falla la validación e interrumpir ejecución
if [ "$VARIABLES_MISSING" = true ] || [ "$INVALID_FORMAT" = true ]; then
    echo -e "\033[1;31m❌ [ERROR DE CONFIGURACIÓN DE DESPLIEGUE - STAMLESS]\033[0m"
    echo "========================================================================"
    echo -e "\033[1;33m⚠️  Motivo: $REASON\033[0m"
    echo "========================================================================"
    echo "Para poder realizar el despliegue hacia Staging, abre tu .env local,"
    echo "agrega el siguiente bloque al final y ajusta tus accesos:"
    echo "========================================================================"
    echo ""
    echo "# =============================================================================="
    echo "# CONFIGURACIÓN DE IMPLEMENTACIÓN DE INFRAESTRUCTURA - STAMLESS"
    echo "# =============================================================================="
    echo "STAMLESS_DEPLOY_SERVER=\"server-webapps\""
    echo "STAMLESS_DEPLOY_DOMAIN=\"stamless.com\""
    echo "STAMLESS_DEPLOY_SUBDOMAIN=false"
    echo ""
    echo "========================================================================"
    echo -e "\033[1;33m⚠️  El despliegue local ha sido abortado hasta configurar estas variables.\033[0m"
    exit 1
fi

# ==============================================================================
# REGLA INSTITUCIONAL DE RUTAS DE STAGING
# ==============================================================================
# Dominio principal -> usa prefijo con guion bajo: stage_ (Ej. stage_stamless)
# Subdominio         -> usa prefijo con guion medio: stage- (Ej. stage-studio_stamless)

DOMAIN_NO_TLD=$(echo "$PROJECT_BASE" | rev | cut -d'.' -f2- | rev | tr '.' '_')

if [ "$IS_SUBDOMAIN" = "true" ] || [ "$IS_SUBDOMAIN" = true ]; then
    PROJECT_FOLDER="stage-${DOMAIN_NO_TLD}"
else
    PROJECT_FOLDER="stage_${DOMAIN_NO_TLD}"
fi

REMOTE_PATH="/var/www/vhosts/${PROJECT_FOLDER}/"
WWW_USER="www-data"

# ==============================================================================
# VALIDACIÓN DE ESTADO DE GIT
# ==============================================================================
echo "🔍 0. Verificando estado de Git..."
git fetch origin >/dev/null 2>&1

GIT_STATUS=$(git status --porcelain)
GIT_BEHIND=$(git rev-list HEAD..origin/$(git rev-parse --abbrev-ref HEAD) --count 2>/dev/null || echo "0")

if [ -n "$GIT_STATUS" ]; then
    echo -e "\033[1;33m⚠️  [ADVERTENCIA]: Tienes cambios locales sin commitear (se subirán tal como están en tu disco local).\033[0m"
    git status -s
fi

if [ "$GIT_BEHIND" -gt 0 ]; then
    echo -e "\033[1;33m⚠️  [ADVERTENCIA]: Tu rama local está $GIT_BEHIND commits detrás del remoto. Considera hacer 'git pull' primero.\033[0m"
fi

if [ -n "$GIT_STATUS" ] || [ "$GIT_BEHIND" -gt 0 ]; then
    echo ""
    read -p "❓ ¿Deseas continuar con el despliegue de todas formas? (s/N): " CONFIRM
    if [[ ! "$CONFIRM" =~ ^[sS]$ ]]; then
        echo -e "\033[1;31m❌ Despliegue cancelado por el usuario.\033[0m"
        exit 1
    fi
else
    echo "✅ El repositorio está limpio y sincronizado."
fi
echo ""
echo "🚀 Iniciando el despliegue hacia $SERVER_ALIAS en $PROJECT_FOLDER ($REMOTE_PATH)..."

# 3. Verificación de directorio y preparación en el servidor remoto
echo "🔍 1. Verificando entorno remoto en $SERVER_ALIAS..."
ssh -T -q $SERVER_ALIAS "
    if [ ! -d '$REMOTE_PATH' ]; then
        echo '📁 El directorio remoto no existe. Creando de forma automática: $REMOTE_PATH...';
        sudo mkdir -p '$REMOTE_PATH';
        sudo chown -R $WWW_USER:$WWW_USER '$REMOTE_PATH';
        sudo chmod 775 '$REMOTE_PATH';
    else
        echo '✅ Directorio remoto confirmado: $REMOTE_PATH';
    fi

    # Ejecutar reparación preventiva si el comando fix-perms existe
    if command -v fix-perms >/dev/null 2>&1; then
        if [ '$VERBOSE' = true ]; then
            sudo fix-perms '$PROJECT_FOLDER';
        else
            sudo fix-perms '$PROJECT_FOLDER' > /dev/null 2>&1;
        fi
    fi
"
if [ $? -ne 0 ]; then
    echo -e "\033[1;31m❌ [ERROR SSH]: Falló la verificación de red o directorios en el servidor remoto.\033[0m"
    exit 1
fi

# 4. Compilación local segura
echo "📦 2. Compilando el frontend localmente..."
if [ -f "package.json" ]; then
    if [ "$VERBOSE" = true ]; then
        npm install && npm run build
    else
        npm install --silent > /dev/null 2>&1
        npm run build --silent > /dev/null 2>&1
        echo "   ✅ Compilación de Node.js / Vite completada."
    fi
    if [ $? -ne 0 ]; then
        echo -e "\033[1;31m❌ [ERROR FRONTEND]: Falló la compilación de recursos locales de Node/Vite.\033[0m"
        exit 1
    fi
fi

# 5. Sincronización robusta con Rsync (Exclusiones estrictas y verbosidad dinámica)
RSYNC_EXCLUDES=(
    --exclude='/.git/'
    --exclude='/.github/'
    --exclude='/.env'
    --exclude='/.pnpm-store/'
    --exclude='/.DS_Store'
    --exclude='/.idea/'
    --exclude='/.vscode/'
    --exclude='/.editorconfig'
    --exclude='/.gitattributes'
    --exclude='/.npmrc'
    --exclude='/deploy.sh'
    --exclude='/production.sh'
    --exclude='/AGENTS.md'
    --exclude='/CLAUDE.md'
    --exclude='/GEMINI.md'
    --exclude='/README.md'
    --exclude='/compose.yaml'
    --exclude='/Dockerfile'
    --exclude='/docker/'
    --exclude='/storage/'
    --exclude='/node_modules/'
    --exclude='/vendor/'
    --exclude='/public/hot'
    --exclude='/public/storage'
    --exclude='/tests/'
    --exclude='/docs/'
)

if [ "$VERBOSE" = true ]; then
    echo "📂 3. Sincronizando archivos (Modo detallado)..."
    rsync -av --no-perms --no-owner --no-group --delete --rsync-path="sudo rsync" \
        "${RSYNC_EXCLUDES[@]}" \
        ./ $SERVER_ALIAS:$REMOTE_PATH
else
    echo "📂 3. Sincronizando archivos con el servidor remoto..."
    rsync -a --no-perms --no-owner --no-group --delete --rsync-path="sudo rsync" \
        "${RSYNC_EXCLUDES[@]}" \
        ./ $SERVER_ALIAS:$REMOTE_PATH
fi

if [ $? -ne 0 ]; then
    echo -e "\033[1;31m❌ [ERROR RSYNC]: Falló la sincronización física de archivos con el servidor.\033[0m"
    exit 1
fi

# 3.1 Sincronización condicional de storage (solo una vez en setup inicial, o forzado con flag -s o -m)
NEED_STORAGE_SYNC=false
if [ "$SYNC_STORAGE" = true ] || [ "$MIGRATE_FRESH" = true ]; then
    NEED_STORAGE_SYNC=true
else
    REMOTE_MEDIA_EXISTS=$(ssh -T -q $SERVER_ALIAS "[ -d '${REMOTE_PATH}storage/app/public/media' ] && echo 'yes' || echo 'no'")
    if [ "$REMOTE_MEDIA_EXISTS" != "yes" ]; then
        NEED_STORAGE_SYNC=true
    fi
fi

if [ "$NEED_STORAGE_SYNC" = true ]; then
    echo "🖼️  3.1 Sincronizando assets y media base (storage/app/public)..."
    ssh -T -q $SERVER_ALIAS "sudo mkdir -p '${REMOTE_PATH}storage/app/public'"
    if [ "$VERBOSE" = true ]; then
        rsync -av --no-perms --no-owner --no-group --rsync-path="sudo rsync" \
            ./storage/app/public/ $SERVER_ALIAS:${REMOTE_PATH}storage/app/public/
    else
        rsync -a --no-perms --no-owner --no-group --rsync-path="sudo rsync" \
            ./storage/app/public/ $SERVER_ALIAS:${REMOTE_PATH}storage/app/public/
    fi
    echo "   ✅ Sincronización de storage/app/public completada."
else
    echo "⏭️  3.1 Sincronización de storage omitida (ya existe en servidor. Usa -s para forzarla)."
fi

# 6. Configuración y optimización remota
echo "⚙️  4. Ejecutando despliegue y optimización en el servidor..."
ssh -t $SERVER_ALIAS << EOF
# Configurar control de errores en el bloque remoto
set -e

PROJECT_FOLDER="${PROJECT_FOLDER}"
REMOTE_PATH="${REMOTE_PATH}"
VERBOSE=${VERBOSE}
MIGRATE_FRESH=${MIGRATE_FRESH}
RUN_HORIZON=${RUN_HORIZON}
RUN_REVERB=${RUN_REVERB}
WWW_USER=${WWW_USER}

cd \$REMOTE_PATH

if [ "\$VERBOSE" = true ]; then
    sudo fix-perms \$PROJECT_FOLDER 2>/dev/null || true
else
    sudo fix-perms \$PROJECT_FOLDER > /dev/null 2>&1 || true
fi

# Asegurar directorios requeridos de Laravel y Filament
sudo mkdir -p bootstrap/cache
sudo mkdir -p storage/framework/{cache,sessions,views}
sudo mkdir -p storage/app/public/{assets,media,forms}
sudo chown -R \$WWW_USER:\$WWW_USER bootstrap/cache storage
sudo chmod -R 775 storage bootstrap/cache

# Limpiar cachés antiguas
sudo rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php bootstrap/cache/routes-v7.php
sudo rm -f public/hot

# Control inteligente y seguro de archivo .env
GENERATE_KEY=false
if [ ! -f ".env" ]; then
    echo "⚠️  [ADVERTENCIA]: No se detectó un archivo .env remoto en \$PROJECT_FOLDER."
    echo "⚡ Generando .env base a partir de .env.example..."
    
    sudo -u \$WWW_USER cp .env.example .env
    sudo chown edu:\$WWW_USER .env 2>/dev/null || sudo chown \$WWW_USER:\$WWW_USER .env
    sudo chmod 660 .env
    GENERATE_KEY=true
fi

# Instalar dependencias de Composer sin warnings de caché
echo "📦 Instalando dependencias de Composer..."
if [ "\$VERBOSE" = true ]; then
    sudo -u \$WWW_USER COMPOSER_CACHE_DIR=/tmp/composer-cache composer install --no-dev --optimize-autoloader --no-interaction
else
    sudo -u \$WWW_USER COMPOSER_CACHE_DIR=/tmp/composer-cache composer install --no-dev --optimize-autoloader --no-interaction --no-progress > /dev/null
    echo "   ✅ Dependencias instaladas correctamente."
fi

# Generar App Key de Laravel si es un entorno limpio
if [ "\$GENERATE_KEY" = true ]; then
    echo "🔑 Generando la clave única de la aplicación Laravel..."
    sudo -u \$WWW_USER php artisan key:generate
    
    sudo chmod 640 .env

    DB_ENGINE=\$(grep -E "^DB_CONNECTION=" .env | cut -d'=' -f2 | tr -d "'\"" | xargs 2>/dev/null)
    case "\$DB_ENGINE" in
        pgsql) DB_FRIENDLY="PostgreSQL" ;;
        mysql) DB_FRIENDLY="MySQL" ;;
        sqlite) DB_FRIENDLY="SQLite" ;;
        sqlsrv) DB_FRIENDLY="SQL Server" ;;
        *) DB_FRIENDLY="Base de Datos (PostgreSQL/MySQL/SQLite)" ;;
    esac
    
    echo ""
    echo "🚨 ======================================================================"
    echo "🚨 [URGENTE]: Se ha creado tu .env inicial y generado la App Key."
    echo "🚨 [URGENTE]: Ingresa por SSH para configurar tus credenciales de \$DB_FRIENDLY en:"
    echo "🚨            \$REMOTE_PATH.env"
    echo "🚨 ======================================================================"
    echo ""
fi

# Cachear y optimizar Laravel
echo "⚡ Optimizando cachés del framework y Filament..."
sudo rm -f bootstrap/cache/*.php

if [ "\$VERBOSE" = true ]; then
    sudo -u \$WWW_USER php artisan optimize:clear
    sudo -u \$WWW_USER php artisan config:cache
    sudo -u \$WWW_USER php artisan route:cache
    sudo -u \$WWW_USER php artisan view:cache
    sudo -u \$WWW_USER php artisan filament:optimize 2>/dev/null || true
    sudo -u \$WWW_USER php artisan storage:link 2>/dev/null || true
    sudo -u \$WWW_USER php artisan livewire:publish --assets 2>/dev/null || true
else
    sudo -u \$WWW_USER php artisan optimize:clear > /dev/null 2>&1
    sudo -u \$WWW_USER php artisan config:cache > /dev/null 2>&1
    sudo -u \$WWW_USER php artisan route:cache > /dev/null 2>&1
    sudo -u \$WWW_USER php artisan view:cache > /dev/null 2>&1
    sudo -u \$WWW_USER php artisan filament:optimize > /dev/null 2>&1 || true
    sudo -u \$WWW_USER php artisan storage:link > /dev/null 2>&1 || true
    sudo -u \$WWW_USER php artisan livewire:publish --assets > /dev/null 2>&1 || true
    echo "   ✅ Cachés de Laravel y Filament optimizadas, assets publicados."
fi

# Ejecutar migraciones de base de datos
if [ "\$GENERATE_KEY" = false ]; then
    if [ "\$MIGRATE_FRESH" = true ]; then
        echo "🗄️  Ejecutando migrate:fresh --seed (Forzado por flag -m)..."
        if [ "\$VERBOSE" = true ]; then
            sudo -u \$WWW_USER php artisan migrate:fresh --seed --force
        else
            sudo -u \$WWW_USER php artisan migrate:fresh --seed --force > /dev/null
            echo "   ✅ Migraciones y semillas aplicadas desde cero."
        fi
    else
        echo "🗄️  Ejecutando migraciones de base de datos..."
        if [ "\$VERBOSE" = true ]; then
            sudo -u \$WWW_USER php artisan migrate --force
        else
            sudo -u \$WWW_USER php artisan migrate --force > /dev/null
            echo "   ✅ Migraciones aplicadas con éxito."
        fi
    fi
else
    echo "⏭️  Saltando ejecuciones de base de datos hasta que configures tu .env remoto."
fi

# Reinicios condicionales (Horizon y Reverb)
if [ "$RUN_HORIZON" = true ] && [ "\$GENERATE_KEY" = false ]; then
    echo "🔄 Reiniciando Laravel Horizon..."
    if [ "\$VERBOSE" = true ]; then
        sudo -u \$WWW_USER php artisan horizon:terminate 2>/dev/null || true
    else
        sudo -u \$WWW_USER php artisan horizon:terminate > /dev/null 2>&1 || true
        echo "   ✅ Horizon reiniciado de forma segura."
    fi
fi

if [ "$RUN_REVERB" = true ] && [ "\$GENERATE_KEY" = false ]; then
    echo "🔄 Reiniciando Laravel Reverb..."
    if [ "\$VERBOSE" = true ]; then
        sudo -u \$WWW_USER php artisan reverb:restart 2>/dev/null || echo "⚠️ Reverb restart omitido."
    else
        sudo -u \$WWW_USER php artisan reverb:restart > /dev/null 2>&1 || true
        echo "   ✅ Reverb reiniciado de forma segura."
    fi
fi

if [ "\$VERBOSE" = true ]; then
    sudo fix-perms \$PROJECT_FOLDER 2>/dev/null || true
else
    sudo fix-perms \$PROJECT_FOLDER > /dev/null 2>&1 || true
fi

EOF

if [ $? -ne 0 ]; then
    echo -e "\033[1;31m❌ [ERROR REMOTO]: Fallaron las tareas de optimización de Laravel en el servidor remoto.\033[0m"
    exit 1
fi

echo ""
echo "🎉 ======================================================================"
echo "🎉 ¡Despliegue a STAGE terminado con éxito en $SERVER_ALIAS!"
echo "🎉 Directorio: $REMOTE_PATH"
echo "🎉 ======================================================================"