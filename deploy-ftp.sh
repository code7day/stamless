#!/bin/bash

# ==============================================================================
# SCRIPT DE DESPLIEGUE AUTOMATIZADO - WEBSITE FRONTEND (FTP CPANEL / FEROZO)
# ==============================================================================

set -e

# Colores de terminal
COLOR_RESET="\033[0m"
COLOR_PRIMARY="\033[38;2;217;119;6m"     # Amber
COLOR_SUCCESS="\033[38;2;16;185;129m"    # Green
COLOR_INFO="\033[38;2;59;130;246m"       # Blue
COLOR_WARNING="\033[38;2;245;158;11m"    # Yellow
COLOR_ERROR="\033[38;2;239;68;68m"       # Red
COLOR_MUTED="\033[38;2;156;163;175m"     # Gray

RUN_BUILD=false
DIST_DIR=""

# Parsear argumentos de consola
while [[ $# -gt 0 ]]; do
    case "$1" in
        -b|--build)
            RUN_BUILD=true
            shift
            ;;
        -d|--dir)
            DIST_DIR="$2"
            shift 2
            ;;
        -h|--help)
            echo "Uso: $0 [-b|--build] [-d <directorio_dist>]"
            echo ""
            echo "Opciones:"
            echo "  -b, --build      Ejecuta 'npm run build' antes de desplegar"
            echo "  -d, --dir <path> Especifica la ruta local de la carpeta 'dist/'"
            echo "  -h, --help       Muestra esta ayuda"
            echo ""
            echo "Variables soportadas en .env o .env.production:"
            echo "  FTP_HOST         Servidor FTP (ej: c2701532.ferozo.com)"
            echo "  FTP_USER         Usuario FTP (ej: ftp@c2701532.ferozo.com)"
            echo "  FTP_PASSWORD     Contraseña FTP"
            echo "  FTP_REMOTE_DIR   Directorio remoto (ej: public_html)"
            echo "  FTP_PORT         Puerto FTP (default: 21)"
            exit 0
            ;;
        *)
            echo -e "${COLOR_ERROR}Opción no reconocida: $1${COLOR_RESET}"
            exit 1
            ;;
    esac
done

# Función para cargar variables desde archivos .env
load_env_file() {
    local env_path="$1"
    if [ -f "$env_path" ]; then
        echo -e "${COLOR_MUTED}⚙️  Leyendo configuración desde ${env_path}...${COLOR_RESET}"
        while IFS='=' read -r key value || [ -n "$key" ]; do
            # Omitir comentarios y líneas vacías
            [[ "$key" =~ ^[[:space:]]*# ]] && continue
            [[ -z "$key" ]] && continue
            key=$(echo "$key" | tr -d '[:space:]')
            value=$(echo "$value" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//")
            case "$key" in
                FTP_HOST) [ -z "$ENV_FTP_HOST" ] && ENV_FTP_HOST="$value" ;;
                FTP_USER) [ -z "$ENV_FTP_USER" ] && ENV_FTP_USER="$value" ;;
                FTP_PASS|FTP_PASSWORD) [ -z "$ENV_FTP_PASS" ] && ENV_FTP_PASS="$value" ;;
                FTP_REMOTE_DIR|FTP_DIR) [ -z "$ENV_FTP_REMOTE_DIR" ] && ENV_FTP_REMOTE_DIR="$value" ;;
                FTP_PORT) [ -z "$ENV_FTP_PORT" ] && ENV_FTP_PORT="$value" ;;
            esac
        done < "$env_path"
    fi
}

# Cargar archivos .env en orden de prioridad
load_env_file ".env.production"
load_env_file ".env.local"
load_env_file ".env"
load_env_file "../cica360/.env.production"
load_env_file "../cica360/.env"
load_env_file "cica360/.env.production"
load_env_file "cica360/.env"

