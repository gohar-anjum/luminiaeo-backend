# Luminiaeo Backend

Enterprise-grade Laravel backend application for SEO keyword research, citation analysis, and backlink management with microservices architecture.

## 🏗️ Architecture Overview

This application follows a **microservices architecture** with the following components:

- **Laravel API** (PHP 8.2) - Main application backend
- **Keyword Clustering Service** (Python/FastAPI) - Semantic keyword clustering using ML
- **PBN Detector Service** (Python/FastAPI) - Private Blog Network detection
- **MySQL 8.0** - Primary database
- **Redis 7** - Caching and queue management
- **Laravel Horizon** - Queue dashboard and supervisor
- **Nginx** - Web server and reverse proxy

## 🚀 Quick Start with Docker

### Prerequisites

- Docker & Docker Compose installed
- At least 4GB RAM available
- Ports 8000, 8001, 8002, 3306, 6379 available

### Single Command Setup

```bash
# Clone and navigate to project
cd /var/www/luminiaeo-backend

# Copy environment file (if not exists)
cp .env.example .env

# Start all services
docker-compose up -d

# View logs
docker-compose logs -f
```

The application will be available at:
- **API**: http://localhost:8000
- **Horizon Dashboard**: http://localhost:8000/horizon
- **Clustering Service**: http://localhost:8001
- **PBN Detector**: http://localhost:8002

### Environment Configuration

Create a `.env` file with the following essential variables:

```env
# Application
APP_NAME=Luminiaeo
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=luminiaeo
DB_USERNAME=luminiaeo
DB_PASSWORD=password

# Redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null

# Queue
QUEUE_CONNECTION=redis

# Services
KEYWORD_CLUSTERING_SERVICE_URL=http://clustering:8001
KEYWORD_CLUSTERING_TIMEOUT=120
PBN_DETECTOR_URL=http://pbn-detector:8000

# API Keys (Add your keys)
DATAFORSEO_LOGIN=
DATAFORSEO_PASSWORD=
SERP_API_KEY=
GOOGLE_ADS_CLIENT_ID=
GOOGLE_ADS_CLIENT_SECRET=
GOOGLE_ADS_REFRESH_TOKEN=
GOOGLE_ADS_DEVELOPER_TOKEN=
```

### First Run Setup

```bash
# Generate application key
docker-compose exec app php artisan key:generate

# Run migrations
docker-compose exec app php artisan migrate

# Create admin user (if seeder exists)
docker-compose exec app php artisan db:seed

# Cache configuration (production)
docker-compose exec app php artisan config:cache
docker-compose exec app php artisan route:cache
```

## 📦 Services & Components

### 1. Laravel Application

**Location**: Root directory

**Features**:
- RESTful API with Sanctum authentication
- Keyword research orchestration
- Citation analysis
- Backlink management
- Queue-based job processing
- Redis caching layer

**Key Directories**:
- `app/Http/Controllers/Api/` - API controllers
- `app/Services/` - Business logic services
- `app/Repositories/` - Data access layer
- `app/Jobs/` - Queue jobs
- `app/Models/` - Eloquent models

### 2. Keyword Clustering Microservice

**Location**: `keyword-clustering-service/`

**Technology**: Python 3.11, FastAPI, Sentence Transformers, scikit-learn

**Features**:
- Semantic keyword embeddings using MPNet
- K-Means clustering on embeddings
- Automatic cluster labeling
- Custom model training support

**API Endpoints**:
- `POST /cluster` - Cluster keywords
- `GET /health` - Health check

**Training Custom Model** (Docker-based, non-interactive):

All training is done via Docker - no virtual environments or Jupyter needed:

```bash
# From project root directory

# Step 1: Prepare training data from 10M dataset
docker-compose run --rm clustering-train python prepare_dataset_for_training.py \
    /app/data/dataset_10000000_pairs.json \
    --output /app/data/training_pairs.json \
    --target-pairs 2000000

# Step 2: Train model (non-interactive)
docker-compose run --rm clustering-train python train_model_complete.py \
    /app/data/training_pairs.json \
    --output-dir /app/models/custom-keyword-clustering

# Step 3: Restart service to load new model
docker-compose restart clustering
```

See `keyword-clustering-service/TRAINING_GUIDE.md` for complete documentation.

### 3. PBN Detector Microservice

**Location**: `pbn-detector/`

**Technology**: Python, FastAPI

**Features**:
- Private Blog Network detection
- Domain analysis
- Redis caching

## 🗄️ Database Schema

### Key Tables

- **keywords** - Keyword research data
- **keyword_research_jobs** - Research job tracking
- **keyword_clusters** - Clustered keyword groups
- **keyword_cache** - Cached keyword data
- **citation_tasks** - Citation analysis tasks
- **backlinks** - Backlink data
- **projects** - User projects
- **users** - User accounts

### Performance Optimizations

The database includes optimized indexes for:
- Keyword searches by job, cluster, source
- Research job queries by user and status
- Citation task lookups
- Backlink domain queries

Run migrations to apply indexes:
```bash
docker-compose exec app php artisan migrate
```

## 🔧 Development

### Local Development Setup

```bash
# Install PHP dependencies
composer install

# Install Node dependencies
npm install

# Copy environment
cp .env.example .env
php artisan key:generate

# Run migrations
php artisan migrate

# Start development server
php artisan serve

# Start queue worker
php artisan queue:work

# Start Horizon
php artisan horizon
```

### Code Structure & Best Practices

#### Controllers
- Use FormRequest classes for validation
- Keep controllers thin, delegate to services
- Use `HasApiResponse` trait for consistent responses

#### Services
- Business logic lives in services
- Services are injected via constructor
- Use DTOs for data transfer

