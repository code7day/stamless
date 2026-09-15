#!/bin/bash

# ==============================================================================
# SCRIPT DE DESPLIEGUE AUTOMATIZADO - BEEZYNC (CON CONTROL DE VERBOSIDAD Y SEGURIDAD)
# ==============================================================================

# 1. Parsear argumentos de consola (Detectar modo verbose -v)
VERBOSE=false
MIGRATE_FRESH=false
while getopts "vm" opt; do
    case $opt in
        v) VERBOSE=true ;;
        m) MIGRATE_FRESH=true ;;
        *) echo "Uso: $0 [-v] [-m]" && exit 1 ;;
    esac
done

# 2. Control Estricto y Validación de Variables en el .env Local
ENV_FILE=".env"
VARIABLES_MISSING=false
INVALID_FORMAT=false
REASON=""

if [ -f "$ENV_FILE" ]; then
    SERVER_ALIAS=$(grep -E "^BEEZYNC_DEPLOY_SERVER=" "$ENV_FILE" | cut -d'=' -f2 | tr -d "'\"")
    PROJECT_BASE=$(grep -E "^BEEZYNC_DEPLOY_DOMAIN=" "$ENV_FILE" | cut -d'=' -f2 | tr -d "'\"")
    IS_SUBDOMAIN=$(grep -E "^BEEZYNC_DEPLOY_SUBDOMAIN=" "$ENV_FILE" | cut -d'=' -f2 | tr -d "'\"")
    RUN_HORIZON=$(grep -E "^BEEZYNC_DEPLOY_RUN_HORIZON=" "$ENV_FILE" | cut -d'=' -f2 | tr -d "'\"")
    RUN_REVERB=$(grep -E "^BEEZYNC_DEPLOY_RUN_REVERB=" "$ENV_FILE" | cut -d'=' -f2 | tr -d "'\"")
else
    VARIABLES_MISSING=true
    REASON="No se encontró el archivo .env local en el directorio raíz."
fi

# Configurar valores por defecto para servicios opcionales
[ -z "$RUN_HORIZON" ] && RUN_HORIZON=false
[ -z "$RUN_REVERB" ] && RUN_REVERB=false

# Validar si alguna de las variables críticas está vacía o no existe
if [ -z "$SERVER_ALIAS" ] || [ -z "$PROJECT_BASE" ] || [ -z "$IS_SUBDOMAIN" ]; then
    VARIABLES_MISSING=true
    REASON="Faltan una o más variables obligatorias de implementación en tu .env local (SERVER, DOMAIN, SUBDOMAIN)."
fi

# Validar consistencia y nomenclatura institucional si no faltan variables
if [ "$VARIABLES_MISSING" = false ]; then
    # Exigir que el servidor siga la estructura server-[NOMBRE_SERVIDOR]
    if [[ ! "$SERVER_ALIAS" =~ ^server-[a-zA-Z0-9_-]+$ ]]; then
        INVALID_FORMAT=true
        REASON="La variable BEEZYNC_DEPLOY_SERVER debe seguir la nomenclatura institucional 'server-[NOMBRE_SERVIDOR]' (Ejemplo: BEEZYNC_DEPLOY_SERVER=\"server-beezync\")."
    fi
    
    # Exigir estructura de dominio o subdominio real
    if [[ ! "$PROJECT_BASE" =~ ^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]]; then
        INVALID_FORMAT=true
        REASON="La variable BEEZYNC_DEPLOY_DOMAIN debe tener un formato de dominio o subdominio válido (Ejemplo: BEEZYNC_DEPLOY_DOMAIN=\"console.beezync.com\")."
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
        # Escanear ~/.ssh/config para sugerir alias de servidores institucionales reales
        SUGGESTED_HOSTS=$(grep -iE "^[[:space:]]*Host[[:space:]]+" "$SSH_CONFIG" 2>/dev/null | sed -e 's/^[[:space:]]*Host[[:space:]]*//I' | tr ' ' '\n' | grep -E "^server-" | tr '\n' ' ' | xargs 2>/dev/null)
        if [ -z "$SUGGESTED_HOSTS" ]; then
            SUGGESTED_HOSTS="server-emaus (institucional por defecto)"
        fi
        REASON="El alias de servidor '${SERVER_ALIAS}' no está configurado en tu archivo ~/.ssh/config local.\n      Tus alias de servidor válidos detectados localmente son: \033[1;32m${SUGGESTED_HOSTS}\033[0m"
    fi
