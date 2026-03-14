<p align="center">
  <img src="assets/banner.png" alt="Zoho-Parasut-Sync" width="100%">
</p>

<p align="center">
  <strong>Automated synchronization between Zoho CRM and Paraşüt</strong><br>
  <sub>Bridge your Turkish accounting software with your CRM — effortlessly.</sub>
</p>

<p align="center">
  <a href="https://github.com/mrzcn/Zoho-Parasut-Sync/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="License"></a>
  <a href="https://github.com/mrzcn/Zoho-Parasut-Sync/actions"><img src="https://github.com/mrzcn/Zoho-Parasut-Sync/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <img src="https://img.shields.io/badge/php-7.4%2B-8892BF.svg" alt="PHP">
  <img src="https://img.shields.io/badge/mysql-5.7%2B-4479A1.svg" alt="MySQL">
  <a href="https://github.com/mrzcn/Zoho-Parasut-Sync/stargazers"><img src="https://img.shields.io/github/stars/mrzcn/Zoho-Parasut-Sync?style=social" alt="Stars"></a>
</p>

<p align="center">
  <a href="#-features">Features</a> •
  <a href="#-quick-start">Quick Start</a> •
  <a href="#-docker">Docker</a> •
  <a href="#-screenshots">Screenshots</a> •
  <a href="#-api-setup">API Setup</a> •
  <a href="#-architecture">Architecture</a> •
  <a href="#-contributing">Contributing</a>
</p>

---

## ✨ Features

| Feature | Description |
|---------|-------------|
| 📦 **Product Sync** | Automatically sync products between Paraşüt and Zoho CRM |
| 🧾 **Invoice Sync** | Two-way invoice matching and synchronization |
| 📋 **Purchase Orders** | Transfer purchase orders to Zoho CRM |
| 🔍 **Duplicate Detection** | Smart duplicate product detection and merging by product code |
| 🔄 **Related Record Updates** | When merging, all invoices/quotes/orders are automatically reassigned |
| 📊 **Dashboard** | Real-time sync status, API metrics, and queue management |
| 🔐 **Security** | CSRF protection, rate limiting, brute-force protection, Turnstile CAPTCHA |
| 🪝 **Webhooks** | Real-time triggers from both Paraşüt and Zoho |
| ⏰ **Cron Jobs** | Scheduled automatic synchronization with job queue |
| 📝 **Structured Logging** | All operations logged to database with automatic token masking |

## 🚀 Quick Start

### Option 1: Docker (Recommended)

```bash
git clone https://github.com/mrzcn/Zoho-Parasut-Sync.git
cd Zoho-Parasut-Sync
docker-compose up -d
```

Open **http://localhost:8080** and follow the setup wizard.

### Option 2: Manual Installation

```bash
git clone https://github.com/mrzcn/Zoho-Parasut-Sync.git
cd Zoho-Parasut-Sync
composer install
```

1. Create a MySQL database via your hosting panel
2. Open `https://your-domain.com/Zoho-Parasut-Sync/install.php`
3. Follow the 3-step wizard:
   - **Step 1:** Database connection (host, name, user, password)
   - **Step 2:** Table creation (15 tables auto-created)
   - **Step 3:** Admin password

### Requirements

| Requirement | Version |
|------------|---------|
| PHP | 7.4+ |
| MySQL / MariaDB | 5.7+ / 10.4+ |
| PHP Extensions | PDO, cURL, JSON |
| Composer | 2.x |

## 🐳 Docker

The project includes full Docker support for instant setup:

```bash
# Start everything
docker-compose up -d

# View logs
docker-compose logs -f app

# Stop
docker-compose down

# Reset (remove database)
docker-compose down -v
```

The Docker setup includes:
- **PHP 8.1 + Apache** with mod_rewrite enabled
- **MariaDB 10.6** with automatic schema initialization
- Volume persistence for database data
- Health checks for service readiness

## 🔧 API Setup

### Zoho CRM

