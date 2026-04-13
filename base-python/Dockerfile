FROM python:3.10-slim-bullseye

# libc6-dev replaces build-essential — avoids downloading libtsan0/libasan6
# which time out on your server. gcc + libc6-dev is all pip needs.
# --fix-missing handles any remaining flaky mirror connections
RUN apt-get update && apt-get install -y --no-install-recommends --fix-missing \
    gcc \
    curl \
    libc6-dev \
    && rm -rf /var/lib/apt/lists/*

# Install numpy first, pinned to avoid x86-64-v2 compiled wheels
RUN pip install --no-cache-dir "numpy>=1.24.0,<1.25.0"
RUN python -c "import numpy; print('numpy OK:', numpy.__version__)"

# Pin pydantic-core before pydantic — newer builds have x86-64-v2 wheels
RUN pip install --no-cache-dir "pydantic-core>=2.5.0,<2.14.0"

RUN pip install --no-cache-dir \
    "fastapi>=0.104.0,<0.110.0" \
    "uvicorn>=0.25.0,<0.30.0" \
    "pydantic>=2.5.0,<2.10.0" \
    "requests>=2.28.0,<3.0.0" \
    "redis>=4.0.0,<5.0.0" \
    "python-dotenv>=0.21.0,<1.1.0" \
    "httpx>=0.24.0,<0.27.0"

RUN python -c "import numpy, fastapi, pydantic, uvicorn, httpx, redis; print('All packages OK')"

WORKDIR /app
