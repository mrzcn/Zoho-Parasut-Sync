# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.8] - 2026-03-15

### Added
- 🧩 **Trait-based architecture** — Service sınıfları mantıksal parçalara ayrıldı:
  - `ZohoAuthTrait` — OAuth2 token yönetimi, authorization code exchange, DB-level lock
  - `ZohoProductsTrait` — Ürün CRUD, arama, toplu işlem, vergi yönetimi
  - `ZohoInvoicesTrait` — Fatura/sipariş CRUD, e-fatura, e-arşiv, satıcı yönetimi
  - `ParasutAuthTrait` — OAuth2 password-grant token yönetimi
  - `ParasutProductsTrait` — Ürün listeleme, güncelleme, arşiv, stok yönetimi
  - `ParasutInvoicesTrait` — Satış faturası, alış faturası, e-belge, contact yönetimi
- 📝 Tüm trait metotlarına **PHP type hints** ve **PHPDoc** eklendi

### Changed
- ♻️ `ProductController`: 3 tekrarlanan metot (`_lite` ve `_in_both_systems`) artık canonical versiyonlarına delege ediyor (~150 satır tekrar kaldırıldı)
- 🌐 **Hata mesajı tutarlılığı**: Tüm kullanıcıya gösterilen mesajlar Türkçe olarak standardize edildi
  - `Queue.php`: "Unknown job type" → "Bilinmeyen iş tipi"
  - `ZohoService.php`: "Unknown error" → "Bilinmeyen hata"
- 📂 Autoloader `classes/Zoho/` ve `classes/Parasut/` alt dizinlerini destekliyor

### Architecture
- Trait yapısı mevcut `ZohoService` ve `ParasutService` sınıflarını **kırmaz**
- Her trait bağımsız olarak `use` edilebilir veya ileride servisler parçalandığında kompozisyon için kullanılabilir
- Tekrarlanan `_lite` metotları `@deprecated` olarak işaretlendi

## [2.7] - 2026-03-14

### Added
- 🏗️ `ApiClient` abstract base class — shared HTTP client with retry, rate limit, and metric logging
- 🚨 Custom Exception hierarchy (`ZohoApiException`, `ParasutApiException`, `ConnectionException`, etc.)
- 📝 `Logger` singleton — replaces `global $pdo` dependency, supports log level filtering (DEBUG/INFO/WARNING/ERROR)
- 🗃️ Database migration system (`database/migrate.php` + `database/migrations/`)
- 🔍 PHPStan Level 1 static analysis added to CI pipeline
- 📋 `invoice_mapping` table for Zoho ↔ Paraşüt invoice tracking

### Changed
- Verbose API request logs moved from INFO to DEBUG level (reduces ~80% log noise)
- Schema: `job_queue` table renamed to `jobs` (matches `Queue.php`)
- Schema: `zoho_products.zoho_id` now UNIQUE (prevents duplicates)
- Schema: `sync_history` table now includes `resource_type` and `resource_id` columns
- `.env.example` updated with API credential placeholders
- Autoloader now supports subdirectory scanning (`classes/Exceptions/`)
- `writeLog()` function delegates to Logger singleton (backward compatible)

### Fixed
- Schema/code mismatch: `job_queue` table name vs `jobs` in Queue.php
- Missing `scheduled_at` and `started_at` columns in jobs table
- Missing `invoice_mapping` table (used by SyncService but not in schema)

## [2.6] - 2026-03-14

### Added
- 🐳 Docker support with `docker-compose.yml` for one-command setup
- ✅ PHPUnit test infrastructure with example tests
- 📖 Architecture documentation (`docs/architecture.md`)
- 🤝 Community files: CONTRIBUTING.md, CODE_OF_CONDUCT.md
- 🔄 GitHub Actions CI pipeline for automated testing
- 📋 Issue and PR templates for better collaboration

### Security
- All API credentials stored in database, never in source code
- `.env` file excluded from version control
- CSRF protection on all forms
- Rate limiting on API endpoints
- Brute-force protection on login
- Token masking in log output

## [2.4] - 2026-03-12

### Added
- 🔧 Browser-based setup wizard (`install.php`) — no file editing required
- 🪝 Webhook support for Paraşüt and Zoho
- ⏰ Master cron runner with job queue system
- 📊 API metrics tracking and dashboard widgets

### Changed
- All hardcoded credentials moved to database settings
- Improved error handling with structured logging

## [2.3] - 2026-03-06

### Added
- 🔍 Duplicate product detection and smart merging
- 🔄 Related record updates during merge (invoices, quotes, orders)
- 📋 Merge history log with rollback data

### Changed
- Product comparison page redesigned with side-by-side view
- Improved product code matching algorithm

## [2.2] - 2026-02-28

### Added
- 🧾 Invoice synchronization (Paraşüt → Zoho)
- 📋 Purchase Order synchronization
- 💰 Tax mapping configuration (zoho_tax_map)
- 📊 Dashboard with real-time sync status

### Changed
- Refactored API clients into service classes
- Added retry logic with exponential backoff

## [2.1] - 2026-02-15

### Added
- 📦 Product synchronization (Paraşüt → Zoho CRM)
- 🔐 Login system with password hashing
- 🛡️ Cloudflare Turnstile CAPTCHA support

## [2.0] - 2026-02-01

### Changed
- Complete architecture rewrite with MVC pattern
- Router-based API handling
- Controller classes for each feature area
- Composer autoloading

## [1.0] - 2026-01-15

### Added
- Initial release
- Basic Paraşüt product sync to Zoho CRM
- Simple admin panel

[2.8]: https://github.com/mrzcn/Zoho-Parasut-Sync/compare/v2.7...v2.8
[2.7]: https://github.com/mrzcn/Zoho-Parasut-Sync/compare/v2.6...v2.7
[2.6]: https://github.com/mrzcn/Zoho-Parasut-Sync/compare/v2.4...v2.6
[2.4]: https://github.com/mrzcn/Zoho-Parasut-Sync/compare/v2.3...v2.4
[2.3]: https://github.com/mrzcn/Zoho-Parasut-Sync/compare/v2.2...v2.3
[2.2]: https://github.com/mrzcn/Zoho-Parasut-Sync/compare/v2.1...v2.2
[2.1]: https://github.com/mrzcn/Zoho-Parasut-Sync/compare/v2.0...v2.1
[2.0]: https://github.com/mrzcn/Zoho-Parasut-Sync/compare/v1.0...v2.0
[1.0]: https://github.com/mrzcn/Zoho-Parasut-Sync/releases/tag/v1.0