# Prioridad: Variables de entorno explícitas > Archivo .env > Valores por defecto de Ferozo
FTP_HOST="${FTP_HOST:-${ENV_FTP_HOST:-c2701532.ferozo.com}}"
FTP_USER="${FTP_USER:-${ENV_FTP_USER:-ftp@c2701532.ferozo.com}}"
FTP_REMOTE_DIR="${FTP_REMOTE_DIR:-${ENV_FTP_REMOTE_DIR:-public_html}}"
FTP_PORT="${FTP_PORT:-${ENV_FTP_PORT:-21}}"
FTP_PASS="${FTP_PASS:-${FTP_PASSWORD:-${ENV_FTP_PASS}}}"

echo -e "\n${COLOR_PRIMARY}========================================================================${COLOR_RESET}"
echo -e "${COLOR_PRIMARY}🚀 DESPLIEGUE DE WEBSITE A CPANEL / FEROZO (FTP)${COLOR_RESET}"
echo -e "${COLOR_PRIMARY}========================================================================${COLOR_RESET}"
echo -e "Servidor FTP:   ${COLOR_INFO}ftp://${FTP_HOST}:${FTP_PORT}${COLOR_RESET}"
echo -e "Usuario FTP:    ${COLOR_INFO}${FTP_USER}${COLOR_RESET}"
echo -e "Directorio:     ${COLOR_INFO}/${FTP_REMOTE_DIR}/${COLOR_RESET}"
echo -e "Autenticación:  ${COLOR_INFO}$([ -n "$FTP_PASS" ] && echo "Cargada desde .env" || echo "Pendiente por terminal")${COLOR_RESET}"
echo -e "${COLOR_PRIMARY}========================================================================${COLOR_RESET}"

# 1. Localizar la carpeta dist
if [ -n "$DIST_DIR" ]; then
    LOCAL_DIST="$DIST_DIR"
elif [ -d "dist" ]; then
    LOCAL_DIST="dist"
elif [ -d "../cica360/dist" ]; then
    LOCAL_DIST="../cica360/dist"
elif [ -d "cica360/dist" ]; then
    LOCAL_DIST="cica360/dist"
elif [ -d "../dist" ]; then
    LOCAL_DIST="../dist"
else
    if [ -d "../cica360" ]; then
        LOCAL_DIST="../cica360/dist"
    else
        LOCAL_DIST="dist"
    fi
fi

# 2. Compilar si se solicitó o si dist no existe
if [ "$RUN_BUILD" = true ] || [ ! -d "$LOCAL_DIST" ]; then
    PROJECT_ROOT="."
    if [ ! -f "package.json" ] && [ -f "../cica360/package.json" ]; then
        PROJECT_ROOT="../cica360"
    elif [ -f "cica360/package.json" ]; then
        PROJECT_ROOT="cica360"
    fi

    if [ -f "$PROJECT_ROOT/package.json" ]; then
        echo -e "\n${COLOR_INFO}📦 Compilando proyecto en ${PROJECT_ROOT} ('npm run build')...${COLOR_RESET}"
        (cd "$PROJECT_ROOT" && npm run build)
        LOCAL_DIST="$PROJECT_ROOT/dist"
    fi
fi

# Validar existencia de dist
if [ ! -d "$LOCAL_DIST" ]; then
    echo -e "\n${COLOR_ERROR}❌ Error: No se encontró la carpeta 'dist/' en '${LOCAL_DIST}'.${COLOR_RESET}"
    echo -e "${COLOR_MUTED}Ejecuta 'npm run build' en tu proyecto frontend o usa el flag '-b'.${COLOR_RESET}"
    exit 1
fi

TOTAL_FILES=$(find "$LOCAL_DIST" -type f | wc -l | tr -d ' ')
echo -e "\n${COLOR_SUCCESS}✅ Directorio local listo:${COLOR_RESET} ${LOCAL_DIST} (${TOTAL_FILES} archivos)"

# 3. Solicitar contraseña FTP si no vino en el .env
if [ -z "$FTP_PASS" ]; then
    echo -e "\n${COLOR_WARNING}🔑 Ingresa la contraseña para ${FTP_USER}:${COLOR_RESET}"
    read -r -s FTP_PASS
    echo ""
    if [ -z "$FTP_PASS" ]; then
        echo -e "${COLOR_ERROR}❌ La contraseña no puede estar vacía.${COLOR_RESET}"
        exit 1
    fi
