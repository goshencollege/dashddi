#!/usr/bin/env bash
# DashDDI dev/test setup
# Generates secrets, a self-signed SSL certificate, and docker-compose.dev.yml, then starts the stack.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_FILE="$SCRIPT_DIR/docker-compose.dev.yml"
DIST_FILE="$SCRIPT_DIR/docker-compose.dev.yml.dist"
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

ask_yn() {
    local _prompt="$1" _default="${2:-y}" _val
    read -rp "$(echo -e "  ${BOLD}${_prompt}${NC} [${_default}]: ")" _val
    _val="${_val:-$_default}"
    [[ "${_val,,}" == y* ]]
}

cd "$SCRIPT_DIR"

echo
echo -e "${BOLD}DashDDI Dev/Test Setup${NC}"
echo "  Creates docker-compose.dev.yml and starts the stack with APP_ENV=dev."
echo

# ── 1. Prerequisites ──────────────────────────────────────────────────────────
header "Checking prerequisites"

for cmd in docker openssl sed; do
    command -v "$cmd" >/dev/null 2>&1 || die "$cmd is required but not found in PATH."
    ok "$cmd"
done
docker compose version >/dev/null 2>&1 || die "docker compose (plugin v2) is required."
ok "docker compose"
[[ -f "$DIST_FILE" ]] || die "docker-compose.dev.yml.dist not found."
ok "docker-compose.dev.yml.dist"

if [[ -f "$COMPOSE_FILE" ]]; then
    warn "docker-compose.dev.yml already exists."
    ask_yn "Overwrite and re-run full setup?" "n" \
        || { echo "  Aborted."; exit 0; }
    # Wipe the old stack and volumes so regenerated credentials take effect
    docker compose -f "$COMPOSE_FILE" down -v 2>/dev/null || true
fi

# ── 2. Hostname ───────────────────────────────────────────────────────────────
header "Hostname"

ask FQDN "Hostname or IP (e.g. localhost, 192.168.1.50, mydev.local)" "localhost"
BASE_URL="https://${FQDN}:8443"
ok "Base URL: $BASE_URL"

# ── 3. Secrets ────────────────────────────────────────────────────────────────
header "Generating secrets"

APP_SECRET=$(openssl rand -hex 32)
ok "APP_SECRET generated"

APP_ENCRYPTION_KEY=$(openssl rand -base64 32)
ok "APP_ENCRYPTION_KEY generated"

DB_PASSWORD=$(openssl rand -hex 16)
DB_ROOT_PASSWORD=$(openssl rand -hex 16)
ok "Database passwords generated"

# ── 4. SSL certificate ────────────────────────────────────────────────────────
header "SSL Certificate"

mkdir -p "$SSL_DIR"

REGEN_CERT=true
if [[ -f "$SSL_DIR/cert.pem" && -f "$SSL_DIR/key.pem" ]]; then
    warn "Existing certificate found in docker/ssl/."
    if ask_yn "Regenerate?" "n"; then
        REGEN_CERT=true
    else
        REGEN_CERT=false
        ok "Keeping existing certificate"
    fi
fi

