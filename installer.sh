#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

REPO_URL="https://github.com/Cyberzone24/Portflow.git"
DEFAULT_BRANCH="main"
DEFAULT_TARGET_DIR="/var/www/html"
LOG_FILE="/tmp/portflow-installer-$(date +%Y%m%d-%H%M%S).log"

CURRENT_STEP=0
TOTAL_STEPS=11

if [[ -t 1 ]]; then
    COLOR_BLUE='\033[1;34m'
    COLOR_GREEN='\033[1;32m'
    COLOR_YELLOW='\033[1;33m'
    COLOR_RED='\033[1;31m'
    COLOR_RESET='\033[0m'
else
    COLOR_BLUE=''
    COLOR_GREEN=''
    COLOR_YELLOW=''
    COLOR_RED=''
    COLOR_RESET=''
fi

SUDO=''
TARGET_DIR="$DEFAULT_TARGET_DIR"
GIT_REF="$DEFAULT_BRANCH"
CONFIGURE_DB='y'
INTERACTIVE='true'
FORCE_ENV_WRITE='false'
PG_USERNAME='portflow'
PG_DBNAME='portflow'
PG_PASSWORD=''
PHP_FPM_SERVICE=''
PHP_FPM_SOCKET=''
PUBLIC_BASE_URL=''
PORTFLOW_SSL='FALSE'
PORTFLOW_REGISTER='FALSE'
PORTFLOW_LOG_LEVEL='1'
AUTOMATION_SECRET=''
MAIL_HOST=''
MAIL_USER=''
MAIL_PASSWORD=''
MAIL_PORT='587'
MAIL_SMTPAUTH='FALSE'
MAIL_SMTPSECURE=''
LDAP_ENABLED='FALSE'
LDAP_SERVER=''
LDAP_PORT='389'
LDAP_BASEDN=''
LDAP_USERDN=''
LDAP_FILTER=''
LDAP_BIND='FALSE'
LDAP_BIND_USER=''
LDAP_BIND_PASSWORD=''
LDAP_TRUST='FALSE'
WEB_PATH=''
SETUP_URLS=()

usage() {
    cat <<'EOF'
Usage: installer.sh [options]

Options:
  --non-interactive           Run without prompts and use flags/defaults.
  --target-dir PATH           Installation target directory.
  --git-ref REF               Git branch or tag to install.
  --base-url URL              Public Portflow base URL.
  --db-name NAME              PostgreSQL database name.
  --db-user NAME              PostgreSQL username.
  --db-password PASSWORD      PostgreSQL password.
  --skip-db                   Do not create/update PostgreSQL user and database.
  --register                  Enable self-registration in Portflow.
  --log-level LEVEL           Portflow log level (0-4).
  --mail-host HOST            Bootstrap mail host.
  --mail-user USER            Bootstrap mail user.
  --mail-password PASSWORD    Bootstrap mail password.
  --mail-port PORT            Bootstrap mail port.
  --mail-smtpauth true|false  Bootstrap SMTP auth flag.
  --mail-smtpsecure VALUE     Bootstrap SMTP security (empty, tls, ssl).
  --ldap-enabled true|false   Bootstrap LDAP enabled flag.
  --ldap-server HOST          Bootstrap LDAP server.
  --ldap-port PORT            Bootstrap LDAP port.
  --ldap-basedn DN            Bootstrap LDAP base DN.
  --ldap-userdn DN            Bootstrap LDAP user DN.
  --ldap-filter FILTER        Bootstrap LDAP filter.
  --ldap-bind true|false      Bootstrap LDAP bind flag.
  --ldap-bind-user USER       Bootstrap LDAP bind user.
  --ldap-bind-password PASS   Bootstrap LDAP bind password.
  --ldap-trust true|false     Bootstrap LDAP trust flag.
  --automation-secret SECRET  Bootstrap automation secret.
  --force-env-write           Overwrite an existing .env file.
  --help                      Show this help.
EOF
}