1. Go to [Zoho API Console](https://api-console.zoho.com/)
2. Create a **Self Client**
3. Set scope: `ZohoCRM.modules.ALL,ZohoCRM.settings.ALL,ZohoCRM.org.ALL`
4. Paste the **Grant Token** into Settings page → Zoho section
5. Click "Generate Refresh Token"

### Paraşüt

1. Create an app at [Paraşüt API](https://api.parasut.com/)
2. Enter Client ID, Client Secret, and Company ID in Settings
3. Enter your Paraşüt username and password

## 🏗️ Architecture

```
┌──────────────────────────────────────────────────────────┐
│                    Admin Dashboard                        │
│      (Dashboard, Products, Invoices, Settings...)         │
└───────────────────────┬──────────────────────────────────┘
                        │
               ┌────────▼────────┐
               │   Controllers   │
               └────────┬────────┘
                        │
          ┌─────────────┼─────────────┐
          ▼             ▼             ▼
   ┌────────────┐ ┌──────────┐ ┌────────────┐
   │ZohoService │ │SyncService│ │ParasutSvc  │
   └──────┬─────┘ └─────┬────┘ └──────┬─────┘
          ▼             ▼             ▼
   ┌──────────┐  ┌────────────┐  ┌──────────┐
   │ Zoho CRM │  │  MySQL DB  │  │ Paraşüt  │
   │   API    │  │            │  │   API    │
   └──────────┘  └────────────┘  └──────────┘
```

### Project Structure

```
├── classes/              # Service classes (API clients, business logic)
│   ├── ZohoService.php   # Zoho CRM API client with OAuth2
│   ├── ParasutService.php# Paraşüt API client
│   ├── SyncService.php   # Comparison and sync logic
│   └── Queue.php         # Background job processing
├── controllers/          # MVC request handlers
├── config/helpers/       # Utility functions (security, logging, HTTP)
├── database/schema.sql   # Complete DB schema (15 tables)
├── cron/                 # Scheduled task runner
├── tests/                # PHPUnit test suite
├── docs/                 # Architecture documentation
└── templates/            # Shared HTML templates
```

📖 Full architecture documentation: [docs/architecture.md](docs/architecture.md)

## 🧪 Testing

```bash
# Install dev dependencies
composer install

# Run tests
composer test

# Run with coverage
composer test-coverage
```

## 🔒 Security

| Layer | Implementation |
|-------|---------------|
| Authentication | `password_hash` / `password_verify` |
| CSRF Protection | Per-session token with `hash_equals` |
| Rate Limiting | File-based sliding window |
| Brute Force | IP-based lockout after 5 attempts |
| CAPTCHA | Cloudflare Turnstile (optional) |
| API Credentials | Stored in database, never in source |
| Log Masking | Tokens auto-masked in all log output |
| Session | HttpOnly, SameSite=Lax, Secure cookies |

## 🤝 Contributing

Contributions are welcome! Please read our [Contributing Guide](CONTRIBUTING.md) before submitting a PR.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'feat: add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📝 Changelog

See [CHANGELOG.md](CHANGELOG.md) for a detailed version history.

---

## 🇹🇷 Türkçe

### Hızlı Kurulum

```bash
git clone https://github.com/mrzcn/Zoho-Parasut-Sync.git
cd Zoho-Parasut-Sync
docker-compose up -d
# veya: composer install → install.php'yi tarayıcıda açın
```

### Özellikler

- 📦 **Ürün Senkronizasyonu** — Paraşüt ürünlerini Zoho CRM'e otomatik aktarım
- 🧾 **Fatura Senkronizasyonu** — Satış faturalarını iki yönlü eşleştirme
- 📋 **Gider Faturası Aktarımı** — Satın alma siparişlerini Zoho'ya aktarma
- 🔍 **Mükerrer Ürün Tespiti** — Ürün koduna göre duplike algılama ve birleştirme
- 📊 **Dashboard** — Anlık durum, API metrikleri, kuyruk yönetimi
- 🔐 **Güvenlik** — CSRF, rate limiting, brute-force, Cloudflare Turnstile
- 🪝 **Webhook / Cron** — Gerçek zamanlı ve zamanlanmış senkronizasyon

### Zoho CRM API Kurulumu

1. [Zoho API Console](https://api-console.zoho.com/) → **Self Client** oluşturun
2. Scope: `ZohoCRM.modules.ALL,ZohoCRM.settings.ALL,ZohoCRM.org.ALL`
3. Grant Token'ı Ayarlar sayfasına yapıştırın

### Paraşüt API Kurulumu

1. [Paraşüt API](https://api.parasut.com/) → Uygulama oluşturun
2. Client ID, Client Secret, Şirket ID bilgilerini girin

### Vergi Haritası

Ayarlar sayfasından Zoho CRM'deki vergi ID'lerini Paraşüt KDV oranlarıyla eşleştirin.

---

## 📄 License

[MIT License](LICENSE) — Copyright (c) 2026 Nolto
