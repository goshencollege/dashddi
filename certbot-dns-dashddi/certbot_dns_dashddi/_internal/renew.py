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


def _read_credentials(path: str) -> tuple[str, str, "bool | str"]:
    """Return (url, token, verify) from a dashddi credentials INI file.

    verify follows the requests convention: True (system CA), False (skip),
    or a path string (custom CA bundle). Controlled by dns_dashddi_ca_cert.
    """
    cfg = configparser.RawConfigParser()
    # certbot INI files have no section header; inject a dummy one
    with open(path, encoding="utf-8-sig") as f:
        cfg.read_string("[dashddi]\n" + f.read())
    section = cfg["dashddi"]
    url = section.get("dns_dashddi_url", "").strip().rstrip("/")
    token = section.get("dns_dashddi_token", "").strip()
    if not url or not token:
        sys.exit("Error: credentials file must contain dns_dashddi_url and dns_dashddi_token")
    raw_ca = section.get("dns_dashddi_ca_cert", "").strip()
    if not raw_ca:
        verify: "bool | str" = True
    elif raw_ca.lower() == "false":
        verify = False
    else:
        verify = raw_ca
    return url, token, verify


def _fetch_fqdns(url: str, token: str, verify: "bool | str" = True) -> list[str]:
    """Return deduplicated A/AAAA/CNAME FQDNs for this host from DashDDI."""
    try:
        resp = requests.get(
            f"{url}/api/self/host",
            headers={"Authorization": f"Bearer {token}"},
            timeout=15,
            verify=verify,
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
            if record.get("type") not in ("A", "AAAA", "CNAME"):
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

    url, token, verify = _read_credentials(args.credentials)
    fqdns = _fetch_fqdns(url, token, verify)

    if not fqdns:
        sys.exit(
            "No publicly-reachable A/AAAA/CNAME FQDNs found for this host in DashDDI.\n"
            "Check that:\n"
            "  - The host has A, AAAA, or CNAME records linked to its interfaces.\n"
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