fi

# Imprimir pantalla de ayuda (HELP) si falla la validación e interrumpir ejecución
if [ "$VARIABLES_MISSING" = true ] || [ "$INVALID_FORMAT" = true ]; then
    echo -e "\033[1;31m❌ [ERROR DE CONFIGURACIÓN DE DESPLIEGUE - BEEZYNC]\033[0m"
    echo "========================================================================"
    echo -e "\033[1;33m⚠️  Motivo: $REASON\033[0m"
    echo "========================================================================"
    echo "Para poder realizar el despliegue hacia Producción/Staging, DEBES abrir tu .env,"
    echo "ir al final del archivo, pegar el siguiente bloque y configurar tus accesos:"
    echo "========================================================================"
    echo ""
    echo "# =============================================================================="
    echo "# CONFIGURACIÓN DE IMPLEMENTACIÓN DE INFRAESTRUCTURA - BEEZYNC"
    echo "# =============================================================================="
    echo "BEEZYNC_DEPLOY_SERVER=\"server-beezync\""
    echo "BEEZYNC_DEPLOY_DOMAIN=\"console.beezync.com\""
    echo "BEEZYNC_DEPLOY_SUBDOMAIN=true"
    echo ""
    echo "# (Las variables de Horizon y Reverb son opcionales, asumen false por defecto si se omiten)"
    echo ""
    echo "========================================================================"
    echo -e "\033[1;33m⚠️  El despliegue local ha sido abortado hasta que configures correctamente estas variables.\033[0m"
    exit 1
fi

# ==============================================================================
# REGLA INSTITUCIONAL DE RUTAS DE STAGING
# ==============================================================================
# Dominio principal -> usa prefijo con guion bajo: stage_ (Ej. stage_beezync)
# Subdominio         -> usa prefijo con guion medio: stage- (Ej. stage-console_beezync)

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
    echo -e "\033[1;33m⚠️  [ADVERTENCIA]: Tienes cambios locales sin commitear (se subirán tal como están en tu disco duro).\033[0m"
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
echo "🚀 Iniciando el despliegue hacia $SERVER_ALIAS en $PROJECT_FOLDER..."

# 3. Verificación de directorio y reparación preventiva en el servidor remoto
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
            sudo fix-perms '$PROJECT_FOLDER' > /dev/null;
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
        echo "   ✅ Compilación de Node.js completada."
    fi
    if [ $? -ne 0 ]; then
        echo -e "\033[1;31m❌ [ERROR FRONTEND]: Falló la compilación de recursos locales de Node.\033[0m"
        exit 1
    fi
fi

# 5. Sincronización robusta con Rsync (Exclusiones estrictas y verbosidad dinámica)
if [ "$VERBOSE" = true ]; then
    echo "📂 3. Sincronizando archivos (Modo detallado)..."
    rsync -av --no-perms --no-owner --no-group --delete --rsync-path="sudo rsync" \
        --exclude='/.git/' \
        --exclude='/.github/' \
        --exclude='/.env' \
        --exclude='/.pnpm-store/' \
        --exclude='/.DS_Store' \
        --exclude='/.idea/' \
        --exclude='/.vscode/' \
        --exclude='/.editorconfig' \
        --exclude='/.gitattributes' \
        --exclude='/.npmrc' \
        --exclude='/deploy.sh' \
        --exclude='/production.sh' \
        --exclude='/AGENTS.md' \
        --exclude='/CLAUDE.md' \
        --exclude='/README.md' \
        --exclude='/BEEZYNC_BUSINESS_VISION.md' \
        --exclude='/compose.yaml' \
        --exclude='/Dockerfile' \
        --exclude='/docker/' \
        --exclude='/storage/' \
        --exclude='/node_modules/' \
        --exclude='/vendor/' \
        --exclude='/public/hot' \
        --exclude='/public/storage' \
        ./ $SERVER_ALIAS:$REMOTE_PATH
