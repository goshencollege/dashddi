"""
dashddi-certbot: fetch this host's FQDNs from DashDDI and run certbot.

Calls GET /api/self/host with the host-scoped token to discover all
publicly-reachable A/AAAA record FQDNs, then invokes certbot with the
dns-dashddi plugin to request a single SAN certificate covering them all.
"""
import argparse
import configparser
import subprocess
import sys

import requests


def _read_credentials(path: str) -> tuple[str, str]:
    """Return (url, token) from a dashddi credentials INI file."""
    cfg = configparser.RawConfigParser()
    # certbot INI files have no section header; inject a dummy one
    with open(path) as f:
        cfg.read_string("[dashddi]\n" + f.read())
    section = cfg["dashddi"]
    url = section.get("dns_dashddi_url", "").strip().rstrip("/")
    token = section.get("dns_dashddi_token", "").strip()
    if not url or not token:
        sys.exit("Error: credentials file must contain dns_dashddi_url and dns_dashddi_token")
    return url, token


def _fetch_fqdns(url: str, token: str) -> list[str]:
    """Return deduplicated A/AAAA FQDNs for this host from DashDDI."""
    try:
        resp = requests.get(
            f"{url}/api/self/host",
            headers={"Authorization": f"Bearer {token}"},
            timeout=15,
        )
    except requests.RequestException as exc:
        sys.exit(f"Error contacting DashDDI at {url}: {exc}")

    if resp.status_code == 401:
        sys.exit(
            "Error: 401 Unauthorized — token is invalid or this host's IP address "
            "is not registered in DashDDI."
        )
    if resp.status_code != 200:
        sys.exit(f"Error: GET /api/self/host returned {resp.status_code}: {resp.text}")

    data = resp.json()
    seen: set[str] = set()
    fqdns: list[str] = []
    for iface in data.get("interfaces", []):
        for record in iface.get("records", []):
            if record.get("type") not in ("A", "AAAA"):
                continue
            fqdn = record.get("fqdn", "").rstrip(".")
            if fqdn and fqdn not in seen:
                seen.add(fqdn)
                fqdns.append(fqdn)

    return fqdns


def main() -> None:
    parser = argparse.ArgumentParser(
        description=(
            "Request or renew a TLS certificate for all A/AAAA FQDNs "
            "registered to this host in DashDDI."
        ),
        epilog=(
            "Any arguments after -- are passed through to certbot. Example:\n"
            "  dashddi-certbot --credentials /etc/letsencrypt/dashddi.ini "
            "-- --dns-dashddi-propagation-seconds 60 --dry-run"
        ),
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument(
        "--credentials",
        required=True,
        metavar="FILE",
        help="Path to the DashDDI credentials INI file.",
    )
    parser.add_argument(
        "certbot_args",
        nargs=argparse.REMAINDER,
        help="Extra arguments passed directly to certbot (use -- to separate).",
    )
    args = parser.parse_args()

    # Strip leading '--' separator if present
    extra = args.certbot_args
    if extra and extra[0] == "--":
        extra = extra[1:]

    url, token = _read_credentials(args.credentials)
    fqdns = _fetch_fqdns(url, token)

    if not fqdns:
        sys.exit(
            "No publicly-reachable A/AAAA FQDNs found for this host in DashDDI.\n"
            "Check that:\n"
            "  - The host has A or AAAA records linked to its interfaces.\n"
            "  - Each domain has at least one view marked Public in DashDDI."
        )

    print(f"Requesting certificate for {len(fqdns)} domain(s): {', '.join(fqdns)}")

    cmd = [
        "certbot", "certonly",
        "--authenticator", "dns-dashddi",
        "--dns-dashddi-credentials", args.credentials,
    ]
    for fqdn in fqdns:
        cmd += ["-d", fqdn]
    cmd += extra

    sys.exit(subprocess.call(cmd))
