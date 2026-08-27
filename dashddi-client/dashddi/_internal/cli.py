"""
dashddi: DashDDI host self-service client.

The `cert` subcommand discovers this host's FQDNs from DashDDI (or uses an explicit
override list), requests a certificate covering them via certbot and the dns-dashddi
plugin, and optionally publishes CAA/HTTPS records for each issued name.
"""
import argparse
import configparser
import getpass
import os
import shlex
import shutil
import subprocess
import sys
from dataclasses import dataclass
from urllib.parse import urlsplit

import requests

DEFAULT_CA_DOMAIN = "letsencrypt.org"
DEFAULT_HTTPS_VALUE = "1 . alpn=h2"
DEFAULT_CREDENTIALS_PATH = "/etc/dashddi/dashddi.ini"

SYSTEMD_SERVICE_PATH = "/etc/systemd/system/dashddi.service"
SYSTEMD_TIMER_PATH = "/etc/systemd/system/dashddi.timer"

SYSTEMD_SERVICE_TEMPLATE = """[Unit]
Description=Renew certificates via DashDDI
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart={exec_start}
PrivateTmp=true
"""

SYSTEMD_TIMER_TEMPLATE = """[Unit]
Description=Run dashddi cert twice daily

[Timer]
OnCalendar=*-*-* 03,15:00:00
RandomizedDelaySec=1h
Persistent=true

[Install]
WantedBy=timers.target
"""


@dataclass
class Credentials:
    url: str
    token: str
    verify: "bool | str"
    names: list[str]
    caa: bool
    https: bool
    https_value: str


def _prompt_and_write_credentials(path: str) -> None:
    """Interactively prompt for and write a new credentials file at `path`.

    Only called when `path` doesn't exist yet. Requires an interactive terminal —
    a missing file with no TTY (e.g. a systemd timer firing before initial setup
    has happened) is a hard error rather than hanging on input().
    """
    if not sys.stdin.isatty():
        sys.exit(
            f"Error: no credentials file at {path} and input is not interactive.\n"
            "Create it manually (see dashddi.ini.example) or pass --credentials "
            "pointing at an existing file."
        )

    print(f"No credentials file found at {path} — let's create one.")
    url = input("DashDDI URL (e.g. https://dashddi.example.com): ").strip().rstrip("/")
    token = getpass.getpass("Host-scoped API token (from the host detail page in DashDDI): ").strip()
    if not url or not token:
        sys.exit("Error: URL and token are both required.")

    try:
        directory = os.path.dirname(path)
        if directory:
            os.makedirs(directory, mode=0o755, exist_ok=True)
        with open(path, "w", encoding="utf-8") as f:
            f.write(f"dns_dashddi_url = {url}\n")
            f.write(f"dns_dashddi_token = {token}\n")
        os.chmod(path, 0o600)
    except OSError as exc:
        sys.exit(f"Error: failed to write credentials file at {path}: {exc}")

    print(f"Credentials written to {path}")


