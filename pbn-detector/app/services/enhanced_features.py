from __future__ import annotations

from collections import defaultdict
from datetime import datetime, timezone
from typing import Dict, List, Optional

import numpy as np
from loguru import logger

from app.schemas import BacklinkSignal

class EnhancedFeatureExtractor:

    def __init__(self) -> None:
        pass

    def extract_temporal_features(
        self,
        backlink: BacklinkSignal,
        peers: List[BacklinkSignal]
    ) -> Dict[str, float]:
        features = {}

        if backlink.first_seen and backlink.last_seen:
            first_seen = backlink.first_seen
            last_seen = backlink.last_seen

            if first_seen.tzinfo is None:
                first_seen = first_seen.replace(tzinfo=timezone.utc)
            if last_seen.tzinfo is None:
                last_seen = last_seen.replace(tzinfo=timezone.utc)

            lifespan_days = (last_seen - first_seen).days

            if lifespan_days > 365:
                features['link_stability'] = 0.1
            elif lifespan_days < 30:
                features['link_stability'] = 0.8
            elif lifespan_days < 90:
                features['link_stability'] = 0.6
            else:
                features['link_stability'] = 0.3
        else:
            features['link_stability'] = 0.5

        if backlink.first_seen:
            first_seen = backlink.first_seen
            if first_seen.tzinfo is None:
                first_seen = first_seen.replace(tzinfo=timezone.utc)

            now = datetime.now(timezone.utc)
            days_ago = (now - first_seen).days

            same_period = 0
            for p in peers:
                if p.first_seen:
                    p_first_seen = p.first_seen
                    if p_first_seen.tzinfo is None:
                        p_first_seen = p_first_seen.replace(tzinfo=timezone.utc)
                    p_days_ago = (now - p_first_seen).days

                    if abs(days_ago - p_days_ago) <= 7:
                        same_period += 1

            temporal_clustering = same_period / max(len(peers), 1)
            features['temporal_clustering'] = min(temporal_clustering * 2, 1.0)
        else:
            features['temporal_clustering'] = 0.0

        return features

    def extract_graph_features(
        self,
        backlink: BacklinkSignal,
        peers: List[BacklinkSignal]
    ) -> Dict[str, float]:
        features = {}

        graph = defaultdict(set)
        for p in peers:
            if p.ip:
                graph[p.ip].add(p.domain_from if p.domain_from else str(p.source_url))

        if backlink.ip and backlink.ip in graph:
            neighbors = graph[backlink.ip]
            if len(neighbors) > 1:

                edges_between_neighbors = 0
                for neighbor1 in neighbors:
                    for neighbor2 in neighbors:
                        if neighbor1 != neighbor2:

                            for p in peers:
                                if p.domain_from == neighbor1 or str(p.source_url) == neighbor1:
                                    if p.ip:
                                        for p2 in peers:
                                            if (p2.domain_from == neighbor2 or str(p2.source_url) == neighbor2) and p2.ip == p.ip:
                                                edges_between_neighbors += 1
                                                break

                n = len(neighbors)
                max_possible_edges = n * (n - 1)
                if max_possible_edges > 0:
                    clustering_coefficient = (2 * edges_between_neighbors) / max_possible_edges
                    features['clustering_coefficient'] = min(clustering_coefficient, 1.0)
                else:
                    features['clustering_coefficient'] = 0.0
            else:
                features['clustering_coefficient'] = 0.0
        else:
            features['clustering_coefficient'] = 0.0

        total_ips = len(set(p.ip for p in peers if p.ip))
        total_domains = len(set(p.domain_from for p in peers if p.domain_from))

        if total_ips > 0 and total_domains > 0:

            density = len(graph) / max(total_ips * total_domains, 1)
            features['network_density'] = min(density * 10, 1.0)
        else:
            features['network_density'] = 0.0

        return features

    def extract_statistical_features(
        self,
        backlink: BacklinkSignal,
        peers: List[BacklinkSignal]
    ) -> Dict[str, float]:
        features = {}

        domain_ranks = [p.domain_rank for p in peers if p.domain_rank is not None]
        if domain_ranks and backlink.domain_rank:
            mean_rank = np.mean(domain_ranks)
            std_rank = np.std(domain_ranks)

            if std_rank > 0:
                z_score = abs((backlink.domain_rank - mean_rank) / std_rank)

                features['rank_z_score'] = min(z_score / 3.0, 1.0)
            else:
                features['rank_z_score'] = 0.0
        else:
            features['rank_z_score'] = 0.0

        domain_ages = [p.domain_age_days for p in peers if p.domain_age_days is not None]
        if domain_ages and backlink.domain_age_days:
            mean_age = np.mean(domain_ages)
            std_age = np.std(domain_ages)

            if std_age > 0:
                z_score = abs((backlink.domain_age_days - mean_age) / std_age)
                features['age_z_score'] = min(z_score / 3.0, 1.0)
            else:
                features['age_z_score'] = 0.0
        else:
            features['age_z_score'] = 0.0

        spam_scores = [p.backlink_spam_score for p in peers if p.backlink_spam_score is not None]
        if spam_scores and backlink.backlink_spam_score is not None:
            mean_spam = np.mean(spam_scores)
            std_spam = np.std(spam_scores)

            if std_spam > 0:
                z_score = abs((backlink.backlink_spam_score - mean_spam) / std_spam)
                features['spam_z_score'] = min(z_score / 3.0, 1.0)
            else:
                features['spam_z_score'] = 0.0
        else:
            features['spam_z_score'] = 0.0

        return features

    def extract_all_enhanced_features(
        self,
        backlink: BacklinkSignal,
        peers: List[BacklinkSignal]
    ) -> Dict[str, float]:
        features = {}

        try:
            temporal = self.extract_temporal_features(backlink, peers)
            features.update(temporal)
        except Exception as e:
            logger.warning("Temporal feature extraction failed", error=str(e))

        try:
            graph = self.extract_graph_features(backlink, peers)
            features.update(graph)
        except Exception as e:
            logger.warning("Graph feature extraction failed", error=str(e))

        try:
            statistical = self.extract_statistical_features(backlink, peers)
            features.update(statistical)
        except Exception as e:
            logger.warning("Statistical feature extraction failed", error=str(e))

        return features

enhanced_feature_extractor = EnhancedFeatureExtractor()