else
    echo "📂 3. Sincronizando archivos con el servidor remoto..."
    rsync -a --no-perms --no-owner --no-group --delete --rsync-path="sudo rsync" \
        --exclude='/.git/' \
        --exclude='/.github/' \
        --exclude='/.env' \
        --exclude='/.pnpm-store/' \
        --exclude='/.DS_Store' \
        --exclude='/.idea/' \
        --exclude='/.vscode/' \
        --exclude='/.editorconfig' \
        --exclude='/.gitattributes' \
        --exclude='/.npmrc' \
        --exclude='/deploy.sh' \
        --exclude='/production.sh' \
        --exclude='/AGENTS.md' \
        --exclude='/CLAUDE.md' \
        --exclude='/README.md' \
        --exclude='/BEEZYNC_BUSINESS_VISION.md' \
        --exclude='/compose.yaml' \
        --exclude='/Dockerfile' \
        --exclude='/docker/' \
        --exclude='/storage/' \
        --exclude='/node_modules/' \
        --exclude='/vendor/' \
        --exclude='/public/hot' \
        --exclude='/public/storage' \
        ./ $SERVER_ALIAS:$REMOTE_PATH
fi

if [ $? -ne 0 ]; then
    echo -e "\033[1;31m❌ [ERROR RSYNC]: Falló la sincronización física de archivos con el servidor.\033[0m"
    exit 1
else
    echo "   ✅ Sincronización de archivos finalizada con éxito."
fi

# 6. Configuración y optimización remota
echo "⚙️  4. Ejecutando despliegue y optimización en el servidor..."
ssh -t $SERVER_ALIAS << EOF
# Configurar control de errores en el bloque remoto
set -e

# Recibir variables desde el script local
PROJECT_FOLDER="${PROJECT_FOLDER}"
REMOTE_PATH="${REMOTE_PATH}"
VERBOSE=${VERBOSE}
MIGRATE_FRESH=${MIGRATE_FRESH}
RUN_HORIZON=${RUN_HORIZON}
RUN_REVERB=${RUN_REVERB}
WWW_USER=${WWW_USER}

cd $REMOTE_PATH

if [ "$VERBOSE" = true ]; then
    sudo fix-perms $PROJECT_FOLDER
else
    sudo fix-perms $PROJECT_FOLDER > /dev/null 2>&1
fi

# Asegurar directorios de Laravel
sudo mkdir -p bootstrap/cache
sudo mkdir -p storage/framework/{cache,sessions,views}
sudo chown -R $WWW_USER:$WWW_USER bootstrap/cache storage

# Limpiar cachés antiguas
sudo rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php bootstrap/cache/routes-v7.php
sudo rm -f public/hot

# Control inteligente y seguro de archivo .env
GENERATE_KEY=false
if [ ! -f ".env" ]; then
    echo "⚠️  [ADVERTENCIA]: No se detectó un archivo .env remoto en \$PROJECT_FOLDER."
    echo "⚡ Generando .env base a partir de .env.example..."
    
    # Copiar usando privilegios de www-data
    sudo -u $WWW_USER cp .env.example .env
    sudo chown edu:$WWW_USER .env
    sudo chmod 660 .env
    GENERATE_KEY=true
fi

# Instalar dependencias de Composer sin warnings de caché
echo "📦 Instalando dependencias de Composer..."
if [ "$VERBOSE" = true ]; then
    sudo -u $WWW_USER COMPOSER_CACHE_DIR=/tmp/composer-cache composer install --no-dev --optimize-autoloader --no-interaction
else
    sudo -u $WWW_USER COMPOSER_CACHE_DIR=/tmp/composer-cache composer install --no-dev --optimize-autoloader --no-interaction --no-progress > /dev/null
    echo "   ✅ Dependencias instaladas correctamente."
fi

# Generar App Key de Laravel si es un entorno limpio
if [ "\$GENERATE_KEY" = true ]; then
    echo "🔑 Generando la clave única de la aplicación Laravel..."
    sudo -u $WWW_USER php artisan key:generate
    
    # Cerrar el .env a permisos estrictos de lectura
    sudo chmod 640 .env

    # Leer el motor de base de datos configurado de forma dinámica
    DB_ENGINE=\$(grep -E "^DB_CONNECTION=" .env | cut -d'=' -f2 | tr -d "'\"" | xargs 2>/dev/null)
    case "\$DB_ENGINE" in
        pgsql) DB_FRIENDLY="PostgreSQL" ;;
        mysql) DB_FRIENDLY="MySQL" ;;
        sqlite) DB_FRIENDLY="SQLite" ;;
        sqlsrv) DB_FRIENDLY="SQL Server" ;;
        *) DB_FRIENDLY="Base de Datos (MySQL/PostgreSQL/SQLite/SQL Server)" ;;
    esac
    
    echo ""
    echo "🚨 ======================================================================"
    echo "🚨 [URGENTE]: Se ha creado tu .env inicial y generado la App Key."
    echo "🚨 [URGENTE]: DEBES ingresar por SSH de inmediato a configurar tus"
    echo "🚨            accesos reales de \$DB_FRIENDLY en:"
    echo "🚨            $REMOTE_PATH.env"
    echo "🚨 ======================================================================"
    echo ""
