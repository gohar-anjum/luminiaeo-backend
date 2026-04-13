#!/bin/bash
set -e

echo "================================================"
echo " Building luminiaeo-base-python base image..."
echo "================================================"
docker build -t luminiaeo-base-python ./base-python

echo ""
echo "================================================"
echo " Verifying base image CPU compatibility..."
echo "================================================"
docker run --rm luminiaeo-base-python python -c "
import numpy
print('numpy version:', numpy.__version__)
print('CPU compatibility: OK')
"

echo ""
echo "================================================"
echo " Building all services..."
echo "================================================"
docker compose build --no-cache clustering
docker compose build --no-cache keyword-intent
docker compose build --no-cache page-analysis
docker compose build --no-cache pbn-detector

echo ""
echo "================================================"
echo " Starting all services..."
echo "================================================"
docker compose up -d

echo ""
echo "================================================"
echo " Checking service health..."
echo "================================================"
sleep 10
docker compose ps
echo ""
echo "Build complete."