#### Repositories
- Data access layer abstraction
- Implement interfaces for testability
- Use query scopes for reusable queries

#### Models
- Use Eloquent relationships
- Define query scopes for common queries
- Use casts for JSON/array fields

#### Traits
- `HasCacheable` - Caching utilities
- `HasTimestamps` - Timestamp helpers
- `HasApiResponse` - API response helpers

### Testing

```bash
# Run tests
php artisan test

# Run with coverage
php artisan test --coverage
```

## 📊 Queue & Jobs

### Queue Configuration

The application uses Redis for queue management with Laravel Horizon for monitoring.

**Queue Workers**:
- `queue` - Standard queue worker
- `horizon` - Horizon supervisor (recommended for production)

### Key Jobs

- `ProcessKeywordResearchJob` - Orchestrates keyword research
- `ProcessKeywordIntentJob` - Scores keyword intent
- `ProcessCitationTaskJob` - Processes citation analysis
- `FetchBacklinksResultsJob` - Fetches backlink data

### Monitoring

Access Horizon dashboard at: `http://localhost:8000/horizon`

## 🎯 API Endpoints

### Authentication

```
POST /api/login
POST /api/register
GET  /api/user (protected)
```

### Keyword Research

```
POST   /api/keyword-research          - Create research job
GET    /api/keyword-research          - List jobs
GET    /api/keyword-research/{id}/status  - Get job status
GET    /api/keyword-research/{id}/results - Get results
```

### Citations

```
POST   /api/citations/analyze         - Analyze URL
GET    /api/citations/status/{id}    - Get status
GET    /api/citations/results/{id}   - Get results
POST   /api/citations/retry/{id}    - Retry failed queries
```

### SEO Data

```
POST   /api/seo/keywords/search-volume - Get search volumes
POST   /api/seo/backlinks/submit      - Submit backlink analysis
POST   /api/serp/keywords             - Get SERP keyword data
```

## 🔐 Security

### Best Practices Implemented

1. **Authentication**: Laravel Sanctum for API tokens
2. **Validation**: FormRequest classes with comprehensive rules
3. **Rate Limiting**: Throttle middleware on sensitive endpoints
4. **SQL Injection**: Eloquent ORM prevents SQL injection
5. **XSS Protection**: Blade templating escapes output
6. **CSRF Protection**: Enabled for web routes

### Security Recommendations

- Use strong database passwords
- Rotate API keys regularly
- Enable HTTPS in production
- Use environment variables for secrets
- Regularly update dependencies

## 📈 Performance Optimization

### Caching Strategy

1. **Redis Cache**: Application-level caching
2. **Keyword Cache**: Cached keyword data with TTL
3. **Clustering Cache**: Cached clustering results
4. **Query Result Cache**: Cached expensive queries

### Database Optimization

- Indexes on frequently queried columns
- Eager loading to prevent N+1 queries
- Batch inserts for bulk operations
- Query optimization with select specific columns

### Code Optimizations

- Batch processing for large datasets
- Queue jobs for long-running tasks
- Lazy loading relationships
- Database transactions for consistency

## 🐳 Docker Commands

```bash
# Start services
docker-compose up -d

# Stop services
docker-compose down

# View logs
docker-compose logs -f [service_name]

# Execute commands
docker-compose exec app php artisan [command]
docker-compose exec clustering python [script]

# Rebuild services
docker-compose build [service_name]

# Restart service
docker-compose restart [service_name]

# View service status
docker-compose ps
```

## 🔍 Monitoring & Debugging

### Logs

```bash
# Application logs
docker-compose exec app tail -f storage/logs/laravel.log

# Service logs
docker-compose logs -f clustering
docker-compose logs -f queue
```

### Health Checks

- **Laravel**: `http://localhost:8000/api/health` (if implemented)
- **Clustering**: `http://localhost:8001/health`
- **PBN Detector**: `http://localhost:8000/health`

### Database

```bash
# Connect to MySQL
docker-compose exec mysql mysql -u luminiaeo -p luminiaeo

# Connect to Redis
docker-compose exec redis redis-cli
```

## 🚢 Production Deployment

### Pre-Deployment Checklist

1. ✅ Set `APP_ENV=production`
2. ✅ Set `APP_DEBUG=false`
3. ✅ Generate `APP_KEY`
4. ✅ Configure database credentials
5. ✅ Set up Redis password
6. ✅ Configure API keys
7. ✅ Run migrations
8. ✅ Cache configuration
9. ✅ Set up SSL certificates
10. ✅ Configure backup strategy

### Production Commands

```bash
# Optimize for production
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Run migrations
php artisan migrate --force

# Start Horizon
php artisan horizon
```

### Scaling

- **Horizontal Scaling**: Run multiple queue workers
- **Database**: Use read replicas for read-heavy operations
- **Redis**: Use Redis Cluster for high availability
- **Load Balancing**: Use Nginx/HAProxy for multiple app instances

## 📚 Additional Resources

### Documentation

- [Laravel Documentation](https://laravel.com/docs)
- [FastAPI Documentation](https://fastapi.tiangolo.com/)
- [Laravel Horizon](https://laravel.com/docs/horizon)

### Training Data

To train the clustering model with your data:

1. Export keyword clusters from production
2. Prepare training pairs using `prepare_training_data.py`
3. Train model using `train_model.py`
4. Deploy model to `keyword-clustering-service/models/`

## 🤝 Contributing

1. Follow PSR-12 coding standards
2. Write tests for new features
3. Update documentation
4. Use meaningful commit messages

## 📝 License

[Your License Here]

## 🆘 Support

For issues and questions:
- Check logs: `docker-compose logs`
- Review documentation
- Check service health endpoints

---

**Built with ❤️ using Laravel, FastAPI, and modern microservices architecture**

