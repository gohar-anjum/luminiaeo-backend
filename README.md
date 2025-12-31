# Luminiaeo Backend

Enterprise-grade Laravel backend application for comprehensive SEO management with AI-powered keyword research, citation analysis, backlink management, and FAQ generation.

## 🏗️ Architecture Overview

This application follows a **microservices architecture** with the following components:

- **Laravel API** (PHP 8.2) - Main application backend and API gateway
- **Keyword Clustering Service** (Python/FastAPI) - Semantic keyword clustering using ML embeddings
- **PBN Detector Service** (Python/FastAPI) - Private Blog Network detection with ML models
- **MySQL 8.0** - Primary database with comprehensive indexing
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
APP_KEY=                    # Generate: php artisan key:generate
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
REDIS_DB=0
REDIS_URL=redis://redis:6379/0

# Queue
QUEUE_CONNECTION=redis

# DataForSEO API (Required)
DATAFORSEO_LOGIN=your_login
DATAFORSEO_PASSWORD=your_password
DATAFORSEO_BASE_URL=https://api.dataforseo.com/v3
DATAFORSEO_TIMEOUT=60

# DataForSEO Limits (Configurable)
DATAFORSEO_SEARCH_VOLUME_MAX_KEYWORDS=100
DATAFORSEO_BACKLINKS_DEFAULT_LIMIT=100
DATAFORSEO_BACKLINKS_MAX_LIMIT=1000
DATAFORSEO_KEYWORDS_FOR_SITE_DEFAULT_LIMIT=100
DATAFORSEO_KEYWORDS_FOR_SITE_MAX_LIMIT=1000
DATAFORSEO_KEYWORD_IDEAS_DEFAULT_LIMIT=100
DATAFORSEO_KEYWORD_IDEAS_MAX_LIMIT=1000
DATAFORSEO_CITATION_MAX_DEPTH=100
DATAFORSEO_CITATION_DEFAULT_DEPTH=10
DATAFORSEO_CITATION_CHUNK_SIZE=25
DATAFORSEO_CITATION_MAX_QUERIES=5000
DATAFORSEO_CITATION_ENABLED=true
DATAFORSEO_MAX_CONCURRENT_REQUESTS=5

# Microservices
KEYWORD_CLUSTERING_SERVICE_URL=http://clustering:8001
KEYWORD_CLUSTERING_TIMEOUT=120
CLUSTERING_MAX_KEYWORDS=1000

PBN_DETECTOR_URL=http://pbn-detector:8081
PBN_DETECTOR_TIMEOUT=30
PBN_DETECTOR_SECRET=your_secret_key
PBN_MAX_BACKLINKS=1000
PBN_HIGH_RISK_THRESHOLD=0.75
PBN_MEDIUM_RISK_THRESHOLD=0.5
PBN_PARALLEL_THRESHOLD=50
PBN_PARALLEL_WORKERS=4

# LLM Services (For Citation & FAQ)
OPENAI_API_KEY=your_openai_key
OPENAI_API_BASE_URL=https://api.openai.com/v1
GOOGLE_API_KEY=your_google_api_key

# Citation Analysis
CITATION_DEFAULT_QUERIES=5000
CITATION_MAX_QUERIES_PER_TASK=5000
CITATION_CHUNK_SIZE=25
CITATION_OPENAI_MODEL=gpt-4o
CITATION_GEMINI_MODEL=gemini-2.0-pro-exp-02-05

# FAQ Generation
FAQ_GENERATOR_TIMEOUT=60
FAQ_DEFAULT_LANGUAGE=en
FAQ_DEFAULT_LOCATION=2840

# External APIs
SERP_API_KEY=your_serp_api_key
ALSOASKED_API_KEY=your_alsoasked_key
WHOISXML_API_KEY=your_whoisxml_key
SAFE_BROWSING_API_KEY=your_safe_browsing_key

# Google Ads (Optional)
GOOGLE_ADS_CLIENT_ID=
GOOGLE_ADS_CLIENT_SECRET=
GOOGLE_ADS_REFRESH_TOKEN=
GOOGLE_ADS_DEVELOPER_TOKEN=
GOOGLE_ADS_LOGIN_CUSTOMER_ID=
GOOGLE_ADS_REDIRECT_URI=

