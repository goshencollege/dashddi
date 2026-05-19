#!/usr/bin/env bash
# DashDDI production setup
# Generates secrets, SSL certificate, and docker-compose.prod.yml, then starts the stack.

set -euo pipefail

# Parse flags
APP_ENV="prod"
for arg in "$@"; do
    case "$arg" in
        --dev) APP_ENV="dev" ;;
        *) echo "Unknown option: $arg" >&2; exit 1 ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_FILE="$SCRIPT_DIR/docker-compose.${APP_ENV}.yml"
SSL_DIR="$SCRIPT_DIR/docker/ssl"

# ── Terminal colours (disabled when not a TTY) ────────────────────────────────
if [[ -t 1 ]]; then
    RED='\033[0;31m' GREEN='\033[0;32m' YELLOW='\033[1;33m'
    CYAN='\033[0;36m' BOLD='\033[1m' NC='\033[0m'
else
    RED='' GREEN='' YELLOW='' CYAN='' BOLD='' NC=''
fi

header() { echo; echo -e "${BOLD}${CYAN}── $* ──${NC}"; }
ok()     { echo -e "  ${GREEN}✓${NC}  $*"; }
warn()   { echo -e "  ${YELLOW}!${NC}  $*"; }
die()    { echo -e "  ${RED}✗  $*${NC}" >&2; exit 1; }

ask() {
    # ask VAR_NAME "Prompt text" ["default value"]
    local _var="$1" _prompt="$2" _default="${3:-}" _val
    if [[ -n "$_default" ]]; then
        read -rp "$(echo -e "  ${BOLD}${_prompt}${NC} [${_default}]: ")" _val
        printf -v "$_var" '%s' "${_val:-$_default}"
    else
        read -rp "$(echo -e "  ${BOLD}${_prompt}${NC}: ")" _val
        [[ -z "$_val" ]] && die "$_prompt is required."
        printf -v "$_var" '%s' "$_val"
    fi
}

ask_secret() {
    local _var="$1" _prompt="$2" _val
    read -rsp "$(echo -e "  ${BOLD}${_prompt}${NC}: ")" _val; echo
    [[ -z "$_val" ]] && die "$_prompt is required."
    printf -v "$_var" '%s' "$_val"
}

ask_yn() {
    # ask_yn "Prompt" "y"|"n"  →  returns 0 (yes) or 1 (no)
    local _prompt="$1" _default="${2:-y}" _val
    read -rp "$(echo -e "  ${BOLD}${_prompt}${NC} [${_default}]: ")" _val
    _val="${_val:-$_default}"
    [[ "${_val,,}" == y* ]]
}

cd "$SCRIPT_DIR"

echo
if [[ "$APP_ENV" == "dev" ]]; then
    echo -e "${BOLD}DashDDI Development Setup${NC}"
    echo "  This wizard creates docker-compose.dev.yml and starts the stack with APP_ENV=dev."
else
    echo -e "${BOLD}DashDDI Production Setup${NC}"
    echo "  This wizard creates docker-compose.prod.yml and starts the stack."
fi
echo "  Have your IdP SAML metadata (XML file or URL) ready before you begin."
echo

# ── 1. Prerequisites ──────────────────────────────────────────────────────────
header "Checking prerequisites"

for cmd in docker openssl; do
    command -v "$cmd" >/dev/null 2>&1 || die "$cmd is required but not found in PATH."
    ok "$cmd"
done
docker compose version >/dev/null 2>&1 || die "docker compose (plugin v2) is required."
ok "docker compose"

if [[ -f "$COMPOSE_FILE" ]]; then
    warn "docker-compose.${APP_ENV}.yml already exists."
    ask_yn "Overwrite and re-run full setup?" "n" \
        || { echo "  Aborted."; exit 0; }
fi

# ── 2. Secrets ────────────────────────────────────────────────────────────────
header "Generating secrets"

APP_SECRET=$(openssl rand -hex 32)
ok "APP_SECRET generated"

# 32 random bytes, base64-encoded — equivalent to sodium_crypto_secretbox_keygen()
APP_ENCRYPTION_KEY=$(openssl rand -base64 32)
ok "APP_ENCRYPTION_KEY generated"

# ── 3. Base URL ───────────────────────────────────────────────────────────────
header "Base URL"

ask FQDN "Fully-qualified domain name (e.g. dashddi.example.com)"
BASE_URL="https://${FQDN}"
ok "Base URL: $BASE_URL"

