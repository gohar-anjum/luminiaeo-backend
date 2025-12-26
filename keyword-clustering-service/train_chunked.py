#!/usr/bin/env python3
"""
Chunked Training Script - Train with 2000 pairs in 2 iterations of 1000 pairs each
Optimized for minimal iterations with maximum efficiency
"""

import json
import os
import sys
import argparse
from pathlib import Path
from sentence_transformers import SentenceTransformer, InputExample, losses, evaluation
from sentence_transformers.evaluation import EmbeddingSimilarityEvaluator
from torch.utils.data import DataLoader
from sklearn.model_selection import train_test_split
import logging
from datetime import datetime
import random

os.environ['PYTHONUNBUFFERED'] = '1'
sys.stdout.reconfigure(line_buffering=True) if hasattr(sys.stdout, 'reconfigure') else None

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    stream=sys.stdout,
    force=True
)
logger = logging.getLogger(__name__)

def load_training_pairs(file_path: str):
    """Load training pairs from JSON file"""
    logger.info(f"Loading training pairs from {file_path}...")
    with open(file_path, 'r', encoding='utf-8') as f:
        data = json.load(f)

    if 'training_pairs' in data:
        pairs = data['training_pairs']
        logger.info(f"Loaded {len(pairs):,} training pairs")
        logger.info(f"  Positive: {data.get('positive_count', 0):,}")
        logger.info(f"  Negative: {data.get('negative_count', 0):,}")
        return pairs
    return data

def sample_pairs(pairs, total_pairs=2000):
    """Sample specified number of pairs from dataset"""
    if len(pairs) <= total_pairs:
        logger.info(f"Dataset has {len(pairs)} pairs, using all")
        return pairs
    
    logger.info(f"Sampling {total_pairs:,} pairs from {len(pairs):,} total pairs...")
    sampled = random.sample(pairs, total_pairs)
    logger.info(f"Sampled {len(sampled):,} pairs")
    return sampled

def split_into_chunks(pairs, chunk_size=1000):
    """Split pairs into chunks of specified size"""
    chunks = []
    for i in range(0, len(pairs), chunk_size):
        chunk = pairs[i:i + chunk_size]
        chunks.append(chunk)
        logger.info(f"Chunk {len(chunks)}: {len(chunk):,} pairs")
    return chunks

def prepare_examples(pairs):
    """Convert pairs to InputExample format"""
    examples = []
    for pair in pairs:
        if len(pair) == 3:
            keyword1, keyword2, similarity = pair
            examples.append(InputExample(texts=[keyword1, keyword2], label=float(similarity)))
        else:
            logger.warning(f"Invalid pair format: {pair}")
    return examples

def train_chunk(
    model,
    chunk_pairs,
    output_dir: str,
    chunk_num: int,
    total_chunks: int,
    train_ratio: float = 0.8,
    epochs: int = 1,  # 1 epoch per chunk for efficiency
    batch_size: int = 32,  # Larger batch size for efficiency
    learning_rate: float = 2e-5,
    warmup_steps: int = 50,  # Reduced warmup for smaller chunks
    evaluation_steps: int = 100,  # More frequent evaluation for smaller chunks
):
    """Train model on a single chunk"""
    logger.info("=" * 60)
    logger.info(f"Training Chunk {chunk_num}/{total_chunks}")
    logger.info("=" * 60)
    
    examples = prepare_examples(chunk_pairs)
    logger.info(f"Prepared {len(examples):,} examples from chunk")
    
    # Split into train/validation
    train_examples, val_examples = train_test_split(
        examples,
        train_size=train_ratio,
        random_state=42,
        shuffle=True
    )
    logger.info(f"Train: {len(train_examples):,}, Validation: {len(val_examples):,}")
    
    # Prepare data loaders
    train_dataloader = DataLoader(train_examples, shuffle=True, batch_size=batch_size)
    train_loss = losses.CosineSimilarityLoss(model)
    
    # Prepare evaluator
    evaluator = EmbeddingSimilarityEvaluator.from_input_examples(
        val_examples,
        name=f'validation_chunk_{chunk_num}'
    )
    
    logger.info(f"Training configuration:")
    logger.info(f"  Epochs: {epochs}")
    logger.info(f"  Batch size: {batch_size}")
    logger.info(f"  Learning rate: {learning_rate}")
    logger.info(f"  Warmup steps: {warmup_steps}")
    logger.info(f"  Evaluation steps: {evaluation_steps}")
    
    # Train on this chunk
    model.fit(
        train_objectives=[(train_dataloader, train_loss)],
        evaluator=evaluator,
        epochs=epochs,
        evaluation_steps=evaluation_steps,
        warmup_steps=warmup_steps,
        output_path=output_dir,  # Save after each chunk
        optimizer_params={'lr': learning_rate},
        show_progress_bar=sys.stdout.isatty(),
    )
    
    logger.info(f"✅ Chunk {chunk_num}/{total_chunks} training complete")
    return model