def _read_credentials(path: str) -> Credentials:
    """Parse a dashddi credentials INI file.

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

    raw_names = section.get("dns_dashddi_names", "").strip()
    names = _split_names(raw_names)

    https_value = section.get("dns_dashddi_https_value", "").strip()
    https = _parse_bool(section.get("dns_dashddi_https", "")) or bool(https_value)

    return Credentials(
        url=url,
        token=token,
        verify=verify,
        names=names,
        caa=_parse_bool(section.get("dns_dashddi_caa", "")),
        https=https,
        https_value=https_value,
    )


def _parse_bool(raw: str) -> bool:
    return raw.strip().lower() in ("1", "true", "yes", "on")


def _split_names(raw: str) -> list[str]:
    return [n.strip() for n in raw.split(",") if n.strip()]


def _fetch_fqdns(creds: Credentials) -> list[str]:
    """Return deduplicated A/AAAA/CNAME FQDNs for this host from DashDDI."""
    try:
        resp = requests.get(
            f"{creds.url}/api/self/host",
            headers={"Authorization": f"Bearer {creds.token}"},
            timeout=15,
            verify=creds.verify,
        )
    except requests.RequestException as exc:
        sys.exit(f"Error contacting DashDDI at {creds.url}: {exc}")

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


def _ca_domain_from_certbot_args(certbot_args: list[str]) -> str:
    """Detect the registrable domain of the ACME server certbot was told to use.

    Certbot defaults to Let's Encrypt production unless a custom directory URL is
    passed via --server. The CAA "issue" tag only needs the CA's registrable
    domain (e.g. "letsencrypt.org"), not the full ACME endpoint hostname.
    """
    for i, arg in enumerate(certbot_args):
        if arg == "--server" and i + 1 < len(certbot_args):
            return _registrable_domain(certbot_args[i + 1])
        if arg.startswith("--server="):
            return _registrable_domain(arg.split("=", 1)[1])
    return DEFAULT_CA_DOMAIN


def _registrable_domain(url: str) -> str:
    if "://" not in url:
        url = f"https://{url}"
    host = urlsplit(url).hostname or url
    labels = host.split(".")
    return ".".join(labels[-2:]) if len(labels) >= 2 else host


def _publish_records(creds: Credentials, fqdns: list[str], certbot_args: list[str]) -> None:
    """Create/update CAA and/or HTTPS records for each issued FQDN, if configured.

    Failures here are logged and skipped rather than raised — a problem publishing a
    supplementary record must not turn an otherwise-successful certificate issuance
    into a failed run.
    """
    record_types = []
    if creds.caa:
        ca_domain = _ca_domain_from_certbot_args(certbot_args)
        record_types.append(("CAA", f'0 issue "{ca_domain}"'))
    if creds.https:
        record_types.append(("HTTPS", creds.https_value or DEFAULT_HTTPS_VALUE))
    if not record_types:
        return

    for fqdn in fqdns:
        for record_type, value in record_types:
            try:
                resp = requests.put(
                    f"{creds.url}/api/self/records",
                    headers={"Authorization": f"Bearer {creds.token}", "Content-Type": "application/json"},
                    json={"fqdn": fqdn, "type": record_type, "value": value},
                    timeout=30,
                    verify=creds.verify,
                )
            except requests.RequestException as exc:
                print(f"Warning: failed to publish {record_type} record for {fqdn}: {exc}", file=sys.stderr)
                continue

            if resp.status_code in (200, 201):
                action = resp.json().get("action", "ok")
                print(f"{record_type} record for {fqdn}: {action}")
            else:
                print(
                    f"Warning: failed to publish {record_type} record for {fqdn}: "
                    f"{resp.status_code} {resp.text}",
                    file=sys.stderr,
                )


def _renewal_command(args: argparse.Namespace) -> list[str]:
    """Rebuild the exact `dashddi cert ...` invocation used for this run.

    Used as the ExecStart for the generated systemd service, so the scheduled
    renewal reproduces the same credentials file, name selection, and
    CAA/HTTPS/certbot options as the run that created the schedule.
    """
    dashddi_bin = shutil.which("dashddi") or os.path.abspath(sys.argv[0])
    cmd = [dashddi_bin, "cert", "--credentials", args.credentials]
    if args.names:
        cmd += ["--names", args.names]
    if args.caa:
        cmd.append("--caa")
    if args.https_value:
        cmd += ["--https-value", args.https_value]
    elif args.https:
        cmd.append("--https")
    if args.certbot_args:
        cmd.append("--")
        cmd += args.certbot_args
    return cmd


def _ensure_renewal_schedule(args: argparse.Namespace) -> None:
    """Install a systemd timer that reruns this command twice daily, if one isn't
    already set up.

    Only runs after a successful issuance. Never overwrites an existing timer
    (e.g. one deployed by the Ansible role, or a prior run of this command) —
    it may have been hand-customized, so we leave it alone rather than
    clobbering it on every renewal.
    """
    if os.path.exists(SYSTEMD_TIMER_PATH):
        return

    if shutil.which("systemctl") is None:
        print(
            "Note: systemd not found — skipping automatic renewal schedule setup. "
            "Set up cron or another scheduler manually, or pass --no-schedule to "
            "silence this message.",
            file=sys.stderr,
        )
        return

    if os.geteuid() != 0:
        print(
            "Note: not running as root — skipping automatic renewal schedule setup. "
            "Re-run as root to have a systemd timer created automatically, or pass "
            "--no-schedule to silence this message.",
            file=sys.stderr,
        )
        return

    exec_start = shlex.join(_renewal_command(args))
    try:
        with open(SYSTEMD_SERVICE_PATH, "w", encoding="utf-8") as f:
            f.write(SYSTEMD_SERVICE_TEMPLATE.format(exec_start=exec_start))
        with open(SYSTEMD_TIMER_PATH, "w", encoding="utf-8") as f:
            f.write(SYSTEMD_TIMER_TEMPLATE)
        subprocess.run(["systemctl", "daemon-reload"], check=True)
        subprocess.run(["systemctl", "enable", "--now", "dashddi.timer"], check=True)
    except (OSError, subprocess.CalledProcessError) as exc:
        print(f"Warning: failed to set up automatic renewal schedule: {exc}", file=sys.stderr)
        return

    print(f"Renewal schedule installed: systemd timer 'dashddi.timer' ({SYSTEMD_TIMER_PATH})")


def _cmd_cert(args: argparse.Namespace) -> int:
    if not os.path.exists(args.credentials):
        _prompt_and_write_credentials(args.credentials)

    creds = _read_credentials(args.credentials)
    if args.caa:
        creds.caa = True
    if args.https_value:
        creds.https = True
        creds.https_value = args.https_value
    elif args.https:
        creds.https = True

    if args.names:
        fqdns = _split_names(args.names)
    elif creds.names:
        fqdns = creds.names
    else:
        fqdns = _fetch_fqdns(creds)

    if not fqdns:
        sys.exit(
            "No FQDNs to certify.\n"
            "Check that:\n"
            "  - The host has A, AAAA, or CNAME records linked to its interfaces.\n"
            "  - Each domain has at least one view marked Public in DashDDI.\n"
            "  - Or set dns_dashddi_names in the credentials file (or pass --names) "
            "to list them explicitly."
        )

    print(f"Requesting certificate for {len(fqdns)} domain(s): {', '.join(fqdns)}")

    cmd = [
        "certbot", "certonly",
        "--authenticator", "dns-dashddi",
        "--dns-dashddi-credentials", args.credentials,
    ]
    for fqdn in fqdns:
        cmd += ["-d", fqdn]
    cmd += args.certbot_args

    result = subprocess.call(cmd)
    if result == 0:
        _publish_records(creds, fqdns, args.certbot_args)
        if args.schedule:
            _ensure_renewal_schedule(args)
    return result


def main() -> None:
    parser = argparse.ArgumentParser(
        prog="dashddi",
        description="DashDDI host self-service client.",
    )
    subparsers = parser.add_subparsers(dest="command", required=True)

    cert_parser = subparsers.add_parser(
        "cert",
        help="Request or renew a certificate for this host's FQDNs.",
        description=(
            "Request or renew a TLS certificate for this host's FQDNs in DashDDI. "
            "By default, requests all publicly-reachable A/AAAA/CNAME FQDNs; set "
            "dns_dashddi_names in the credentials file (or pass --names) to certify "
            "an explicit list instead — a subset, or concrete names covered by a "
            "wildcard record."
        ),
        epilog=(
            "Any arguments after -- are passed through to certbot. Example:\n"
            "  dashddi cert --credentials /etc/dashddi/dashddi.ini "
            "-- --dns-dashddi-propagation-seconds 60 --dry-run"
        ),
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    cert_parser.add_argument(
        "--credentials",
        default=DEFAULT_CREDENTIALS_PATH,
        metavar="FILE",
        help=(
            f"Path to the DashDDI credentials INI file. Defaults to {DEFAULT_CREDENTIALS_PATH}. "
            "If it doesn't exist yet, you're prompted to create it (interactive sessions only)."
        ),
    )
    cert_parser.add_argument(
        "--names",
        metavar="FQDN,FQDN,...",
        help=(
            "Comma-separated explicit list of FQDNs to certify, replacing auto-discovery. "
            "Takes precedence over dns_dashddi_names in the credentials file."
        ),
    )
    cert_parser.add_argument(
        "--caa",
        action="store_true",
        help=(
            "After issuance, publish a CAA record at each certified FQDN authorizing "
            "the CA that issued the certificate (auto-detected from the ACME server "
            "used — Let's Encrypt by default, or whatever --server points at). "
            "Same effect as dns_dashddi_caa = true in the credentials file."
        ),
    )
    cert_parser.add_argument(
        "--https",
        action="store_true",
        help=(
            "After issuance, publish an HTTPS (RFC 9460) record at each certified "
            f"FQDN, using a default value ({DEFAULT_HTTPS_VALUE!r}) unless --https-value "
            "is also given. Same effect as dns_dashddi_https = true in the credentials file."
        ),
    )
    cert_parser.add_argument(
        "--https-value",
        metavar="VALUE",
        help=(
            "Explicit HTTPS record value to publish after issuance (implies --https). "
            "Takes precedence over dns_dashddi_https_value in the credentials file."
        ),
    )
    cert_parser.add_argument(
        "--no-schedule",
        dest="schedule",
        action="store_false",
        default=True,
        help=(
            "Don't set up an automatic renewal schedule after issuance. By default, "
            "after a successful certificate request, this command installs and enables "
            f"a systemd timer ({SYSTEMD_TIMER_PATH}) that reruns this exact "
            "invocation twice daily — unless one already exists, or the process isn't "
            "running as root, or systemd isn't available, in which case setup is "
            "silently skipped (or logged as a warning)."
        ),
    )
    cert_parser.add_argument(
        "certbot_args",
        nargs=argparse.REMAINDER,
        help="Extra arguments passed directly to certbot (use -- to separate).",
    )
    cert_parser.set_defaults(func=_cmd_cert)

    args = parser.parse_args()

    # Strip leading '--' separator if present
    if args.certbot_args and args.certbot_args[0] == "--":
        args.certbot_args = args.certbot_args[1:]

    sys.exit(args.func(args))
