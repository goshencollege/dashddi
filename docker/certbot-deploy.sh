#!/bin/bash
# Certbot deploy hook: copy the renewed cert into Docker's ssl_certs volume.
#
# Usage — either:
#   Place in /etc/letsencrypt/renewal-hooks/deploy/ (certbot runs it automatically)
#   Pass via:  --deploy-hook /path/to/dashddi/docker/certbot-deploy.sh
#
# Certbot sets $RENEWED_LINEAGE to the live cert directory before calling this script.
set -euo pipefail

DASHDDI_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cp "${RENEWED_LINEAGE}/fullchain.pem" "${DASHDDI_DIR}/docker/ssl/cert.pem"
cp "${RENEWED_LINEAGE}/privkey.pem"   "${DASHDDI_DIR}/docker/ssl/key.pem"

make -C "${DASHDDI_DIR}" cert-install