# ── 4. SSL certificate ────────────────────────────────────────────────────────
header "SSL Certificate"
echo "  1) Generate self-signed certificate (fine for internal/testing use)"
echo "  2) Use Let's Encrypt  (requires certbot, public DNS, and port 80 reachable)"
echo "  3) I will provide my own certificate"
echo
ask SSL_CHOICE "Choice" "1"

mkdir -p "$SSL_DIR"

case "$SSL_CHOICE" in
1)
    echo
    echo "  Generating self-signed RSA-4096 certificate (valid 10 years)…"
    openssl req -x509 -newkey rsa:4096 -nodes \
        -keyout "$SSL_DIR/key.pem" \
        -out    "$SSL_DIR/cert.pem" \
        -days 3650 \
        -subj   "/CN=${FQDN}" \
        -addext "subjectAltName=DNS:${FQDN}" \
        2>/dev/null
    ok "Certificate written to docker/ssl/"
    warn "Browsers will show a security warning for self-signed certs."
    warn "For a trusted cert, install certbot and run 'certbot certonly --standalone'"
    warn "then replace docker/ssl/cert.pem and key.pem with the issued files."
    ;;
2)
    command -v certbot >/dev/null 2>&1 || die "certbot is not installed (apt install certbot)."
    echo
    warn "Port 80 must be reachable from the internet for the HTTP-01 challenge."
    warn "Stop any service listening on port 80 before continuing."
    echo
    certbot certonly --standalone -d "$FQDN" \
        --non-interactive --agree-tos \
        --register-unsafely-without-email \
        || die "certbot failed — check the output above."
    ln -sf "/etc/letsencrypt/live/${FQDN}/fullchain.pem" "$SSL_DIR/cert.pem"
    ln -sf "/etc/letsencrypt/live/${FQDN}/privkey.pem"   "$SSL_DIR/key.pem"
    ok "Let's Encrypt certificate obtained and linked into docker/ssl/"
    warn "Set up auto-renewal: 'certbot renew --pre-hook \"docker compose -f docker-compose.${APP_ENV}.yml stop nginx\"'"
    warn "                               --post-hook \"docker compose -f docker-compose.${APP_ENV}.yml start nginx\"'"
    ;;
3)
    if [[ -f "$SSL_DIR/cert.pem" && -f "$SSL_DIR/key.pem" ]]; then
        ok "Found existing docker/ssl/cert.pem and key.pem"
    else
        warn "No certificate found at docker/ssl/ — copy cert.pem and key.pem there before starting."
    fi
    ;;
*)
    die "Invalid choice."
    ;;
esac

# ── 5. Database ───────────────────────────────────────────────────────────────
header "Database (MySQL 8)"
echo "  1) Run MySQL in a container  (recommended for standalone deployments)"
echo "  2) Use an external MySQL server"
echo
ask DB_CHOICE "Choice" "1"

case "$DB_CHOICE" in
1)
    USE_CONTAINER_DB=true
    DB_HOST="db"
    DB_PORT="3306"
    DB_NAME="ipam"
    DB_USER="ipam"
    DB_PASSWORD=$(openssl rand -hex 24)
    DB_ROOT_PASSWORD=$(openssl rand -hex 24)
    ok "MySQL will run in a container with auto-generated credentials"
    ;;
2)
    USE_CONTAINER_DB=false
    ask      DB_HOST          "MySQL hostname or IP"
    ask      DB_PORT          "MySQL port" "3306"
    ask      DB_NAME          "Database name" "ipam"
    ask      DB_USER          "MySQL username"
    ask_secret DB_PASSWORD    "MySQL password"
    ok "External MySQL: ${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME}"
    warn "Ensure the database and user already exist with the correct privileges."
    ;;
*)
    die "Invalid choice."
    ;;
esac

# Detect MySQL server version for the DSN
DB_SERVER_VERSION="8.0"
if [[ "$USE_CONTAINER_DB" == "false" ]]; then
    ask DB_SERVER_VERSION "MySQL server version (used in DSN)" "8.0"
fi
DATABASE_URL="mysql://${DB_USER}:${DB_PASSWORD}@${DB_HOST}:${DB_PORT}/${DB_NAME}?serverVersion=${DB_SERVER_VERSION}&charset=utf8mb4"

# ── 6. Write docker-compose file ─────────────────────────────────────────────
header "Writing docker-compose.${APP_ENV}.yml"

