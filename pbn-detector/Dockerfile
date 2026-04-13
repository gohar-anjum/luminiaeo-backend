FROM luminiaeo-base-python
WORKDIR /app

# Install Poetry
RUN pip install poetry

# Copy dependency files
COPY pyproject.toml poetry.lock* ./

# CRITICAL: Force numpy<2.0.0 BEFORE poetry installs anything
# Poetry will pull numpy 2.x as a transitive dep via sentence-transformers/transformers
# Pre-installing it here forces Poetry to reuse the already-installed compatible version
RUN pip install --no-cache-dir "numpy>=1.24.0,<2.0.0"

# Configure poetry and install dependencies
RUN poetry config virtualenvs.create false \
    && poetry config installer.max-workers 1 \
    && poetry install --without dev --no-interaction --no-ansi --no-root

# Verify numpy wasn't overwritten by poetry with an x86-64-v2 wheel
RUN python -c "import numpy; print('numpy OK:', numpy.__version__)"

# Copy application code
COPY app/ ./app/
COPY scripts/ ./scripts/

EXPOSE 8081

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8081"]
