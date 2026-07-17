#!/usr/bin/env bash
# DashDDI DNS-01 challenge plugin for Proxmox VE's acme.sh-based ACME client.
#
# Install:
#   cp dns_dashddi.sh /usr/share/proxmox-acme/dnsapi/
#   chmod +x /usr/share/proxmox-acme/dnsapi/dns_dashddi.sh
#
# Then add the "dashddi" entry from dns-challenge-schema.snippet.json to
# /usr/share/proxmox-acme/dns-challenge-schema.json and restart pveproxy + pvedaemon.
#
# Required credentials (set via Proxmox UI: Datacenter → ACME → Challenge Plugins):
#   DASHDDI_API_URL    Base URL of your DashDDI instance (no trailing slash)
#   DASHDDI_API_TOKEN  Host-scoped API token (Hosts → <host> → Host API Token → Generate Token)
#
# Optional:
#   DASHDDI_CA_CERT    Path to a PEM CA bundle (for internal/self-signed certs), or
#                      "false" to disable SSL verification entirely (dev only).

dns_dashddi_add() {
  local fulldomain="$1"
  local txtvalue="$2"

  _dashddi_load_credentials || return 1

  local fqdn
  fqdn="$(_dashddi_fqdn_from_validation_name "$fulldomain")"
  _info "DashDDI: creating challenge TXT record for $fqdn"

  if ! _dashddi_request POST "/api/self/dns-challenge" \
      "{\"fqdn\":\"$fqdn\",\"validation\":\"$txtvalue\"}" 201; then
    return 1
  fi

  return 0
}

dns_dashddi_rm() {
  local fulldomain="$1"
  local txtvalue="$2"

  _dashddi_load_credentials || return 1

  local fqdn
  fqdn="$(_dashddi_fqdn_from_validation_name "$fulldomain")"
  _info "DashDDI: removing challenge TXT record for $fqdn"

  if ! _dashddi_request DELETE "/api/self/dns-challenge" \
      "{\"fqdn\":\"$fqdn\",\"validation\":\"$txtvalue\"}" 204 404; then
    return 1
  fi

  return 0
}

# ── Helpers ───────────────────────────────────────────────────────────────────

_dashddi_load_credentials() {
  if [ -z "$DASHDDI_API_URL" ]; then
    _err "DASHDDI_API_URL is not set"
    return 1
  fi
  if [ -z "$DASHDDI_API_TOKEN" ]; then
    _err "DASHDDI_API_TOKEN is not set"
    return 1
  fi
  # Strip trailing slash for safe URL concatenation
  DASHDDI_API_URL="${DASHDDI_API_URL%/}"
}

# Strip the _acme-challenge. prefix (and any trailing dot) to recover the
# source FQDN that DashDDI's /api/self/dns-challenge endpoint expects.
_dashddi_fqdn_from_validation_name() {
  local name="${1%.}"
  local prefix="_acme-challenge."
  if [ "${name#"$prefix"}" != "$name" ]; then
    echo "${name#"$prefix"}"
  else
    echo "$name"
  fi
}

# Make an authenticated JSON request. Accepts one or more expected HTTP status
# codes as trailing arguments. Returns 0 if the response matches, 1 otherwise.
_dashddi_request() {
  local method="$1"
  local path="$2"
  local body="$3"
  shift 3
  local expected_codes=("$@")

  local curl_args=(
    --silent
    --write-out "\n%{http_code}"
    --request "$method"
    --header "Authorization: Bearer $DASHDDI_API_TOKEN"
    --header "Content-Type: application/json"
    --data "$body"
  )

  if [ "${DASHDDI_CA_CERT:-}" = "false" ]; then
    curl_args+=(--insecure)
  elif [ -n "${DASHDDI_CA_CERT:-}" ]; then
    curl_args+=(--cacert "$DASHDDI_CA_CERT")
  fi

  curl_args+=("$DASHDDI_API_URL$path")

  local response http_code response_body
  response="$(curl "${curl_args[@]}" 2>&1)"
  http_code="$(printf '%s' "$response" | tail -1)"
  response_body="$(printf '%s' "$response" | head -n -1)"

  _debug "DashDDI: $method $path → HTTP $http_code"
  _debug "DashDDI: response body: $response_body"

  for code in "${expected_codes[@]}"; do
    if [ "$http_code" = "$code" ]; then
      return 0
    fi
  done

  _err "DashDDI: unexpected HTTP $http_code from $method $path: $response_body"
  return 1
}