fi

# 4. Desplegar vía FTP (Prioriza LFTP para sincronización espejo incremental; si no, usa Python ftplib nativo)
echo -e "\n${COLOR_INFO}📡 Conectando y sincronizando archivos con el servidor FTP...${COLOR_RESET}"

if command -v lftp >/dev/null 2>&1; then
    echo -e "${COLOR_MUTED}Usando lftp (mirror incremental)...${COLOR_RESET}"
    
    lftp -u "${FTP_USER}","${FTP_PASS}" "${FTP_HOST}" <<EOF
set ssl:verify-certificate no
set ftp:ssl-allow yes
set net:timeout 15
set net:max-retries 3
cd ${FTP_REMOTE_DIR} || mkdir -p ${FTP_REMOTE_DIR} && cd ${FTP_REMOTE_DIR}
mirror --reverse --delete --verbose --exclude .well-known/ --exclude cgi-bin/ ${LOCAL_DIST}/ .
bye
EOF

else
    echo -e "${COLOR_MUTED}lftp no encontrado. Usando motor nativo Python ftplib...${COLOR_RESET}"

    python3 - <<PYEOF
import os
import sys
import ftplib

host = "${FTP_HOST}"
user = "${FTP_USER}"
password = """${FTP_PASS}"""
remote_base = "${FTP_REMOTE_DIR}"
local_base = os.path.abspath("${LOCAL_DIST}")

print(f"-> Conectando a {host}...")
try:
    ftp = ftplib.FTP(host, timeout=30)
    ftp.login(user, password)
    ftp.set_pasv(True)
    print("-> Autenticado correctamente.")
except Exception as e:
    print(f"Error de conexión FTP: {e}", file=sys.stderr)
    sys.exit(1)

def ensure_remote_dir(path):
    parts = path.strip("/").split("/")
    current = ""
    for part in parts:
        if not part:
            continue
        current += "/" + part
        try:
            ftp.cwd(current)
        except Exception:
            try:
                ftp.mkd(current)
                ftp.cwd(current)
            except Exception as e:
                pass

try:
    ensure_remote_dir(remote_base)
except Exception as e:
    print(f"Error al acceder a /{remote_base}: {e}", file=sys.stderr)
    sys.exit(1)

total_uploaded = 0
for root, dirs, files in os.walk(local_base):
    rel_path = os.path.relpath(root, local_base)
    if rel_path == ".":
        remote_dir = f"/{remote_base}"
    else:
        remote_dir = f"/{remote_base}/{rel_path.replace(os.sep, '/')}"
    
    ensure_remote_dir(remote_dir)
    
    for file in files:
        if file.startswith(".DS_Store"):
            continue
        local_file_path = os.path.join(root, file)
        with open(local_file_path, "rb") as f:
            try:
                ftp.storbinary(f"STOR {file}", f)
                total_uploaded += 1
                sys.stdout.write(f"\r-> Subidos {total_uploaded}/{${TOTAL_FILES}} archivos... ({file[:35]})")
                sys.stdout.flush()
            except Exception as e:
                print(f"\nError subiendo {file}: {e}", file=sys.stderr)

ftp.quit()
print(f"\n-> ¡{total_uploaded} archivos transferidos con éxito!")
PYEOF

fi

echo -e "\n${COLOR_SUCCESS}========================================================================${COLOR_RESET}"
echo -e "${COLOR_SUCCESS}🎉 ¡DESPLIEGUE COMPLETADO CON ÉXITO EN CPANEL / FEROZO!${COLOR_RESET}"
echo -e "${COLOR_SUCCESS}========================================================================${COLOR_RESET}"
echo -e "Ruta remota actualizada: ${COLOR_INFO}/${FTP_REMOTE_DIR}/${COLOR_RESET}"
echo -e "Tu sitio web ya está disponible en vivo en tu dominio."