normalize_bool() {
    local value="${1:-}"
    case "${value,,}" in
        1|true|yes|y|on)
            printf 'TRUE\n'
            ;;
        0|false|no|n|off|'')
            printf 'FALSE\n'
            ;;
        *)
            fail "Ungueltiger Boolean-Wert: $value"
            ;;
    esac
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --non-interactive)
                INTERACTIVE='false'
                shift
                ;;
            --target-dir)
                TARGET_DIR="$2"
                shift 2
                ;;
            --git-ref)
                GIT_REF="$2"
                shift 2
                ;;
            --base-url)
                PUBLIC_BASE_URL="$2"
                shift 2
                ;;
            --db-name)
                PG_DBNAME="$2"
                shift 2
                ;;
            --db-user)
                PG_USERNAME="$2"
                shift 2
                ;;
            --db-password)
                PG_PASSWORD="$2"
                shift 2
                ;;
            --skip-db)
                CONFIGURE_DB='n'
                shift
                ;;
            --register)
                PORTFLOW_REGISTER='TRUE'
                shift
                ;;
            --log-level)
                PORTFLOW_LOG_LEVEL="$2"
                shift 2
                ;;
            --mail-host)
                MAIL_HOST="$2"
                shift 2
                ;;
            --mail-user)
                MAIL_USER="$2"
                shift 2
                ;;
            --mail-password)
                MAIL_PASSWORD="$2"
                shift 2
                ;;
            --mail-port)
                MAIL_PORT="$2"
                shift 2
                ;;
            --mail-smtpauth)
                MAIL_SMTPAUTH=$(normalize_bool "$2")
                shift 2
                ;;
            --mail-smtpsecure)
                MAIL_SMTPSECURE="$2"
                shift 2
                ;;
            --ldap-enabled)
                LDAP_ENABLED=$(normalize_bool "$2")
                shift 2
                ;;
            --ldap-server)
                LDAP_SERVER="$2"
                shift 2
                ;;
            --ldap-port)
                LDAP_PORT="$2"
                shift 2
                ;;
            --ldap-basedn)
                LDAP_BASEDN="$2"
                shift 2
                ;;
            --ldap-userdn)
                LDAP_USERDN="$2"
                shift 2
                ;;
            --ldap-filter)
                LDAP_FILTER="$2"
                shift 2
                ;;
            --ldap-bind)
                LDAP_BIND=$(normalize_bool "$2")
                shift 2
                ;;
            --ldap-bind-user)
                LDAP_BIND_USER="$2"
                shift 2
                ;;
            --ldap-bind-password)
                LDAP_BIND_PASSWORD="$2"
                shift 2
                ;;
            --ldap-trust)
                LDAP_TRUST=$(normalize_bool "$2")
                shift 2
                ;;
            --automation-secret)
                AUTOMATION_SECRET="$2"
                shift 2
                ;;
            --force-env-write)
                FORCE_ENV_WRITE='true'
                shift
                ;;
            --help|-h)
                usage
                exit 0
                ;;
            *)
                fail "Unbekannte Option: $1"
                ;;
        esac
    done
}

log() {
    local level="$1"
    shift
    printf '[%s] %s\n' "$level" "$*"
}

info() {
    printf '%b[INFO]%b %s\n' "$COLOR_BLUE" "$COLOR_RESET" "$*"
}

success() {
    printf '%b[ OK ]%b %s\n' "$COLOR_GREEN" "$COLOR_RESET" "$*"
}

warn() {
    printf '%b[WARN]%b %s\n' "$COLOR_YELLOW" "$COLOR_RESET" "$*"
}

fail() {
    printf '%b[FAIL]%b %s\n' "$COLOR_RED" "$COLOR_RESET" "$*" >&2
    printf 'Details: %s\n' "$LOG_FILE" >&2
    exit 1
}

on_error() {
    local exit_code="$?"
    fail "Installer aborted in line ${BASH_LINENO[0]} with exit code ${exit_code}."
}

trap on_error ERR

run_privileged() {
    if [[ -n "$SUDO" ]]; then
        sudo "$@"
    else
        "$@"
    fi
}

run_psql_as_postgres() {
    if [[ -n "$SUDO" ]]; then
        sudo -u postgres psql "$@"
    else
        runuser -u postgres -- psql "$@"
    fi
}

