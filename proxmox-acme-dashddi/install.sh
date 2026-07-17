#!/usr/bin/env bash
# Install the DashDDI ACME plugin on a Proxmox VE node.
#
# One-liner (no git required):
#   curl -fsSL https://raw.githubusercontent.com/goshencollege/dashddi/main/proxmox-acme-dashddi/install.sh | bash
#
# Or download and inspect first:
#   curl -fsSLO https://raw.githubusercontent.com/goshencollege/dashddi/main/proxmox-acme-dashddi/install.sh
#   bash install.sh [--no-restart]
#
# Options:
#   --no-restart   Skip restarting pveproxy and pvedaemon (do it manually later)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DNSAPI_DIR="/usr/share/proxmox-acme/dnsapi"
SCHEMA_FILE="/usr/share/proxmox-acme/dns-challenge-schema.json"
PLUGIN_SRC="$SCRIPT_DIR/dns_dashddi.sh"
SNIPPET_SRC="$SCRIPT_DIR/dns-challenge-schema.snippet.json"

BASE_URL="https://raw.githubusercontent.com/goshencollege/dashddi/main/proxmox-acme-dashddi"

NO_RESTART=false

for arg in "$@"; do
  case "$arg" in
    --no-restart) NO_RESTART=true ;;
    *)
      echo "Usage: $0 [--no-restart]" >&2
      exit 1
      ;;
  esac
done

# ── Preflight checks ──────────────────────────────────────────────────────────

if [ "$(id -u)" -ne 0 ]; then
  echo "Error: this script must be run as root." >&2
  exit 1
fi

if [ ! -d "$DNSAPI_DIR" ]; then
  echo "Error: $DNSAPI_DIR not found — is this a Proxmox VE node?" >&2
  exit 1
fi

if [ ! -f "$SCHEMA_FILE" ]; then
  echo "Error: $SCHEMA_FILE not found — is libproxmox-acme-plugins installed?" >&2
  exit 1
fi

# ── Fetch sibling files if not present (supports running via curl | bash) ─────

for file in dns_dashddi.sh dns-challenge-schema.snippet.json; do
  dest="$SCRIPT_DIR/$file"
  if [ ! -f "$dest" ]; then
    echo "Fetching $file..."
    curl -fsSL "$BASE_URL/$file" -o "$dest"
  fi
done

# ── Step 1: Install plugin script ─────────────────────────────────────────────

echo "Installing dns_dashddi.sh..."
cp "$PLUGIN_SRC" "$DNSAPI_DIR/dns_dashddi.sh"
chmod +x "$DNSAPI_DIR/dns_dashddi.sh"
echo "  → $DNSAPI_DIR/dns_dashddi.sh"

# ── Step 2: Merge JSON snippet ────────────────────────────────────────────────

echo "Merging schema entry into $SCHEMA_FILE..."

# Back up the original before touching it
cp "$SCHEMA_FILE" "${SCHEMA_FILE}.bak"

python3 - "$SCHEMA_FILE" "$SNIPPET_SRC" <<'PYEOF'
import sys, json, os, tempfile

schema_path = sys.argv[1]
snippet_path = sys.argv[2]

with open(schema_path) as f:
    schema = json.load(f)

with open(snippet_path) as f:
    snippet = json.load(f)

if "dashddi" in schema:
    print("  → 'dashddi' entry already present, updating in place")
else:
    print("  → Adding 'dashddi' entry")

schema.update(snippet)

# Atomic write: temp file in same directory, then rename
schema_dir = os.path.dirname(os.path.abspath(schema_path))
fd, tmp_path = tempfile.mkstemp(dir=schema_dir, suffix=".tmp")
try:
    with os.fdopen(fd, "w") as f:
        json.dump(schema, f, indent=2)
        f.write("\n")
    os.replace(tmp_path, schema_path)
except Exception:
    os.unlink(tmp_path)
    raise
PYEOF

# ── Step 3: Validate ──────────────────────────────────────────────────────────

echo "Validating schema JSON..."
if ! python3 -m json.tool "$SCHEMA_FILE" > /dev/null; then
  echo "Error: schema validation failed — restoring backup." >&2
  cp "${SCHEMA_FILE}.bak" "$SCHEMA_FILE"
  exit 1
fi
echo "  → OK"

# ── Step 4: Restart services ──────────────────────────────────────────────────

if [ "$NO_RESTART" = true ]; then
  echo "Skipping service restart (--no-restart specified)."
  echo "Run the following when ready:"
  echo "  systemctl restart pveproxy pvedaemon"
else
  echo "Restarting pveproxy and pvedaemon..."
  systemctl restart pveproxy pvedaemon
  echo "  → Done"
fi

echo ""
echo "Installation complete."
echo "Configure the plugin in Proxmox: Datacenter → ACME → Challenge Plugins → Add → DNS → dashddi"
