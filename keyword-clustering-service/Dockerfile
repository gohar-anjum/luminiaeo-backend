FROM python:3.11-slim

WORKDIR /app

RUN apt-get update && apt-get install -y \
    gcc \
    curl \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .

RUN pip install --upgrade pip

RUN pip install --no-cache-dir --index-url https://download.pytorch.org/whl/cpu torch>=2.2.0 torchvision

RUN pip install --no-cache-dir --default-timeout=1000 --retries 5 --find-links https://download.pytorch.org/whl/cpu -r requirements.txt

RUN mkdir -p /app/models /app/data

COPY app/ ./app/

COPY prepare_dataset_for_training.py prepare_training_data.py train_model_complete.py train_chunked.py ./

RUN chmod +x prepare_dataset_for_training.py prepare_training_data.py train_model_complete.py train_chunked.py

EXPOSE 8001

HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD python -c "import requests; requests.get('http://localhost:8001/health').raise_for_status()" || exit 1

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001", "--workers", "2"]