run_cmd() {
    local description="$1"
    shift

    info "$description"
    if "$@" >>"$LOG_FILE" 2>&1; then
        success "$description"
    else
        fail "$description failed."
    fi
}

run_root_cmd() {
    local description="$1"
    shift

    info "$description"
    if run_privileged "$@" >>"$LOG_FILE" 2>&1; then
        success "$description"
    else
        fail "$description failed."
    fi
}

run_lighty_enable_mod() {
    local module_name="$1"
    local output=''
    local exit_code=0

    info "Lighttpd Modul aktivieren: $module_name"
    if output=$(run_privileged env LC_ALL=C LANGUAGE=C lighty-enable-mod "$module_name" 2>&1); then
        exit_code=0
    else
        exit_code=$?
    fi

    printf '%s\n' "$output" >>"$LOG_FILE"

    if [[ "$exit_code" -eq 0 ]] || grep -qi 'already enabled' <<<"$output"; then
        success "Lighttpd Modul aktivieren: $module_name"
    else
        fail "Lighttpd Modul $module_name konnte nicht aktiviert werden."
    fi
}

add_setup_url_candidate() {
    local candidate="$1"
    local existing=''

    [[ -n "$candidate" ]] || return
    for existing in "${SETUP_URLS[@]:-}"; do
        if [[ "$existing" == "$candidate" ]]; then
            return
        fi
    done

    SETUP_URLS+=("$candidate")
}

