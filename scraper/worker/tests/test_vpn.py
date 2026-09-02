import pytest

from tenscraper.vpn import VpnController, VpnError, VpnProfile


def test_build_connect_command_per_provider():
    ctrl = VpnController(runner=lambda cmd: 0)
    proton = VpnProfile(provider="protonvpn", name="DE", country="DE", config_ref="DE#12")
    wg = VpnProfile(provider="wireguard", name="wg-de", config_ref="/etc/wg/de.conf")
    ovpn = VpnProfile(provider="openvpn", name="ov", config_ref="/etc/ovpn/de.ovpn")

    assert ctrl.build_connect_command(proton) == ["protonvpn-cli", "connect", "DE#12"]
    assert ctrl.build_connect_command(wg) == ["wg-quick", "up", "/etc/wg/de.conf"]
    assert ctrl.build_connect_command(ovpn) == ["openvpn", "--config", "/etc/ovpn/de.ovpn", "--daemon"]


def test_unknown_provider_raises():
    ctrl = VpnController(runner=lambda cmd: 0)
    with pytest.raises(VpnError):
        ctrl.build_connect_command(VpnProfile(provider="nord", name="x"))


def test_connect_records_profile_and_raises_on_failure():
    calls = []

    def ok_runner(cmd):
        calls.append(cmd)
        return 0

    ctrl = VpnController(runner=ok_runner)
    profile = VpnProfile(provider="protonvpn", name="DE", country="DE", config_ref="DE#1")
    ctrl.connect(profile)
    assert calls and calls[0][0] == "protonvpn-cli"

    failing = VpnController(runner=lambda cmd: 3)
    with pytest.raises(VpnError):
        failing.connect(profile)


def test_verify_country_matches_via_injected_checker():
    ctrl = VpnController(runner=lambda cmd: 0, ip_checker=lambda: "de")
    profile = VpnProfile(provider="protonvpn", name="DE", country="DE", config_ref="DE#1")
    ctrl.connect(profile)
    assert ctrl.verify_country() is True
    assert ctrl.verify_country("FR") is False


def test_verify_country_without_checker_raises():
    ctrl = VpnController(runner=lambda cmd: 0)
    ctrl.connect(VpnProfile(provider="protonvpn", name="DE", country="DE"))
    with pytest.raises(VpnError):
        ctrl.verify_country()


def test_disconnect_runs_command_and_clears():
    cmds = []
    ctrl = VpnController(runner=lambda cmd: cmds.append(cmd) or 0)
    profile = VpnProfile(provider="wireguard", name="wg", config_ref="/etc/wg/de.conf")
    ctrl.connect(profile)
    ctrl.disconnect()
    assert ["wg-quick", "down", "/etc/wg/de.conf"] in cmds
    # second disconnect is a no-op
    ctrl.disconnect()
