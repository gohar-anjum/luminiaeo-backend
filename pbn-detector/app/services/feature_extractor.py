from __future__ import annotations

from collections import Counter
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional

import numpy as np

from app.schemas import BacklinkSignal
from app.utils.cache import cache_client

class NetworkFeatures:
    def __init__(
        self,
        ip_counts: Dict[str, int],
        registrar_counts: Dict[str, int],
        velocity_windows: Dict[str, List[BacklinkSignal]],
        total_peers: int
    ):
        self.ip_counts = ip_counts
        self.registrar_counts = registrar_counts
        self.velocity_windows = velocity_windows
        self.total_peers = total_peers

class FeatureExtractor:
    def __init__(self) -> None:
        self.now = datetime.now(timezone.utc)
        self._regex_cache: Dict[str, Any] = {}

    def precompute_network_features(self, peers: List[BacklinkSignal]) -> NetworkFeatures:
        ip_counts = Counter(p.ip for p in peers if p.ip)
        registrar_counts = Counter(p.whois_registrar for p in peers if p.whois_registrar)
        velocity_windows: Dict[str, List[BacklinkSignal]] = {}
        for peer in peers:
            if peer.ip:
                if peer.ip not in velocity_windows:
                    velocity_windows[peer.ip] = []
                velocity_windows[peer.ip].append(peer)
        return NetworkFeatures(
            ip_counts=dict(ip_counts),
            registrar_counts=dict(registrar_counts),
            velocity_windows=velocity_windows,
            total_peers=len(peers)
        )