# Prod: read-only containers and volume mounts. Dev: writable for easier development.
if [[ "$APP_ENV" == "prod" ]]; then
    APP_READONLY="    read_only: true"
    VOL_RO=":ro"
else
    APP_READONLY=""
    VOL_RO=""
fi

# Build the db service block and app depends_on conditionally
if [[ "$USE_CONTAINER_DB" == "true" ]]; then
    read -r -d '' DB_SERVICE_BLOCK << YAML || true

  db:
    image: mysql:8.0
    read_only: true
    environment:
      MYSQL_DATABASE: ${DB_NAME}
      MYSQL_USER: ${DB_USER}
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - mysql_data:/var/lib/mysql
    tmpfs:
      - /var/run/mysqld
      - /tmp
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "${DB_USER}", "-p${DB_PASSWORD}"]
      interval: 5s
      timeout: 5s
      retries: 12

YAML

    read -r -d '' DEPENDS_ON_BLOCK << YAML || true
    depends_on:
      db:
        condition: service_healthy
YAML

    read -r -d '' VOLUMES_BLOCK << YAML || true
volumes:
  mysql_data:
  symfony_var:
YAML

else
    DB_SERVICE_BLOCK=""
    DEPENDS_ON_BLOCK=""
    read -r -d '' VOLUMES_BLOCK << YAML || true
volumes:
  symfony_var:
YAML
fi

cat > "$COMPOSE_FILE" << EOF
services:
  app:
    build: .
${APP_READONLY}
    restart: unless-stopped
    volumes:
      - .:/var/www/html${VOL_RO}
      - symfony_var:/var/www/html/var
    tmpfs:
      - /tmp
      - /usr/local/var/run
    environment:
      APP_ENV: ${APP_ENV}
      APP_SECRET: "${APP_SECRET}"
      APP_ENCRYPTION_KEY: "${APP_ENCRYPTION_KEY}"
      DATABASE_URL: "${DATABASE_URL}"
      DEFAULT_URI: "${BASE_URL}"
      MESSENGER_TRANSPORT_DSN: "doctrine://default?auto_setup=0"
    healthcheck:
      test: ["CMD-SHELL", "nc -z 127.0.0.1 9000"]
      interval: 5s
      timeout: 3s
      retries: 10
      start_period: 10s
${DEPENDS_ON_BLOCK}

  worker_priority:
    build: .
${APP_READONLY}
    restart: unless-stopped
    command: ["php", "bin/console", "messenger:consume", "async_priority", "failed_priority", "--time-limit=3600"]
    volumes:
      - .:/var/www/html${VOL_RO}
      - symfony_var:/var/www/html/var
    tmpfs:
      - /tmp
      - /usr/local/var/run
    environment:
      APP_ENV: ${APP_ENV}
      APP_SECRET: "${APP_SECRET}"
      APP_ENCRYPTION_KEY: "${APP_ENCRYPTION_KEY}"
      DATABASE_URL: "${DATABASE_URL}"
      DEFAULT_URI: "${BASE_URL}"
      MESSENGER_TRANSPORT_DSN: "doctrine://default?auto_setup=0"
${DEPENDS_ON_BLOCK}

  worker_bulk:
    build: .
${APP_READONLY}
    restart: unless-stopped
    command: ["php", "bin/console", "messenger:consume", "async_bulk", "failed_bulk", "--time-limit=3600"]
    volumes:
      - .:/var/www/html${VOL_RO}
      - symfony_var:/var/www/html/var
    tmpfs:
      - /tmp
      - /usr/local/var/run
    environment:
      APP_ENV: ${APP_ENV}
      APP_SECRET: "${APP_SECRET}"
      APP_ENCRYPTION_KEY: "${APP_ENCRYPTION_KEY}"
      DATABASE_URL: "${DATABASE_URL}"
      DEFAULT_URI: "${BASE_URL}"
      MESSENGER_TRANSPORT_DSN: "doctrine://default?auto_setup=0"
${DEPENDS_ON_BLOCK}

  nginx:
    image: nginx:alpine
    read_only: true
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - .:/var/www/html:ro
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
      - ./docker/ssl:/etc/nginx/ssl:ro
    tmpfs:
      - /var/cache/nginx
      - /var/run
      - /tmp
    depends_on:
      app:
        condition: service_healthy
${DB_SERVICE_BLOCK}
${VOLUMES_BLOCK}
EOF

