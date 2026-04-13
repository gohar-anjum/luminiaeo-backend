FROM python:3.10-slim-bullseye
# IMPORTANT: Must use bullseye (Debian 11), NOT bookworm (Debian 12)
# Bookworm uses a newer glibc compiled with x86-64-v2 — will crash our server
# Bullseye uses older glibc that is safe for x86-64-v1 CPUs

# Install common system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    gcc \
    curl \
    build-essential \
    && rm -rf /var/lib/apt/lists/*

# CRITICAL: Install numpy FIRST, pinned to 1.24.x
# numpy 2.x and some 1.26+ wheels target x86-64-v2 — unsafe on our CPU; 1.24.x stays on v1-safe builds
RUN pip install --no-cache-dir "numpy>=1.24.0,<1.25.0"

# Verify numpy works on this CPU before continuing
RUN python -c "import numpy; print('numpy OK:', numpy.__version__)"

# CRITICAL: Pin pydantic-core BEFORE pydantic
# Newer pydantic-core builds are compiled with x86-64-v2 — must pin early
RUN pip install --no-cache-dir "pydantic-core>=2.5.0,<2.14.0"

# Install common packages shared across all Python microservices
RUN pip install --no-cache-dir \
    "fastapi>=0.104.0,<0.110.0" \
    "uvicorn>=0.25.0,<0.30.0" \
    "pydantic>=2.5.0,<2.10.0" \
    "requests>=2.28.0,<3.0.0" \
    "redis>=4.0.0,<5.0.0" \
    "python-dotenv>=0.21.0,<1.1.0" \
    "httpx>=0.24.0,<0.27.0"

# Verify ALL packages load cleanly on this CPU before shipping the image
RUN python -c "import numpy, fastapi, pydantic, uvicorn, httpx, redis; print('All packages OK')"

WORKDIR /app