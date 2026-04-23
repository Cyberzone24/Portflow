"""LLDP capture using scapy.

Listens on the chosen interface for the next LLDP frame (EtherType 0x88cc)
and returns the parsed neighbour info. Requires raw socket capability
(CAP_NET_RAW on Linux, Npcap on Windows).
"""

from __future__ import annotations

import platform
from dataclasses import dataclass, field
from typing import Optional

# Scapy is heavy; import lazily so non-capture commands stay fast.
def _import_scapy():
    from scapy.all import sniff  # noqa: F401  (re-exported)
    from scapy.contrib import lldp  # noqa: F401
    from scapy.layers.l2 import Ether  # noqa: F401
    return sniff, lldp, Ether


@dataclass
class LldpNeighbour:
    sys_name: str = ""
    sys_desc: str = ""
    chassis_id: str = ""
    port_id: str = ""
    port_desc: str = ""
    mgmt_address: str = ""
    pvid: Optional[int] = None
    vlans: list[int] = field(default_factory=list)
    captured_at: str = ""

    def as_lldp_dict(self) -> dict:
        d = {
            "sys_name": self.sys_name,
            "sys_desc": self.sys_desc,
            "chassis_id": self.chassis_id,
            "port_id": self.port_id,
            "port_desc": self.port_desc,
            "mgmt_address": self.mgmt_address,
            "captured_at": self.captured_at,
        }
        if self.pvid is not None:
            d["pvid"] = self.pvid
        if self.vlans:
            d["vlans"] = self.vlans
        return {k: v for k, v in d.items() if v not in ("", None, [])}


def _format_mac(raw: bytes) -> str:
    return ":".join(f"{b:02x}" for b in raw)


def _decode_chassis(tlv) -> str:
    sub = getattr(tlv, "subtype", None)
    val = getattr(tlv, "id", b"") or b""
    if isinstance(val, str):
        return val
    if sub == 4 and len(val) == 6:  # MAC address
        return _format_mac(val)
    try:
        return val.decode("utf-8", errors="ignore")
    except Exception:
        return val.hex()


def _decode_port(tlv) -> str:
    val = getattr(tlv, "id", b"") or b""
    if isinstance(val, str):
        return val
    sub = getattr(tlv, "subtype", None)
    if sub == 3 and len(val) == 6:  # MAC
        return _format_mac(val)
    try:
        return val.decode("utf-8", errors="ignore").strip("\x00")
    except Exception:
        return val.hex()


def _decode_text(tlv) -> str:
    raw = getattr(tlv, "description", b"") or b""
    if isinstance(raw, str):
        return raw.strip("\x00")
    try:
        return raw.decode("utf-8", errors="ignore").strip("\x00")
    except Exception:
        return raw.hex()


def _decode_mgmt(tlv) -> str:
    addr = getattr(tlv, "management_address", b"") or b""
    sub = getattr(tlv, "management_address_subtype", None)
    if isinstance(addr, str):
        return addr
    # 1 = IPv4, 2 = IPv6
    if sub == 1 and len(addr) == 4:
        return ".".join(str(b) for b in addr)
    if sub == 2 and len(addr) == 16:
        return ":".join(addr.hex()[i : i + 4] for i in range(0, 32, 4))
    if len(addr) == 6:
        return _format_mac(addr)
    return addr.hex() if addr else ""


def capture(iface: str, timeout: int = 120) -> LldpNeighbour:
    """Block until one LLDP frame arrives or the timeout elapses."""
    from datetime import datetime, timezone

    sniff, lldp, _Ether = _import_scapy()

    pkts = sniff(
        iface=iface,
        filter="ether proto 0x88cc",
        timeout=timeout,
        count=1,
        store=True,
    )
    if not pkts:
        raise TimeoutError(
            f"No LLDP frame received on {iface!r} within {timeout}s. "
            "Check that the cable is plugged in and the switch advertises LLDP."
        )

    pkt = pkts[0]
    n = LldpNeighbour(captured_at=datetime.now(timezone.utc).isoformat(timespec="seconds"))

    # Walk through stacked LLDPDU* layers.
    layer = pkt
    while layer is not None:
        cls = layer.__class__.__name__
        if cls == "LLDPDUChassisID":
            n.chassis_id = _decode_chassis(layer)
        elif cls == "LLDPDUPortID":
            n.port_id = _decode_port(layer)
        elif cls == "LLDPDUPortDescription":
            n.port_desc = _decode_text(layer)
        elif cls == "LLDPDUSystemName":
            n.sys_name = _decode_text(layer)
        elif cls == "LLDPDUSystemDescription":
            n.sys_desc = _decode_text(layer)
        elif cls == "LLDPDUManagementAddress":
            n.mgmt_address = _decode_mgmt(layer)
        elif cls == "LLDPDUGenericOrganisationSpecific":
            # IEEE 802.1 Port VLAN ID TLV: OUI 00-80-c2, subtype 1, payload = 2 bytes pvid.
            oui = getattr(layer, "org_code", None)
            sub = getattr(layer, "subtype", None)
            data = getattr(layer, "data", b"") or b""
            if oui in (0x0080C2, b"\x00\x80\xc2") and sub == 1 and len(data) >= 2:
                n.pvid = int.from_bytes(data[:2], "big")
        layer = layer.payload if hasattr(layer, "payload") and layer.payload else None

    return n


def list_interfaces() -> list[str]:
    """Best-effort list of usable network interfaces."""
    try:
        from scapy.all import get_if_list  # type: ignore

        return [i for i in get_if_list() if i not in ("lo", "any")]
    except Exception:
        if platform.system() == "Linux":
            import os

            try:
                return [i for i in os.listdir("/sys/class/net") if i != "lo"]
            except OSError:
                pass
        return []
