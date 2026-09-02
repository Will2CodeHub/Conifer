"""VPN egress abstraction for geo-appropriate fetching (spec section 6).

Geo-access only: bring up a chosen egress before fetching a section's sources,
verify the egress country, tear down after. Not per-request rotation.

``runner`` (executes a command, returns exit code) and ``ip_checker`` (returns
the current egress country code) are injected so the controller is unit-tested
without a real tunnel or network.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Callable, List, Optional

# runner(command: list[str]) -> int   (0 == success)
Runner = Callable[[List[str]], int]
# ip_checker() -> str   (ISO country code of current egress, e.g. "DE")
IpChecker = Callable[[], str]


@dataclass
class VpnProfile:
    provider: str          # 'protonvpn' | 'wireguard' | 'openvpn'
    name: str
    country: str = ""      # expected egress country code, e.g. "DE"
    config_ref: str = ""   # provider-specific: server name or config file path


class VpnError(RuntimeError):
    pass


class VpnController:
    def __init__(self, runner: Runner, ip_checker: Optional[IpChecker] = None):
        self._runner = runner
        self._ip_checker = ip_checker
        self._connected_profile: Optional[VpnProfile] = None

    def build_connect_command(self, profile: VpnProfile) -> List[str]:
        """Provider-specific connect command (data, not executed here)."""
        p = profile.provider.lower()
        if p == "protonvpn":
            target = profile.config_ref or profile.country
            return ["protonvpn-cli", "connect", target] if target else ["protonvpn-cli", "connect", "--fastest"]
        if p == "wireguard":
            return ["wg-quick", "up", profile.config_ref]
        if p == "openvpn":
            return ["openvpn", "--config", profile.config_ref, "--daemon"]
        raise VpnError(f"Unknown VPN provider: {profile.provider}")

    def build_disconnect_command(self, profile: VpnProfile) -> List[str]:
        p = profile.provider.lower()
        if p == "protonvpn":
            return ["protonvpn-cli", "disconnect"]
        if p == "wireguard":
            return ["wg-quick", "down", profile.config_ref]
        if p == "openvpn":
            return ["pkill", "openvpn"]
        raise VpnError(f"Unknown VPN provider: {profile.provider}")

    def connect(self, profile: VpnProfile) -> None:
        code = self._runner(self.build_connect_command(profile))
        if code != 0:
            raise VpnError(f"VPN connect failed (exit {code}) for {profile.name}")
        self._connected_profile = profile

    def verify_country(self, expected_country: Optional[str] = None) -> bool:
        """Confirm the live egress country matches what the profile expects.

        Returns True only when a checker is configured and the codes match
        (case-insensitive). Raises if no checker was injected.
        """
        if self._ip_checker is None:
            raise VpnError("No ip_checker configured; cannot verify egress country")
        expected = (expected_country
                    or (self._connected_profile.country if self._connected_profile else ""))
        if not expected:
            # Nothing to assert against; treat as unverified.
            return False
        actual = (self._ip_checker() or "").strip().upper()
        return actual == expected.strip().upper()

    def disconnect(self) -> None:
        if self._connected_profile is None:
            return
        self._runner(self.build_disconnect_command(self._connected_profile))
        self._connected_profile = None
