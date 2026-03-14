# Architecture

## System Overview

Zoho-Parasut-Sync is a PHP web application that bridges **Zoho CRM** and **Paraşüt** (Turkish accounting software). It runs as a self-hosted panel with a browser-based admin interface.

```
┌─────────────────────────────────────────────────────────┐
│                    Admin Dashboard                       │
│  (index.php, settings.php, products_comparison.php...)   │
└──────────────────────┬──────────────────────────────────┘
                       │
              ┌────────▼────────┐
              │   Controllers   │
              │ (MVC Handlers)  │
              └────────┬────────┘
                       │
         ┌─────────────┼─────────────┐
         ▼             ▼             ▼
  ┌────────────┐ ┌──────────┐ ┌────────────┐
  │ ZohoService│ │SyncService│ │ParasutSvc  │
  │  (API)     │ │ (Logic)  │ │  (API)     │
  └──────┬─────┘ └─────┬────┘ └──────┬─────┘
         │             │             │
         ▼             ▼             ▼
  ┌──────────┐  ┌────────────┐  ┌──────────┐
  │ Zoho CRM │  │  MySQL DB  │  │ Paraşüt  │
  │   API    │  │            │  │   API    │
  └──────────┘  └────────────┘  └──────────┘
```

## Directory Structure

```
├── bootstrap.php              # Central initialization, session, autoloading
├── api_handler.php            # API route dispatcher (Router → Controller)
├── install.php                # Browser-based setup wizard (3 steps)
│
├── classes/                   # Core service classes
│   ├── ZohoService.php        # Zoho CRM API client (OAuth2, CRUD, rate limiting)
│   ├── ParasutService.php     # Paraşüt API client (OAuth2, products, invoices)
│   ├── SyncService.php        # Business logic: compare, diff, sync operations
│   ├── Queue.php              # Job queue processing (async operations)
│   └── Router.php             # HTTP route matching and dispatching
│
├── controllers/               # Request handlers (one per feature)
│   ├── BaseController.php     # Shared controller logic
│   ├── DashboardController.php
│   ├── ProductController.php  # Product CRUD, sync, merge, code update
│   ├── InvoiceController.php  # Invoice fetch, sync, comparison
│   ├── PurchaseOrderController.php
│   ├── MergeController.php    # Duplicate detection, smart merge
│   ├── SettingsController.php # API credentials, tax mapping
│   ├── SyncController.php     # Bulk sync operations
│   └── SystemController.php   # Queue, logs, cron management
│
├── config/
│   ├── database.php           # PDO connection factory
│   ├── db_config.php          # .env file parser → DB credentials
│   ├── ServiceFactory.php     # Dependency injection container
│   └── helpers/               # Pure utility functions
│       ├── http.php           # cURL wrapper with retry logic
│       ├── security.php       # CSRF tokens, authentication
│       ├── logging.php        # Structured logging with token masking
│       ├── settings.php       # Whitelisted settings CRUD
│       ├── repository.php     # Database query helpers
│       ├── turkish.php        # Turkish locale utilities
│       └── rate_limit.php     # File-based rate limiting
│
├── templates/
│   ├── header.php             # Shared HTML head, navigation, CSS
│   └── footer.php             # Shared footer, scripts
│
├── database/
│   └── schema.sql             # Complete database schema (15 tables)
│
├── cron/
│   └── cron_runner.php        # Master cron: queue processing + scheduled tasks
│
├── tests/                     # PHPUnit tests
│   ├── bootstrap.php
│   └── Unit/
│
└── logs/                      # Runtime logs (gitignored)
```

## Data Flow

### Product Sync (Paraşüt → Zoho)
```
1. ProductController::fetchParasut()
   └─► ParasutService::getAllProducts()
       └─► Paraşüt API (paginated, rate-limited)
       └─► Store in `parasut_products` table

2. ProductController::fetchZoho()
   └─► ZohoService::getAllProducts()
       └─► Zoho CRM API (paginated, rate-limited)
       └─► Store in `zoho_products` table

3. SyncService::compareProducts()
   └─► Match by product_code
   └─► Detect: new, updated, price changed, missing

4. SyncController::syncProduct()
   └─► ZohoService::upsertProduct()
       └─► Zoho CRM API (create or update)
```

### Invoice Sync (Paraşüt → Zoho)
```
1. Fetch invoices from Paraşüt → store locally
2. Match with Zoho invoices by number/amount
3. Create unmatched invoices in Zoho
4. Map Paraşüt tax rates → Zoho tax IDs (via zoho_tax_map)
```

## Authentication & Security

| Layer | Implementation |
|-------|---------------|
| Login | Password hash (`password_hash/verify`) |
| CSRF | Per-session token, `hash_equals` verification |
| Rate Limit | File-based sliding window (no Redis needed) |
| Brute Force | IP-based attempt tracking + lockout |
| CAPTCHA | Cloudflare Turnstile (optional) |
| API Tokens | Stored in DB, masked in logs |
| Sessions | Secure cookies (HttpOnly, SameSite, Secure) |

## API Rate Limiting

Both Zoho and Paraşüt APIs have rate limits. The application handles them with:

- **Exponential backoff** on 429/rate-limit responses
- **Configurable delays** between API calls
- **API metrics tracking** (`api_metrics` table) for monitoring
- **File-based rate limiting** for request throttling

## Job Queue

Background processing via `job_queue` table:
```
cron_runner.php (every minute)
  └─► Queue::getNextJob()  (SELECT FOR UPDATE → row lock)
  └─► Queue::processJob()  (dispatch by job_type)
  └─► Retry on failure (up to max_attempts)
```

## Technology Decisions

| Decision | Rationale |
|----------|-----------|
| No framework | Minimal dependencies, easy cPanel deployment |
| File-based rate limiting | No Redis requirement for shared hosting |
| Database settings | No SSH needed to change API keys |
| Browser install wizard | Zero-config setup for non-technical users |
| Composer autoload | PSR-4 class loading with fallback |