def train_model_chunked(
    training_pairs_file: str,
    output_dir: str = './models/custom-keyword-clustering',
    base_model: str = 'sentence-transformers/all-mpnet-base-v2',
    total_pairs: int = 2000,
    chunk_size: int = 1000,
    train_ratio: float = 0.8,
    epochs_per_chunk: int = 1,
    batch_size: int = 32,
    learning_rate: float = 2e-5,
    warmup_steps: int = 50,
    evaluation_steps: int = 100,
    resume: bool = False,
):
    """Train model using chunked approach"""
    start_time = datetime.now()
    logger.info("=" * 60)
    logger.info("Starting Chunked Keyword Clustering Model Training")
    logger.info(f"Total pairs: {total_pairs:,}, Chunk size: {chunk_size:,}")
    logger.info("=" * 60)
    
    # Load and sample pairs
    all_pairs = load_training_pairs(training_pairs_file)
    sampled_pairs = sample_pairs(all_pairs, total_pairs)
    
    # Split into chunks
    chunks = split_into_chunks(sampled_pairs, chunk_size)
    total_chunks = len(chunks)
    
    logger.info(f"Split into {total_chunks} chunks of ~{chunk_size:,} pairs each")
    
    # Load or initialize model
    if resume and os.path.exists(output_dir) and os.path.exists(os.path.join(output_dir, 'model.safetensors')):
        logger.info(f"Resuming training from: {output_dir}")
        model = SentenceTransformer(output_dir)
    else:
        logger.info(f"Loading base model: {base_model}...")
        model = SentenceTransformer(base_model)
        logger.info("Base model loaded successfully")
    
    # Train on each chunk
    for chunk_num, chunk_pairs in enumerate(chunks, 1):
        model = train_chunk(
            model=model,
            chunk_pairs=chunk_pairs,
            output_dir=output_dir,
            chunk_num=chunk_num,
            total_chunks=total_chunks,
            train_ratio=train_ratio,
            epochs=epochs_per_chunk,
            batch_size=batch_size,
            learning_rate=learning_rate,
            warmup_steps=warmup_steps,
            evaluation_steps=evaluation_steps,
        )
    
    # Save final model
    os.makedirs(output_dir, exist_ok=True)
    model.save(output_dir)
    
    logger.info("=" * 60)
    logger.info(f"✅ All chunks trained! Model saved to {output_dir}")
    logger.info("=" * 60)
    
    # Save metadata
    metadata = {
        'base_model': base_model,
        'total_pairs': total_pairs,
        'chunk_size': chunk_size,
        'total_chunks': total_chunks,
        'epochs_per_chunk': epochs_per_chunk,
        'batch_size': batch_size,
        'learning_rate': learning_rate,
        'train_ratio': train_ratio,
        'warmup_steps': warmup_steps,
        'evaluation_steps': evaluation_steps,
        'training_started': start_time.isoformat(),
        'training_completed': datetime.now().isoformat(),
        'training_duration_seconds': (datetime.now() - start_time).total_seconds(),
    }
    
    metadata_path = os.path.join(output_dir, 'training_metadata.json')
    with open(metadata_path, 'w') as f:
        json.dump(metadata, f, indent=2)
    
    logger.info(f"Training metadata saved to {metadata_path}")
    
    duration = datetime.now() - start_time
    logger.info(f"Total training time: {duration}")
    
    return True

def main():
    parser = argparse.ArgumentParser(
        description='Train custom keyword clustering model with chunked approach',
        formatter_class=argparse.ArgumentDefaultsHelpFormatter
    )
    parser.add_argument(
        'training_pairs',
        help='Path to training pairs JSON file',
        default='training_pairs.json',
        nargs='?'
    )
    parser.add_argument(
        '--output-dir',
        default='./models/custom-keyword-clustering',
        help='Directory to save trained model'
    )
    parser.add_argument(
        '--base-model',
        default='sentence-transformers/all-mpnet-base-v2',
        help='Base model to fine-tune'
    )
    parser.add_argument(
        '--total-pairs',
        type=int,
        default=2000,
        help='Total number of pairs to train on'
    )
    parser.add_argument(
        '--chunk-size',
        type=int,
        default=1000,
        help='Number of pairs per chunk'
    )
    parser.add_argument(
        '--epochs-per-chunk',
        type=int,
        default=1,
        help='Number of epochs per chunk'
    )
    parser.add_argument(
        '--batch-size',
        type=int,
        default=32,
        help='Training batch size'
    )
    parser.add_argument(
        '--learning-rate',
        type=float,
        default=2e-5,
        help='Learning rate'
    )
    parser.add_argument(
        '--train-ratio',
        type=float,
        default=0.8,
        help='Ratio of data for training (0-1)'
    )
    parser.add_argument(
        '--warmup-steps',
        type=int,
        default=50,
        help='Number of warmup steps per chunk'
    )
    parser.add_argument(
        '--evaluation-steps',
        type=int,
        default=100,
        help='Steps between evaluations'
    )
    parser.add_argument(
        '--resume',
        action='store_true',
        help='Resume training from checkpoint in output directory'
    )

    args = parser.parse_args()

    if not os.path.exists(args.training_pairs):
        logger.error(f"Training pairs file not found: {args.training_pairs}")
        return 1

    try:
        train_model_chunked(
            training_pairs_file=args.training_pairs,
            output_dir=args.output_dir,
            base_model=args.base_model,
            total_pairs=args.total_pairs,
            chunk_size=args.chunk_size,
            train_ratio=args.train_ratio,
            epochs_per_chunk=args.epochs_per_chunk,
            batch_size=args.batch_size,
            learning_rate=args.learning_rate,
            warmup_steps=args.warmup_steps,
            evaluation_steps=args.evaluation_steps,
            resume=args.resume,
        )
        return 0
    except Exception as e:
        logger.error(f"Training failed: {e}", exc_info=True)
        return 1

if __name__ == '__main__':
    exit(main())