# Cache Lock Timeouts
CACHE_LOCK_KEYWORD_RESEARCH_TIMEOUT=10
CACHE_LOCK_SEARCH_VOLUME_TIMEOUT=30
CACHE_LOCK_CITATIONS_TIMEOUT=60
CACHE_LOCK_FAQ_TIMEOUT=120
```

> **Note:** See `.env.example` for complete list of all environment variables with descriptions.

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

## 📦 Core Components

### Laravel Application (Main Backend)
- **RESTful API** with Sanctum authentication
- **Service Layer Architecture** - Business logic separation
- **Repository Pattern** - Data access abstraction
- **Queue-based Processing** - Async job handling
- **Dual Caching Strategy** - Database + Redis caching
- **Comprehensive Indexing** - Optimized database queries

### Keyword Clustering Microservice
- **Technology**: Python 3.11, FastAPI, Sentence Transformers, scikit-learn
- **Features**: Semantic embeddings (MPNet), K-Means clustering, automatic labeling
- **Endpoints**: `POST /cluster`, `GET /health`
- **Caching**: Redis-based embedding cache
- **Configurable**: Request size limits, model selection

### PBN Detector Microservice
- **Technology**: Python, FastAPI, Machine Learning
- **Features**: PBN detection, network analysis, ML-based classification
- **Endpoints**: `POST /detect`, `GET /health`
- **Configurable**: Risk thresholds, parallel processing, request limits

## 🗄️ Database Schema

### Key Tables

- **keyword_research_jobs** - Research job tracking with user ownership
- **keywords** - Keyword data with clustering relationships
- **keyword_clusters** - Semantic keyword clusters
- **keyword_cache** - Cached search volume and keyword data
- **citation_tasks** - Citation analysis tasks with progress tracking
- **backlinks** - Backlink data with PBN detection results
- **pbn_detections** - PBN detection results and risk levels
- **seo_tasks** - SEO task tracking (backlinks, etc.)
- **faqs** - Generated FAQ data with caching
- **projects** - User projects
- **users** - User accounts

### Performance Optimizations

Comprehensive indexing strategy includes:
- Composite indexes for common query patterns
- User-based queries (user_id indexes)
- Status-based filtering (status indexes)
- Domain and URL lookups
- Task tracking (task_id indexes)
- Temporal queries (created_at, updated_at indexes)

All indexes are applied via migrations:
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

## 🎯 Main Features

### 1. Keyword Research
Comprehensive keyword discovery and analysis with:
- Multi-source keyword collection (Google Keyword Planner, DataForSEO, scrapers)
- Semantic clustering using ML embeddings
- AI intent scoring for keyword visibility
- Search volume, competition, and CPC data
- Asynchronous job-based processing

**Endpoints:**
- `POST /api/keyword-research` - Create research job
- `GET /api/keyword-research` - List jobs (paginated)
- `GET /api/keyword-research/{id}/status` - Get job status
- `GET /api/keyword-research/{id}/results` - Get results

### 2. Search Volume Lookup
Real-time search volume data retrieval with:
- Batch keyword processing (up to 100 keywords per request)
- Dual caching strategy (database + in-memory)
- Request deduplication
- Partial results handling

**Endpoints:**
- `POST /api/seo/keywords/search-volume` - Get search volumes for keywords

### 3. Keywords for Site
Domain-based keyword discovery with:
- Keywords ranking for target domain
- Historical data analysis
- SERP information integration
- Configurable result limits

**Endpoints:**
- `POST /api/keyword-planner/for-site` - Get keywords for domain
- `POST /api/seo/keywords/for-site` - Alternative endpoint

### 4. Citation Analysis
AI-powered citation and competitor analysis with:
- Automated query generation using LLM
- Multi-provider citation checking (DataForSEO SERP analysis)
- Competitor identification and analysis
- Chunk-based async processing
- Progress tracking and retry mechanisms

**Endpoints:**
- `POST /api/citations/analyze` - Analyze URL for citations
- `GET /api/citations/status/{taskId}` - Get task status
- `GET /api/citations/results/{taskId}` - Get results
- `POST /api/citations/retry/{taskId}` - Retry failed queries

### 5. Backlinks Analysis with PBN Detection
Comprehensive backlink analysis with:
- Backlink data collection from DataForSEO
- Private Blog Network (PBN) detection using ML
- Spam score calculation
- Safe Browsing integration
- WHOIS domain analysis
- Asynchronous processing

**Endpoints:**
- `POST /api/seo/backlinks/submit` - Submit backlink analysis
- `POST /api/seo/backlinks/results` - Get results
- `POST /api/seo/backlinks/status` - Get task status
- `POST /api/seo/backlinks/harmful` - Get harmful backlinks

### 6. FAQ Generation
AI-powered FAQ generation with:
- URL or topic-based generation
- Multi-source question collection (SERP, AlsoAsked API)
- LLM-powered answer generation (OpenAI GPT-4o, Google Gemini)
- SEO-optimized FAQ creation
- Caching to avoid duplicate API calls

**Endpoints:**
- `POST /api/faq/generate` - Generate FAQs from URL or topic
- `POST /api/faq/task` - Create FAQ generation task
- `GET /api/faq/task/{taskId}` - Get task status

### Authentication

```
POST /api/login
POST /api/register
GET  /api/user (protected)
GET  /api/health (health check)
```

## 🔐 Security Features

### Implemented Security Measures

1. **Authentication**: Laravel Sanctum token-based API authentication
2. **Authorization**: Resource ownership validation (users can only access their own data)
3. **Input Sanitization**: Automatic sanitization of URLs, domains, and user inputs
4. **Security Headers**: X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, HSTS
5. **Rate Limiting**: Configurable rate limits on all endpoints
6. **SQL Injection Prevention**: Eloquent ORM with parameterized queries
7. **XSS Protection**: Input sanitization and output escaping
8. **Request Validation**: Comprehensive FormRequest validation classes

### Security Best Practices

- Use strong database passwords
- Rotate API keys and secrets regularly
- Enable HTTPS in production
- Store all secrets in environment variables (never commit to git)
- Regularly update dependencies
- Monitor failed authentication attempts
- Use secure session configuration

## 📈 Performance Features

### Caching Strategy

1. **Dual Caching**: Database cache (persistent) + Redis cache (in-memory)
2. **Keyword Cache**: Cached search volume data with configurable TTL
3. **Embedding Cache**: Redis-based caching for ML embeddings
4. **Request Deduplication**: Prevents duplicate processing of identical requests
5. **URL Content Cache**: Cached fetched URL content for FAQ generation

### Database Optimization

- **Comprehensive Indexing**: Composite indexes for all common query patterns
- **Eager Loading**: Prevents N+1 queries with relationship preloading
- **Bulk Operations**: Batch inserts and updates for efficiency
- **Query Optimization**: Selective column loading, query scopes

### Code Optimizations

- **Parallel Processing**: HTTP pool for concurrent API calls
- **Async Jobs**: Queue-based processing for long-running tasks
- **Batch Processing**: Chunk-based processing for large datasets
- **Transaction Management**: Atomic operations with proper boundaries
- **Pagination**: All list endpoints support pagination

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

- **Laravel API**: `GET /api/health` - Checks database, cache, and Redis connectivity
- **Keyword Clustering**: `GET http://localhost:8001/health` - Service and Redis status
- **PBN Detector**: `GET http://localhost:8002/health` (internal: `http://pbn-detector:8081/health`) - Service health check

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