if [[ "$REGEN_CERT" == "true" ]]; then
    if [[ "$FQDN" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        SAN="IP:${FQDN}"
    else
        SAN="DNS:${FQDN},IP:127.0.0.1"
    fi
    openssl req -x509 -newkey rsa:2048 -nodes \
        -keyout "$SSL_DIR/key.pem" \
        -out    "$SSL_DIR/cert.pem" \
        -days 825 \
        -subj   "/CN=${FQDN}" \
        -addext "subjectAltName=${SAN}" \
        2>/dev/null
    ok "Self-signed certificate written to docker/ssl/"
    warn "Browsers will show a security warning — expected for dev/test."
fi

# ── 5. Write docker-compose.dev.yml ──────────────────────────────────────────
header "Writing docker-compose.dev.yml"

sed \
    -e "s|replace_with_32plus_char_secret|${APP_SECRET}|g" \
    -e "s|run: docker compose exec app php bin/console app:generate-encryption-key|${APP_ENCRYPTION_KEY}|g" \
    -e "s|https://your-dev-hostname.example.com|${BASE_URL}|g" \
    -e "s|ipam_password|${DB_PASSWORD}|g" \
    -e "s|root_password|${DB_ROOT_PASSWORD}|g" \
    "$DIST_FILE" > "$COMPOSE_FILE"

ok "docker-compose.dev.yml written"

# ── 6. Build image ────────────────────────────────────────────────────────────
header "Building image"

docker compose -f "$COMPOSE_FILE" build app
ok "Image built"

# ── 7. PHP dependencies ───────────────────────────────────────────────────────
header "Installing PHP dependencies"

COMPOSE_PROJECT=$(docker compose -f "$COMPOSE_FILE" config 2>/dev/null | awk '/^name:/ {print $2; exit}')
COMPOSE_PROJECT=${COMPOSE_PROJECT:-$(basename "$SCRIPT_DIR" | tr '[:upper:]' '[:lower:]')}

docker run --rm \
    --volume "$SCRIPT_DIR:/var/www/html" \
    --workdir /var/www/html \
    --env COMPOSER_HOME=/tmp/composer \
    "${COMPOSE_PROJECT}-app" \
    composer install --no-interaction --no-progress
ok "Dependencies installed"

# ── 8. Start services ─────────────────────────────────────────────────────────
header "Starting services"

docker compose -f "$COMPOSE_FILE" up -d
ok "Containers started"

echo "  Waiting for the app container to be ready…"
for i in $(seq 1 30); do
    if docker compose -f "$COMPOSE_FILE" exec -T app php -r 'echo "ok";' 2>/dev/null | grep -q "ok"; then
        break
    fi
    sleep 2
    [[ $i -eq 30 ]] && die "App container did not become ready in time. Check: docker compose -f docker-compose.dev.yml logs app"
done
ok "App container ready"

# ── 9. Database migrations ────────────────────────────────────────────────────
header "Running database migrations"

docker compose -f "$COMPOSE_FILE" exec -T app \
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
ok "Migrations complete"

# ── 10. Cache warmup ──────────────────────────────────────────────────────────
header "Warming up cache"

docker compose -f "$COMPOSE_FILE" exec -T app \
    php bin/console cache:warmup
ok "Cache warm"

# ── 11. SAML identity provider (optional) ────────────────────────────────────
# Note: /saml/metadata is only accessible after an IdP provider is imported.
header "SAML Identity Provider"
echo
read -rp "$(echo -e "  ${BOLD}Path to IdP metadata XML file, or URL (leave blank to skip): ${NC}")" SAML_SOURCE

if [[ -n "${SAML_SOURCE:-}" ]]; then
    ask SAML_NAME "Provider name (e.g. 'Okta', 'Azure AD')" "IdP"

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
    echo
    echo -e "  ${BOLD}Give this SP metadata URL to your IdP administrator:${NC}"
    echo -e "  ${CYAN}${BASE_URL}/saml/metadata${NC}"
else
    warn "Skipped — no one will be able to log in until a SAML provider is configured."
    warn "Run this when ready:"
    warn "  docker compose -f docker-compose.dev.yml exec app php bin/console app:saml:import-metadata <file-or-url> --activate"
    warn "SP metadata URL (accessible after import):  ${BASE_URL}/saml/metadata"
fi

# ── 12. Summary ───────────────────────────────────────────────────────────────
header "Setup complete"
echo
echo -e "  ${BOLD}DashDDI (dev) is running at:  ${CYAN}${BASE_URL}${NC}"
echo
echo -e "  ${BOLD}MySQL credentials (save these — they are not stored elsewhere):${NC}"
echo "    App user:  ipam / $DB_PASSWORD"
echo "    Root:      root / $DB_ROOT_PASSWORD"
echo
warn "Self-signed cert in use — browsers will show a security warning."
echo
echo "  Common commands:"
echo "    Logs:     docker compose -f docker-compose.dev.yml logs -f"
echo "    Stop:     docker compose -f docker-compose.dev.yml down"
echo "    Start:    docker compose -f docker-compose.dev.yml up -d"
echo "    Migrate:  docker compose -f docker-compose.dev.yml exec app php bin/console doctrine:migrations:migrate"
echo
echo -e "  ${BOLD}SP metadata URL:${NC}  ${CYAN}${BASE_URL}/saml/metadata${NC}"
echo