detect_public_urls() {
    local host=''
    local ip=''

    WEB_PATH=''
    if [[ "$TARGET_DIR" == '/var/www/html' ]]; then
        WEB_PATH=''
    elif [[ "$TARGET_DIR" == /var/www/html/* ]]; then
        WEB_PATH="${TARGET_DIR#/var/www/html}"
    fi

    host=$(hostname -f 2>>"$LOG_FILE" || hostname 2>>"$LOG_FILE" || true)
    add_setup_url_candidate "http://localhost${WEB_PATH}"

    if [[ -n "$host" ]]; then
        add_setup_url_candidate "http://${host}${WEB_PATH}"
    fi

    while read -r ip; do
        [[ -n "$ip" ]] || continue
        add_setup_url_candidate "http://${ip}${WEB_PATH}"
    done < <(hostname -I 2>>"$LOG_FILE" | tr ' ' '\n')

    if [[ ${#SETUP_URLS[@]} -gt 0 ]]; then
        PUBLIC_BASE_URL="${SETUP_URLS[0]}"
    fi
}

generate_automation_secret() {
    if command -v openssl >/dev/null 2>&1; then
        AUTOMATION_SECRET=$(openssl rand -hex 16 2>>"$LOG_FILE" || true)
    fi

    if [[ -z "$AUTOMATION_SECRET" ]]; then
        AUTOMATION_SECRET=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
    fi
}

write_bootstrap_env() {
    local env_file="$TARGET_DIR/.env"
    local tmp_env=''

    if [[ -f "$env_file" && "$FORCE_ENV_WRITE" != 'true' ]]; then
        warn ".env bereits vorhanden. Installer-Werte werden nicht automatisch überschrieben."
        return
    fi

    tmp_env=$(mktemp)
    cat >"$tmp_env" <<EOF
# Portflow Configuration - Generated by installer
# Logging
LOG_LEVEL=$PORTFLOW_LOG_LEVEL

# Database Configuration
DB_TYPE=pgsql
DB_SERVER=localhost
DB_PORT=5432
DB_NAME=${PG_DBNAME}
DB_USER=${PG_USERNAME}
DB_PASSWORD=${PG_PASSWORD}

# Portflow Server Settings
PORTFLOW_HOSTNAME=${PUBLIC_BASE_URL}
PORTFLOW_SECURE=${PORTFLOW_SSL}
PORTFLOW_REGISTER=${PORTFLOW_REGISTER}
PORTFLOW_FIRST_RUN=true

# Mail Configuration
MAIL_HOST=${MAIL_HOST}
MAIL_USER=${MAIL_USER}
MAIL_PASSWORD=${MAIL_PASSWORD}
MAIL_PORT=${MAIL_PORT}
MAIL_SMTPAUTH=${MAIL_SMTPAUTH}
MAIL_SMTPSECURE=${MAIL_SMTPSECURE}

# LDAP Configuration (Optional)
LDAP_ENABLED=${LDAP_ENABLED}
LDAP_SERVER=${LDAP_SERVER}
LDAP_PORT=${LDAP_PORT}
LDAP_BASEDN=${LDAP_BASEDN}
LDAP_USERDN=${LDAP_USERDN}
LDAP_FILTER=${LDAP_FILTER}
LDAP_BIND=${LDAP_BIND}
LDAP_BIND_USER=${LDAP_BIND_USER}
LDAP_BIND_PASSWORD=${LDAP_BIND_PASSWORD}
LDAP_TRUST=${LDAP_TRUST}

# Automation Configuration
AUTOMATION_SECRET=${AUTOMATION_SECRET}
EOF

    run_root_cmd "Bootstrap .env schreiben" install -m 0640 "$tmp_env" "$env_file"
    rm -f "$tmp_env"
}

ensure_runtime_directories() {
    run_root_cmd "Log-Verzeichnis anlegen" mkdir -p /var/log/portflow
    run_root_cmd "Log-Verzeichnis Eigentümer setzen" chown www-data:www-data /var/log/portflow
    run_root_cmd "Log-Verzeichnis Rechte setzen" chmod 0750 /var/log/portflow
    run_root_cmd "Log-Datei vorbereiten" touch /var/log/portflow/portflow.log
    run_root_cmd "Log-Datei Eigentümer setzen" chown www-data:www-data /var/log/portflow/portflow.log
    run_root_cmd "Log-Datei Rechte setzen" chmod 0640 /var/log/portflow/portflow.log
}

next_step() {
    local title="$1"
    CURRENT_STEP=$((CURRENT_STEP + 1))
    printf '\n%b[%d/%d]%b %s\n' "$COLOR_BLUE" "$CURRENT_STEP" "$TOTAL_STEPS" "$COLOR_RESET" "$title"
}

prompt() {
    local var_name="$1"
    local prompt_text="$2"
    local default_value="${3-}"
    local value=''

    if [[ -n "$default_value" ]]; then
        read -r -p "$prompt_text [$default_value]: " value
        printf -v "$var_name" '%s' "${value:-$default_value}"
    else
        read -r -p "$prompt_text: " value
        printf -v "$var_name" '%s' "$value"
    fi
}

prompt_if_interactive() {
    local var_name="$1"
    local prompt_text="$2"
    local default_value="${3-}"

    if [[ "$INTERACTIVE" == 'true' ]]; then
        prompt "$var_name" "$prompt_text" "$default_value"
    else
        printf -v "$var_name" '%s' "$default_value"
    fi
}

prompt_secret_if_interactive() {
    local var_name="$1"
    local prompt_text="$2"
    local current_value="${3-}"

    if [[ "$INTERACTIVE" == 'true' ]]; then
        if [[ -n "$current_value" ]]; then
            printf -v "$var_name" '%s' "$current_value"
        else
            prompt_secret "$var_name" "$prompt_text"
        fi
    else
        printf -v "$var_name" '%s' "$current_value"
    fi
}

prompt_secret() {
    local var_name="$1"
    local prompt_text="$2"
    local value=''

    read -r -s -p "$prompt_text: " value
    echo
    printf -v "$var_name" '%s' "$value"
}

confirm() {
    local prompt_text="$1"
    local default_answer="${2:-y}"
    local answer=''
    local suffix='[Y/n]'

    if [[ "$default_answer" == 'n' ]]; then
        suffix='[y/N]'
    fi

    read -r -p "$prompt_text $suffix: " answer
    answer="${answer:-$default_answer}"
    [[ "$answer" =~ ^[Yy]$ ]]
}

require_root_or_sudo() {
    if [[ "$EUID" -eq 0 ]]; then
        SUDO=''
        return
    fi

    if ! command -v sudo >/dev/null 2>&1; then
        fail "Please run this installer as root or install sudo first."
    fi

    if ! sudo -v; then
        fail "Sudo authentication failed."
    fi

    SUDO='sudo'
}

check_environment() {
    [[ -f /etc/os-release ]] || fail "Unsupported system: /etc/os-release not found."
    # shellcheck disable=SC1091
    . /etc/os-release

    case "${ID:-}" in
        debian|ubuntu)
            ;;
        *)
            fail "This installer currently supports Debian and Ubuntu only."
            ;;
    esac

    command -v apt-get >/dev/null 2>&1 || fail "apt-get is required."
    command -v systemctl >/dev/null 2>&1 || fail "systemd is required."
}

validate_inputs() {
    [[ "$TARGET_DIR" = /* ]] || fail "Das Installationsverzeichnis muss absolut sein."
    case "$TARGET_DIR" in
        /|/var|/var/www)
            fail "Unsicheres Installationsverzeichnis: $TARGET_DIR"
            ;;
    esac

    [[ "$PORTFLOW_LOG_LEVEL" =~ ^[0-4]$ ]] || fail "LOG_LEVEL muss zwischen 0 und 4 liegen."
    [[ -n "$PUBLIC_BASE_URL" ]] || fail "Portflow Basis-URL darf nicht leer sein."
    [[ "$PUBLIC_BASE_URL" =~ ^https?:// ]] || fail "Portflow Basis-URL muss mit http:// oder https:// beginnen."

    if [[ "$CONFIGURE_DB" == 'y' ]]; then
        [[ "$PG_USERNAME" =~ ^[a-zA-Z_][a-zA-Z0-9_-]*$ ]] || fail "Ungueltiger PostgreSQL Benutzername."
        [[ "$PG_DBNAME" =~ ^[a-zA-Z_][a-zA-Z0-9_-]*$ ]] || fail "Ungueltiger PostgreSQL Datenbankname."
        [[ -n "$PG_PASSWORD" ]] || fail "PostgreSQL Passwort fehlt. Nutze --db-password oder den interaktiven Modus."
    fi

    [[ "$MAIL_PORT" =~ ^[0-9]+$ ]] || fail "MAIL_PORT muss numerisch sein."
    [[ "$LDAP_PORT" =~ ^[0-9]+$ ]] || fail "LDAP_PORT muss numerisch sein."
    case "$MAIL_SMTPSECURE" in
        ''|tls|ssl)
            ;;
        *)
            fail "MAIL_SMTPSECURE erlaubt nur '', tls oder ssl."
            ;;
    esac
}

collect_inputs() {
    if [[ "$INTERACTIVE" == 'false' && ! -t 0 ]]; then
        info "Installer laeuft ohne interaktive Rueckfragen"
    fi

    prompt_if_interactive TARGET_DIR "Installationsverzeichnis" "$TARGET_DIR"
    prompt_if_interactive GIT_REF "Git-Branch oder Tag" "$GIT_REF"

    detect_public_urls
    prompt_if_interactive PUBLIC_BASE_URL "Portflow Basis-URL" "$PUBLIC_BASE_URL"
    PUBLIC_BASE_URL="${PUBLIC_BASE_URL%/}"
    if [[ "$PUBLIC_BASE_URL" == https://* ]]; then
        PORTFLOW_SSL='TRUE'
    else
        PORTFLOW_SSL='FALSE'
    fi

    if [[ -z "$AUTOMATION_SECRET" ]]; then
        generate_automation_secret
    fi

    if [[ "$INTERACTIVE" == 'true' ]]; then
        if confirm "PostgreSQL direkt mit anlegen?" 'y'; then
            CONFIGURE_DB='y'

            while true; do
                prompt PG_USERNAME "PostgreSQL Benutzer" "$PG_USERNAME"
                [[ "$PG_USERNAME" =~ ^[a-zA-Z_][a-zA-Z0-9_-]*$ ]] && break
                warn "Nur Buchstaben, Zahlen, Unterstrich und Bindestrich sind erlaubt."
            done

            while true; do
                prompt PG_DBNAME "PostgreSQL Datenbankname" "$PG_DBNAME"
                [[ "$PG_DBNAME" =~ ^[a-zA-Z_][a-zA-Z0-9_-]*$ ]] && break
                warn "Nur Buchstaben, Zahlen, Unterstrich und Bindestrich sind erlaubt."
            done

            while [[ -z "$PG_PASSWORD" ]]; do
                prompt_secret_if_interactive PG_PASSWORD "PostgreSQL Passwort" "$PG_PASSWORD"
                [[ -n "$PG_PASSWORD" ]] || warn "Das Passwort darf nicht leer sein."
            done
        else
            CONFIGURE_DB='n'
        fi
    fi

    if [[ "$CONFIGURE_DB" == 'y' ]]; then
        CONFIGURE_DB='y'
    else
        CONFIGURE_DB='n'
        PG_USERNAME=''
        PG_DBNAME=''
        PG_PASSWORD=''
    fi

    validate_inputs
}

install_packages() {
    run_root_cmd "APT Paketlisten aktualisieren" env DEBIAN_FRONTEND=noninteractive apt-get update
    run_root_cmd "Benötigte Pakete installieren" env DEBIAN_FRONTEND=noninteractive apt-get install -y git curl ca-certificates cron lighttpd php-fpm php-cli php-common php-mbstring php-ldap php-pgsql php-opcache php-snmp postgresql postgresql-contrib
}

configure_scheduler_cron() {
    local php_bin
    local tmp_cron

    php_bin=$(command -v php || true)
    [[ -n "$php_bin" ]] || fail "PHP CLI wurde fuer den Scheduler-Cronjob nicht gefunden."

    run_root_cmd "Cron-Dienst aktivieren" systemctl enable --now cron

    tmp_cron=$(mktemp)
    cat >"$tmp_cron" <<EOF
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

*/5 * * * * www-data $php_bin $TARGET_DIR/scheduler.php >/dev/null 2>&1
EOF

    run_root_cmd "Scheduler Cronjob schreiben" install -m 0644 "$tmp_cron" /etc/cron.d/portflow
    rm -f "$tmp_cron"
}

detect_php_fpm() {
    local service

    service=$(systemctl list-unit-files 'php*-fpm.service' --no-legend 2>>"$LOG_FILE" | awk 'NR==1 {print $1}')
    [[ -n "$service" ]] || fail "Kein PHP-FPM Dienst gefunden."

    PHP_FPM_SERVICE="$service"
    run_root_cmd "PHP-FPM Dienst starten" systemctl enable --now "$PHP_FPM_SERVICE"

    PHP_FPM_SOCKET=$(find /run/php -maxdepth 1 -type s -name 'php*.sock' 2>>"$LOG_FILE" | head -n 1 || true)
    [[ -n "$PHP_FPM_SOCKET" ]] || fail "Kein PHP-FPM Socket unter /run/php gefunden."
}

configure_lighttpd() {
    run_root_cmd "Lighttpd aktivieren" systemctl enable --now lighttpd
    run_lighty_enable_mod fastcgi

    if [[ -f /etc/lighttpd/conf-enabled/15-fastcgi-php.conf ]]; then
        run_root_cmd "Alte Lighttpd PHP-Konfiguration entfernen" rm -f /etc/lighttpd/conf-enabled/15-fastcgi-php.conf
    fi

    local tmp_config
    tmp_config=$(mktemp)
    cat >"$tmp_config" <<EOF
server.modules += ( "mod_fastcgi" )

$HTTP["url"] =~ "^/data(?:/|$)" {
    url.access-deny = ( "" )
}

$HTTP["url"] =~ "^/(?:\.git|\.env(?:\..*)?|\.htaccess|\.gitignore|\.gitmodules)(?:$|/)" {
    url.access-deny = ( "" )
}

$HTTP["url"] =~ "^/(?:composer\.(?:json|lock)|Dockerfile|podman-compose\.yml)$" {
    url.access-deny = ( "" )
}

fastcgi.server = ( ".php" =>
    ( "localhost" =>
        (
            "socket" => "$PHP_FPM_SOCKET",
            "broken-scriptfilename" => "enable"
        )
    )
)
EOF

    run_root_cmd "Lighttpd PHP-FPM Konfiguration schreiben" install -m 0644 "$tmp_config" /etc/lighttpd/conf-available/15-fastcgi-php-fpm.conf
    rm -f "$tmp_config"

    run_lighty_enable_mod fastcgi-php-fpm
    run_root_cmd "Lighttpd Konfiguration validieren" lighttpd -tt -f /etc/lighttpd/lighttpd.conf
    run_root_cmd "Lighttpd neu starten" systemctl restart lighttpd

    systemctl is-active --quiet lighttpd || fail "Lighttpd konnte nicht gestartet werden."
}

install_repository() {
    local remote_url=''
    local reset_target=''

    run_root_cmd "Installationsverzeichnis anlegen" mkdir -p "$TARGET_DIR"

    if [[ -d "$TARGET_DIR/.git" ]]; then
        remote_url=$(git -C "$TARGET_DIR" remote get-url origin 2>>"$LOG_FILE" || true)
        if [[ "$remote_url" == "$REPO_URL" ]]; then
            run_root_cmd "Vorhandenes Portflow Repository aktualisieren" git -C "$TARGET_DIR" fetch --tags origin
            run_root_cmd "Gewünschten Stand auschecken" git -C "$TARGET_DIR" checkout "$GIT_REF"
            if run_privileged git -C "$TARGET_DIR" show-ref --verify --quiet "refs/remotes/origin/$GIT_REF" >>"$LOG_FILE" 2>&1; then
                reset_target="origin/$GIT_REF"
            else
                reset_target="$GIT_REF"
            fi
            run_root_cmd "Repository auf gewünschten Stand setzen" git -C "$TARGET_DIR" reset --hard "$reset_target"
        else
            fail "Im Zielverzeichnis liegt bereits ein anderes Git-Repository: $remote_url"
        fi
        return
    fi

    if find "$TARGET_DIR" -mindepth 1 -maxdepth 1 | read -r _; then
        if confirm "Das Zielverzeichnis ist nicht leer. Inhalt löschen und Portflow per Git klonen?" 'n'; then
            run_root_cmd "Vorhandene Dateien entfernen" find "$TARGET_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
        else
            fail "Installation abgebrochen, weil das Zielverzeichnis nicht leer ist."
        fi
    fi

    run_root_cmd "Portflow Repository klonen" git clone --branch "$GIT_REF" --depth 1 "$REPO_URL" "$TARGET_DIR"
}

configure_postgresql() {
    local escaped_password
    local db_exists

    run_root_cmd "PostgreSQL aktivieren" systemctl enable --now postgresql

    if [[ "$CONFIGURE_DB" != 'y' ]]; then
        warn "PostgreSQL Installation wurde vorbereitet, Datenbank und Benutzer wurden aber nicht angelegt."
        return
    fi

    escaped_password=${PG_PASSWORD//\'/\'\'}

    info "PostgreSQL Benutzer wird angelegt oder aktualisiert"
    if run_psql_as_postgres -v ON_ERROR_STOP=1 postgres >>"$LOG_FILE" 2>&1 <<EOF
DO \
\$\$ \
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '$PG_USERNAME') THEN
        EXECUTE 'CREATE ROLE "$PG_USERNAME" LOGIN PASSWORD ''$escaped_password''';
    ELSE
        EXECUTE 'ALTER ROLE "$PG_USERNAME" WITH LOGIN PASSWORD ''$escaped_password''';
    END IF;
END
\$\$;
EOF
    then
        success "PostgreSQL Benutzer bereitgestellt"
    else
        fail "PostgreSQL Benutzer konnte nicht konfiguriert werden."
    fi

    info "PostgreSQL Datenbank wird angelegt oder aktualisiert"
    db_exists=$(run_psql_as_postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$PG_DBNAME'" postgres 2>>"$LOG_FILE" | tr -d '[:space:]' || true)

    if [[ "$db_exists" == '1' ]]; then
        if run_psql_as_postgres -v ON_ERROR_STOP=1 postgres -c "ALTER DATABASE \"$PG_DBNAME\" OWNER TO \"$PG_USERNAME\"" >>"$LOG_FILE" 2>&1; then
            success "PostgreSQL Datenbankbesitzer aktualisiert"
        else
            fail "PostgreSQL Datenbank konnte nicht aktualisiert werden."
        fi
    elif run_psql_as_postgres -v ON_ERROR_STOP=1 postgres -c "CREATE DATABASE \"$PG_DBNAME\" OWNER \"$PG_USERNAME\"" >>"$LOG_FILE" 2>&1; then
        success "PostgreSQL Datenbank bereitgestellt"
    else
        fail "PostgreSQL Datenbank konnte nicht angelegt werden."
    fi
}

set_permissions() {
    run_root_cmd "Besitzer setzen" chown -R www-data:www-data "$TARGET_DIR"

    run_root_cmd "Verzeichnisrechte setzen" find "$TARGET_DIR" -type d -exec chmod 0755 {} +
    run_root_cmd "Dateirechte setzen" find "$TARGET_DIR" -type f -exec chmod 0644 {} +

    if [[ -d "$TARGET_DIR/data" ]]; then
        run_root_cmd "data Verzeichnis-Rechte haerten" find "$TARGET_DIR/data" -type d -exec chmod 0750 {} +
        run_root_cmd "data Dateirechte haerten" find "$TARGET_DIR/data" -type f -exec chmod 0640 {} +
    fi

    if [[ -f "$TARGET_DIR/.env" ]]; then
        run_root_cmd ".env Rechte haerten" chmod 0640 "$TARGET_DIR/.env"
    fi
}

print_summary() {
    local php_version
    local setup_url=''

    php_version=$(php -r 'echo PHP_VERSION;' 2>>"$LOG_FILE" || echo 'unbekannt')

    printf '\n%bInstallation abgeschlossen.%b\n' "$COLOR_GREEN" "$COLOR_RESET"
    printf 'Repository: %s\n' "$REPO_URL"
    printf 'Git-Ref: %s\n' "$GIT_REF"
    printf 'Zielverzeichnis: %s\n' "$TARGET_DIR"
    printf 'PHP-FPM Dienst: %s\n' "$PHP_FPM_SERVICE"
    printf 'PHP Version: %s\n' "$php_version"
    printf 'Logdatei: %s\n' "$LOG_FILE"
    printf 'Scheduler-Cron: %s\n' '/etc/cron.d/portflow (alle 5 Minuten)'
    if [[ "$CONFIGURE_DB" == 'y' ]]; then
        printf 'PostgreSQL DB: %s\n' "$PG_DBNAME"
        printf 'PostgreSQL User: %s\n' "$PG_USERNAME"
    fi

    printf '\nAufrufbare Setup-URLs:\n'
    for setup_url in "${SETUP_URLS[@]}"; do
        printf ' - %s/setup.php\n' "$setup_url"
    done

    printf '\nVorkonfiguriert in .env:\n'
    printf ' - PORTFLOW_HOSTNAME=%s\n' "$PUBLIC_BASE_URL"
    printf ' - DB_NAME=%s\n' "$PG_DBNAME"
    printf ' - DB_USER=%s\n' "$PG_USERNAME"
    printf ' - AUTOMATION_SECRET=<gesetzt>\n'
    printf '\nNächster Schritt: Rufe setup.php im Browser auf. Datenbank-, Server- und Automation-Werte werden dort bereits übersprungen, wenn die .env unverändert ist.\n'
}

main() {
    : >"$LOG_FILE"

    if [[ ! -t 0 ]]; then
        INTERACTIVE='false'
    fi

    parse_args "$@"

    next_step "Umgebung prüfen"
    require_root_or_sudo
    check_environment

    next_step "Installationsparameter erfassen"
    collect_inputs

    next_step "Systempakete installieren"
    install_packages

    next_step "PHP-FPM erkennen"
    detect_php_fpm

    next_step "Lighttpd konfigurieren"
    configure_lighttpd

    next_step "Portflow per Git bereitstellen"
    install_repository

    next_step "Bootstrap-Konfiguration schreiben"
    write_bootstrap_env

    next_step "Laufzeitverzeichnisse vorbereiten"
    ensure_runtime_directories

    next_step "PostgreSQL konfigurieren"
    configure_postgresql

    next_step "Rechte setzen"
    set_permissions

    next_step "Scheduler einrichten"
    configure_scheduler_cron

    print_summary
}

main "$@"
