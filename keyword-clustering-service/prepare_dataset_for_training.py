import json
import argparse
from collections import defaultdict
from typing import List, Tuple, Dict
import random
from pathlib import Path

def load_dataset(file_path: str, max_items: int = None, sample: bool = False) -> List[Dict]:
    with open(file_path, 'r', encoding='utf-8') as f:
        data = json.load(f)
    
    if isinstance(data, dict) and 'pairs' in data:
        pairs = data['pairs']
    elif isinstance(data, list):
        pairs = data
    else:
        pairs = []
    
    if max_items and len(pairs) > max_items:
        if sample:
            pairs = random.sample(pairs, max_items)
        else:
            pairs = pairs[:max_items]
    
    return pairs