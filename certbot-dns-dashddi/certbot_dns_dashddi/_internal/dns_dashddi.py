"""Certbot DNS authenticator plugin for dashddi."""
import logging
from typing import Any, Callable

import requests
from certbot import errors
from certbot.plugins import dns_common

logger = logging.getLogger(__name__)

ACME_RECORD_TTL = 60


class Authenticator(dns_common.DNSAuthenticator):
    """DNS Authenticator for dashddi."""

    description = "Obtain certificates using a DNS TXT record via the dashddi DNS management API."

    def __init__(self, config: Any, name: str) -> None:
        super().__init__(config, name)
        self._record_ids: dict[str, int] = {}

    @classmethod
    def add_parser_arguments(
        cls, add: Callable[..., None], default_propagation_seconds: int = 30
    ) -> None:
        super().add_parser_arguments(add, default_propagation_seconds)
        add("credentials", help="Path to the dashddi credentials INI file.")

    def more_info(self) -> str:
        return "This plugin uses the dashddi API to add a TXT record for DNS-01 validation."

    def _setup_credentials(self) -> None:
        self.credentials = self._configure_credentials(
            "credentials",
            "dashddi credentials INI file",
            {
                "url": "Base URL of the dashddi instance (e.g. https://dashddi.goshen.edu)",
                "token": "API bearer token",
            },
        )

    def _perform(self, domain: str, validation_name: str, validation: str) -> None:
        url = self.credentials.conf("url").rstrip("/")
        token = self.credentials.conf("token")
        view_ids_raw = self.credentials.conf("view_ids") or ""
        view_ids = [int(v.strip()) for v in view_ids_raw.split(",") if v.strip()]

        domain_id, hostname = self._find_domain(url, token, validation_name)

        resp = requests.post(
            f"{url}/api/domain-records",
            headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
            json={
                "domain_id": domain_id,
                "hostname": hostname,
                "type": "TXT",
                "value": validation,
                "ttl": ACME_RECORD_TTL,
                "view_ids": view_ids,
            },
            timeout=30,
        )
        if resp.status_code != 201:
            raise errors.PluginError(
                f"Failed to create TXT record: {resp.status_code} {resp.text}"
            )

        self._record_ids[validation_name] = resp.json()["id"]
        logger.debug(
            "Created TXT record id=%s for %s", self._record_ids[validation_name], validation_name
        )

    def _cleanup(self, domain: str, validation_name: str, validation: str) -> None:
        record_id = self._record_ids.pop(validation_name, None)
        if record_id is None:
            logger.warning("No record ID found for %s, skipping cleanup", validation_name)
            return

        url = self.credentials.conf("url").rstrip("/")
        token = self.credentials.conf("token")

        resp = requests.delete(
            f"{url}/api/domain-records/{record_id}",
            headers={"Authorization": f"Bearer {token}"},
            timeout=30,
        )
        if resp.status_code not in (204, 404):
            raise errors.PluginError(
                f"Failed to delete TXT record {record_id}: {resp.status_code} {resp.text}"
            )

        logger.debug("Deleted TXT record id=%s", record_id)

    def _find_domain(self, url: str, token: str, validation_name: str) -> tuple[int, str]:
        """Find the best-matching domain in dashddi for the given validation name.

        Tries progressively shorter suffixes until a match is found, so
        _acme-challenge.sub.example.com will match sub.example.com before example.com.

        Returns (domain_id, hostname) where hostname is the label(s) to prepend.
        """
        resp = requests.get(
            f"{url}/api/domains",
            headers={"Authorization": f"Bearer {token}"},
            timeout=30,
        )
        if resp.status_code != 200:
            raise errors.PluginError(
                f"Failed to fetch domains from dashddi: {resp.status_code} {resp.text}"
            )

        domains = {d["name"]: d["id"] for d in resp.json()}

        parts = validation_name.rstrip(".").split(".")
        for i in range(1, len(parts)):
            candidate = ".".join(parts[i:])
            if candidate in domains:
                hostname = ".".join(parts[:i])
                return domains[candidate], hostname

        raise errors.PluginError(
            f"Could not find a matching domain in dashddi for '{validation_name}'. "
            f"Available domains: {', '.join(sorted(domains.keys()))}"
        )