fi

# Cachear Laravel
echo "⚡ Optimizando caches del framework..."
# Prevenir Laravel Catch-22 (conexiones a DB antiguas cacheadas que hacen crashear optimize:clear)
sudo rm -f bootstrap/cache/*.php

if [ "$VERBOSE" = true ]; then
    sudo -u $WWW_USER php artisan optimize:clear
    sudo -u $WWW_USER php artisan config:cache
    sudo -u $WWW_USER php artisan view:cache
    sudo -u $WWW_USER php artisan storage:link
    sudo -u $WWW_USER php artisan livewire:publish --assets
else
    sudo -u $WWW_USER php artisan optimize:clear > /dev/null 2>&1
    sudo -u $WWW_USER php artisan config:cache > /dev/null 2>&1
    sudo -u $WWW_USER php artisan view:cache > /dev/null 2>&1
    sudo -u $WWW_USER php artisan storage:link > /dev/null 2>&1
    sudo -u $WWW_USER php artisan livewire:publish --assets > /dev/null 2>&1
    echo "   ✅ Cachés de Laravel optimizadas y assets publicadas."
fi

# ==============================================================================
# HACK: Forzar publicación física de JS de Flux para evitar bloqueos de Nginx (404 Not Found)
# ==============================================================================
sudo -u $WWW_USER mkdir -p public/flux
if [ -f "vendor/livewire/flux-pro/dist/flux.min.js" ]; then
    sudo -u $WWW_USER cp vendor/livewire/flux-pro/dist/flux.min.js public/flux/flux.min.js
    sudo -u $WWW_USER cp vendor/livewire/flux-pro/dist/flux.js public/flux/flux.js 2>/dev/null || sudo -u $WWW_USER cp vendor/livewire/flux-pro/dist/flux.min.js public/flux/flux.js
else
    sudo -u $WWW_USER cp vendor/livewire/flux/dist/flux.min.js public/flux/flux.min.js 2>/dev/null || sudo -u $WWW_USER cp vendor/livewire/flux/dist/flux-lite.min.js public/flux/flux.min.js
    sudo -u $WWW_USER cp vendor/livewire/flux/dist/flux.js public/flux/flux.js 2>/dev/null || sudo -u $WWW_USER cp vendor/livewire/flux/dist/flux-lite.min.js public/flux/flux.js
fi

# Ejecutar migraciones (solo si no es una instalación limpia que requiere edición de .env)
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
    echo "⏭️  Saltando ejecuciones de base de datos hasta que configures tu .env"
fi

# 7. Reinicios condicionales (Horizon y Reverb) bajo demanda
if [ "$RUN_HORIZON" = true ] && [ "\$GENERATE_KEY" = false ]; then
    echo "🔄 Reiniciando Laravel Horizon..."
    if [ "$VERBOSE" = true ]; then
        sudo -u $WWW_USER php artisan horizon:terminate
    else
        sudo -u $WWW_USER php artisan horizon:terminate > /dev/null 2>&1
        echo "   ✅ Horizon reiniciado de forma segura."
    fi
fi

if [ "$RUN_REVERB" = true ] && [ "\$GENERATE_KEY" = false ]; then
    echo "🔄 Reiniciando Laravel Reverb..."
    if [ "$VERBOSE" = true ]; then
        sudo -u $WWW_USER php artisan reverb:restart || echo "⚠️ Reverb restart omitido."
    else
        sudo -u $WWW_USER php artisan reverb:restart > /dev/null 2>&1 || echo "⚠️ Reverb restart omitido."
        echo "   ✅ Reverb reiniciado de forma segura."
    fi
fi

if [ "$VERBOSE" = true ]; then
    sudo fix-perms $PROJECT_FOLDER
else
    sudo fix-perms $PROJECT_FOLDER > /dev/null 2>&1
fi

EOF

if [ $? -ne 0 ]; then
    echo -e "\033[1;31m❌ [ERROR REMOTO]: Fallaron las tareas de optimización de Laravel en el servidor remoto.\033[0m"
    exit 1
fi

echo "✅ ¡Despliegue terminado con éxito!"