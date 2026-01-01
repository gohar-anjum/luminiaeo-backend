# Docker Setup Guide

Complete containerization setup for LUMINI AEO Backend.

## Architecture

The project is containerized with the following services:

- **app** - Laravel PHP-FPM application
- **nginx** - Nginx web server
- **mysql** - MySQL 8.0 database
- **redis** - Redis for cache and queues
- **queue** - Laravel queue worker
- **clustering** - Python keyword clustering microservice
- **pbn-detector** - Python PBN detector microservice

## Prerequisites

- Docker Engine 20.10+
- Docker Compose 2.0+

## Quick Start

### 1. Environment Setup

Copy the Docker environment file:

```bash
cp .env.docker .env
```

Edit `.env` and configure:
- Database credentials
- API keys (Google Ads, OpenAI, etc.)
- Service URLs

### 2. Build and Start

```bash
# Build all containers
make build

# Start all services
make up

# Or use docker-compose directly
docker-compose up -d
```

### 3. Initial Setup

```bash
# Generate application key
make key-generate

# Run migrations
make migrate

# Or run everything at once
make setup
```

### 4. Access the Application

- **API**: http://localhost:8000
- **Health Check**: http://localhost:8000/api/health (if implemented)
- **Clustering Service**: http://localhost:8001/health
- **PBN Detector**: http://localhost:8002/health

## Development

### Using Make Commands

```bash
# View logs
make logs

# View app logs only
make logs-app

# Open shell in container
make shell

# Run tests
make test

# Clear caches
make cache-clear

# Optimize Laravel
make optimize
```

### Manual Docker Commands

```bash
# Start services
docker-compose up -d

# Stop services
docker-compose down

# View logs
docker-compose logs -f app

# Execute commands
docker-compose exec app php artisan migrate
docker-compose exec app composer install

# Rebuild after changes
docker-compose build --no-cache app
docker-compose up -d
```

## Service URLs (Internal)

When services communicate with each other, use these internal hostnames:

- **Database**: `mysql:3306`
- **Redis**: `redis:6379`
- **Clustering Service**: `clustering:8001`
- **PBN Detector**: `pbn-detector:8000`

## Environment Variables

Key environment variables for Docker:

```env
# Database
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=luminiaeo
DB_USERNAME=luminiaeo
DB_PASSWORD=password

# Redis
REDIS_HOST=redis
REDIS_PORT=6379

# Services
KEYWORD_CLUSTERING_SERVICE_URL=http://clustering:8001
PBN_DETECTOR_URL=http://pbn-detector:8000
```

## Volumes

Data persistence:

- `mysql_data` - MySQL database files
- `redis_data` - Redis data
- `./storage` - Laravel storage (logs, cache, etc.)

## Networking

All services are on the `luminiaeo-network` bridge network for internal communication.

## Production Considerations

### 1. Environment Variables

- Set `APP_ENV=production`
- Set `APP_DEBUG=false`
- Use strong passwords
- Secure all API keys

### 2. Security

- Use secrets management (Docker secrets, AWS Secrets Manager, etc.)
- Enable HTTPS with reverse proxy
- Restrict network access
- Use non-root users in containers

### 3. Performance

- Use production PHP-FPM settings
- Enable OPcache
- Use Redis for sessions and cache
- Configure Nginx caching
- Use CDN for static assets

### 4. Monitoring

- Add health checks
- Set up logging aggregation
- Monitor resource usage
- Set up alerts

## Troubleshooting

### Container won't start

```bash
# Check logs
docker-compose logs app

# Check if ports are in use
netstat -tulpn | grep 8000
```

### Database connection issues

```bash
# Check MySQL is running
docker-compose ps mysql

# Check connection
docker-compose exec app php artisan tinker
>>> DB::connection()->getPdo();
```

### Permission issues

```bash
# Fix storage permissions
docker-compose exec app chown -R www-data:www-data storage bootstrap/cache
docker-compose exec app chmod -R 775 storage bootstrap/cache
```

### Queue not processing

```bash
# Check queue worker
docker-compose logs queue

# Restart queue
docker-compose restart queue
```

## Development Override

For development with hot-reload and debugging:

```bash
docker-compose -f docker-compose.yml -f docker-compose.dev.yml up
```

## Cleanup

```bash
# Stop and remove containers
docker-compose down

# Remove volumes (WARNING: deletes data)
docker-compose down -v

# Remove images
docker-compose down --rmi all
```

## Additional Services

### Adding New Services

1. Add service to `docker-compose.yml`
2. Add to network: `luminiaeo-network`
3. Update `.env.docker` with connection details
4. Rebuild: `docker-compose build`

### Scaling Services

```bash
# Scale queue workers
docker-compose up -d --scale queue=3

# Scale clustering service
docker-compose up -d --scale clustering=2
```

## Notes

- First startup may take time (downloading images, installing dependencies)
- MySQL may take 30-60 seconds to be ready
- Python services download models on first run
- Storage directory must be writable by www-data user

