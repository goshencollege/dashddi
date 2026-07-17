"""Certbot DNS authenticator plugin for DashDDI (host-scoped token)."""
import logging
from typing import Any, Callable

import requests
from certbot import errors
from certbot.plugins import dns_common

logger = logging.getLogger(__name__)


class Authenticator(dns_common.DNSAuthenticator):
    """DNS Authenticator for DashDDI using a host-scoped token."""

    description = (
        "Obtain certificates using a DNS TXT record via the DashDDI "
        "host self-service API. Requires a host-scoped token generated "
        "from the host detail page in DashDDI."
    )

    def __init__(self, config: Any, name: str) -> None:
        super().__init__(config, name)

    @classmethod
    def add_parser_arguments(
        cls, add: Callable[..., None], default_propagation_seconds: int = 30
    ) -> None:
        super().add_parser_arguments(add, default_propagation_seconds)
        add("credentials", help="Path to the DashDDI credentials INI file.")

    def more_info(self) -> str:
        return (
            "This plugin uses the DashDDI host self-service API (/api/self) "
            "to create and remove ACME DNS-01 challenge TXT records. "
            "The host-scoped token must be generated on the host being certified."
        )

    def _setup_credentials(self) -> None:
        self.credentials = self._configure_credentials(
            "credentials",
            "DashDDI credentials INI file",
            {
                "url": "Base URL of the DashDDI instance (e.g. https://dashddi.example.com)",
                "token": "Host-scoped API token (generated from the host detail page in DashDDI)",
            },
        )

    def _perform(self, domain: str, validation_name: str, validation: str) -> None:
        fqdn = self._fqdn_from_validation_name(validation_name)
        url = self.credentials.conf("url").rstrip("/")
        token = self.credentials.conf("token")

        resp = requests.post(
            f"{url}/api/self/dns-challenge",
            headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
            json={"fqdn": fqdn, "validation": validation},
            timeout=30,
        )
        if resp.status_code != 201:
            raise errors.PluginError(
                f"Failed to create challenge record for {fqdn}: "
                f"{resp.status_code} {resp.text}"
            )

        logger.debug("Created challenge TXT record id=%s for %s", resp.json().get("id"), fqdn)

    def _cleanup(self, domain: str, validation_name: str, validation: str) -> None:
        fqdn = self._fqdn_from_validation_name(validation_name)
        url = self.credentials.conf("url").rstrip("/")
        token = self.credentials.conf("token")

        resp = requests.delete(
            f"{url}/api/self/dns-challenge",
            headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
            json={"fqdn": fqdn, "validation": validation},
            timeout=30,
        )
        if resp.status_code not in (204, 404):
            raise errors.PluginError(
                f"Failed to delete challenge record for {fqdn}: "
                f"{resp.status_code} {resp.text}"
            )

        logger.debug("Deleted challenge TXT record for %s", fqdn)

    @staticmethod
    def _fqdn_from_validation_name(validation_name: str) -> str:
        """Derive the source FQDN from the _acme-challenge.* validation name.

        Certbot passes _acme-challenge.<hostname>; the DashDDI self-service API
        expects the source A/AAAA record's FQDN instead.
        """
        name = validation_name.rstrip(".")
        prefix = "_acme-challenge."
        if name.startswith(prefix):
            return name[len(prefix):]
        return name
