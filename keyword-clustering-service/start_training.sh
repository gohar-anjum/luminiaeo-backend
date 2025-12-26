#!/bin/bash
# Quick training script for 2000 pairs in 2 chunks of 1000

set -e

echo "=========================================="
echo "Keyword Clustering Model Training"
echo "2000 pairs, 2 chunks of 1000 each"
echo "=========================================="

cd "$(dirname "$0")"

# Check if training data exists
if [ ! -f "training_pairs_2000.json" ]; then
    echo "❌ training_pairs_2000.json not found"
    echo "Creating it from training_pairs.json..."
    python3 -c "
import json
import random

with open('training_pairs.json', 'r') as f:
    data = json.load(f)
pairs = data.get('training_pairs', data)

if len(pairs) > 2000:
    sampled = random.sample(pairs, 2000)
else:
    sampled = pairs

output = {
    'training_pairs': sampled,
    'total_pairs': len(sampled),
    'positive_count': sum(1 for p in sampled if len(p) == 3 and p[2] >= 0.5),
    'negative_count': sum(1 for p in sampled if len(p) == 3 and p[2] < 0.5)
}

with open('training_pairs_2000.json', 'w') as f:
    json.dump(output, f, indent=2)

print(f'Created training_pairs_2000.json with {len(sampled)} pairs')
"
fi

echo ""
echo "Starting training with Docker..."
echo ""

# Run training in Docker
# Note: keyword-clustering-service is mounted to /app/scripts
docker compose -f ../docker-compose.yml run --rm clustering-train \
    python train_chunked.py \
    /app/scripts/training_pairs_2000.json \
    --total-pairs 2000 \
    --chunk-size 1000 \
    --epochs-per-chunk 1 \
    --batch-size 32 \
    --learning-rate 2e-5 \
    --output-dir /app/models/custom-keyword-clustering

echo ""
echo "=========================================="
echo "✅ Training complete!"
echo "Model saved to: ./models/custom-keyword-clustering"
echo "=========================================="

