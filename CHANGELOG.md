# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[2.6]: https://github.com/mrzcn/Zoho-Parasut-Sync/compare/v2.4...v2.6
[2.4]: https://github.com/mrzcn/Zoho-Parasut-Sync/compare/v2.3...v2.4
[2.3]: https://github.com/mrzcn/Zoho-Parasut-Sync/compare/v2.2...v2.3
[2.2]: https://github.com/mrzcn/Zoho-Parasut-Sync/compare/v2.1...v2.2
[2.1]: https://github.com/mrzcn/Zoho-Parasut-Sync/compare/v2.0...v2.1
[2.0]: https://github.com/mrzcn/Zoho-Parasut-Sync/compare/v1.0...v2.0
[1.0]: https://github.com/mrzcn/Zoho-Parasut-Sync/releases/tag/v1.0
