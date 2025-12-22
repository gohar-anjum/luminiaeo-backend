import json
import csv
import argparse
import random
from pathlib import Path
from typing import List, Tuple

def load_json_data(file_path: str) -> List[dict]:
    with open(file_path, 'r', encoding='utf-8') as f:
        data = json.load(f)

    if 'pairs' in data:
        return data['pairs']
    return data

def load_csv_data(file_path: str) -> List[dict]:
    pairs = []
    with open(file_path, 'r', encoding='utf-8') as f:
        reader = csv.DictReader(f)
        for row in reader:
            pairs.append({
                'keyword1': row['keyword1'],
                'keyword2': row['keyword2'],
                'similarity': float(row['similarity']),
                'source': row.get('source', 'unknown')
            })
    return pairs

def filter_pairs(pairs: List[dict], min_similarity: float = 0.0, max_similarity: float = 1.0) -> List[dict]:
    return [
        pair for pair in pairs
        if min_similarity <= pair['similarity'] <= max_similarity
    ]

def balance_dataset(pairs: List[dict], positive_ratio: float = 0.5) -> List[dict]:
    positive = [p for p in pairs if p.get('similarity', 0) >= 0.5]
    negative = [p for p in pairs if p.get('similarity', 0) < 0.5]
    
    target_positive = int(len(pairs) * positive_ratio)
    target_negative = len(pairs) - target_positive
    
    if len(positive) > target_positive:
        positive = positive[:target_positive]
    if len(negative) > target_negative:
        negative = negative[:target_negative]
    
    balanced = positive + negative
    random.shuffle(balanced)
    return balanced