## 📚 Documentation

### Technical Documentation

- **`TECHNICAL_SYSTEM_FLOW.md`** - Comprehensive technical documentation covering:
  - Complete feature flows (frontend → backend → database)
  - API endpoints with request/response examples
  - Service layer architecture
  - Data access patterns
  - External integrations
  - Architectural audits and improvements
  - All implemented fixes and optimizations

### External Resources

- [Laravel Documentation](https://laravel.com/docs)
- [FastAPI Documentation](https://fastapi.tiangolo.com/)
- [Laravel Horizon](https://laravel.com/docs/horizon)
- [DataForSEO API Docs](https://docs.dataforseo.com/)

## 🤝 Contributing

1. Follow PSR-12 coding standards
2. Write tests for new features
3. Update documentation
4. Use meaningful commit messages

## 📝 License

[Your License Here]

## 🆘 Troubleshooting

### Common Issues

**Service Not Responding:**
```bash
# Check service health
curl http://localhost:8000/api/health
curl http://localhost:8001/health
curl http://localhost:8000/health

# Check logs
docker-compose logs -f [service_name]
```

**Queue Jobs Not Processing:**
```bash
# Check Horizon dashboard
http://localhost:8000/horizon

# Restart queue worker
docker-compose restart queue
```

**Database Connection Issues:**
```bash
# Test database connection
docker-compose exec app php artisan tinker
>>> DB::connection()->getPdo();

# Check migrations
docker-compose exec app php artisan migrate:status
```

### Support Resources

- Check application logs: `docker-compose logs -f app`
- Review `TECHNICAL_SYSTEM_FLOW.md` for detailed system documentation
- Check service health endpoints
- Verify environment variables are set correctly

---

## 🎯 System Status

✅ **Production Ready** - All critical improvements implemented:
- Security enhancements (ownership validation, input sanitization, security headers)
- Performance optimizations (indexing, parallel processing, caching, pagination)
- Code quality improvements (dependency injection, configuration management)
- Resilience features (circuit breakers, error handling, health checks)
- Scalability features (pagination, batch processing, configurable limits)

**Built with ❤️ using Laravel, FastAPI, and modern microservices architecture**