ok "docker-compose.${APP_ENV}.yml written"

# ── 7. Start containers ──────────────────────────────────────────────────────
header "Building and starting services"

docker compose -f "$COMPOSE_FILE" up -d --build
ok "Containers started"

# Wait for the app container to be healthy enough to run console commands
echo "  Waiting for the application container to be ready…"
for i in $(seq 1 30); do
    if docker compose -f "$COMPOSE_FILE" exec -T app php -r 'echo "ok";' 2>/dev/null | grep -q "ok"; then
        break
    fi
    sleep 2
    [[ $i -eq 30 ]] && die "App container did not become ready in time. Check: docker compose -f docker-compose.prod.yml logs app"
done
ok "App container ready"

# ── 8. Database migrations ────────────────────────────────────────────────────
header "Running database migrations"

docker compose -f "$COMPOSE_FILE" exec -T app \
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
ok "Migrations complete"

# ── 9. Cache warmup ──────────────────────────────────────────────────────────
header "Warming up cache"

docker compose -f "$COMPOSE_FILE" exec -T app \
    php bin/console cache:warmup
ok "Cache warm"

# ── 10. SAML identity provider setup ─────────────────────────────────────────
header "SAML Identity Provider"
echo
echo -e "  ${BOLD}SP metadata URL (give this to your IdP administrator):${NC}"
echo -e "  ${CYAN}${BASE_URL}/saml/metadata${NC}"
echo
echo "  Your IdP administrator needs to register that URL as a Service Provider"
echo "  in their system before users can log in."
echo

read -rp "$(echo -e "  ${BOLD}Path to IdP metadata XML file, or URL (leave blank to configure later): ${NC}")" SAML_SOURCE

if [[ -n "$SAML_SOURCE" ]]; then
    ask SAML_NAME "Provider name (e.g. 'Okta', 'Azure AD', 'Entra ID')" "IdP"

    # If a local file path, copy it to project dir so the container can read it
    if [[ "$SAML_SOURCE" =~ ^https?:// ]]; then
        SAML_ARG="$SAML_SOURCE"
        SAML_TMP=""
    else
        [[ -f "$SAML_SOURCE" ]] || die "File not found: $SAML_SOURCE"
        SAML_TMP="$SCRIPT_DIR/.saml-setup-metadata.xml"
        cp "$SAML_SOURCE" "$SAML_TMP"
        SAML_ARG="/var/www/html/.saml-setup-metadata.xml"
    fi

    docker compose -f "$COMPOSE_FILE" exec -T app \
        php bin/console app:saml:import-metadata "$SAML_ARG" \
        --name="$SAML_NAME" --activate

    [[ -n "${SAML_TMP:-}" ]] && rm -f "$SAML_TMP"
    ok "SAML provider '$SAML_NAME' imported and set as active"
else
    warn "Skipped — no one will be able to log in until a SAML provider is configured."
    warn "Run this when ready:"
    warn "  docker compose -f docker-compose.prod.yml exec app php bin/console app:saml:import-metadata <file-or-url> --activate"
fi

# ── 11. Summary ──────────────────────────────────────────────────────────────
header "Setup complete"
echo
echo -e "  ${BOLD}DashDDI is running at:  ${CYAN}${BASE_URL}${NC}"
echo

if [[ "$USE_CONTAINER_DB" == "true" ]]; then
    echo -e "  ${BOLD}MySQL credentials (save these — they are not stored elsewhere):${NC}"
    echo "    App user:   $DB_USER / $DB_PASSWORD"
    echo "    Root:       root / $DB_ROOT_PASSWORD"
    echo
fi

if [[ "$SSL_CHOICE" == "1" ]]; then
    warn "Self-signed cert in use — browsers will show a warning."
    warn "Replace docker/ssl/cert.pem and key.pem with a trusted cert when ready."
    echo
fi

echo "  Common commands:"
echo "    Start:    docker compose -f docker-compose.${APP_ENV}.yml up -d"
echo "    Stop:     docker compose -f docker-compose.${APP_ENV}.yml down"
echo "    Logs:     docker compose -f docker-compose.${APP_ENV}.yml logs -f"
echo "    Migrate:  docker compose -f docker-compose.${APP_ENV}.yml exec app php bin/console doctrine:migrations:migrate"
echo
echo -e "  ${BOLD}SP metadata URL for your IdP:${NC}  ${CYAN}${BASE_URL}/saml/metadata${NC}"
echo
