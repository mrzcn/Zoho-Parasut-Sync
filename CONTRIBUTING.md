# Contributing to Zoho-Parasut-Sync

Thank you for your interest in contributing! 🎉

## How to Contribute

### Reporting Bugs
- Use the [Bug Report](https://github.com/mrzcn/Zoho-Parasut-Sync/issues/new?template=bug_report.md) template
- Include PHP version, MySQL version, and browser info
- Provide steps to reproduce the issue

### Suggesting Features
- Use the [Feature Request](https://github.com/mrzcn/Zoho-Parasut-Sync/issues/new?template=feature_request.md) template
- Check [existing issues](https://github.com/mrzcn/Zoho-Parasut-Sync/issues) first

### Pull Requests

1. **Fork** the repository
2. **Create a branch** from `main`:
   ```bash
   git checkout -b feature/your-feature-name
   ```
3. **Make your changes** following the code style below
4. **Test** your changes locally
5. **Commit** with a clear message:
   ```bash
   git commit -m "feat: add product category sync"
   ```
6. **Push** and open a Pull Request

### Commit Message Format

We follow [Conventional Commits](https://www.conventionalcommits.org/):

| Prefix | Usage |
|--------|-------|
| `feat:` | New feature |
| `fix:` | Bug fix |
| `docs:` | Documentation only |
| `style:` | Formatting, no logic change |
| `refactor:` | Code restructuring |
| `test:` | Adding/updating tests |
| `chore:` | Build, CI, tooling |

### Code Style

- **PHP**: PSR-12 coding standard
- **JavaScript**: ES6+, use `const`/`let` (no `var`)
- **CSS**: Use existing Tailwind utility classes where possible
- **SQL**: Use prepared statements (`$pdo->prepare()`) — never concatenate user input
- **Security**: Always use `sanitize()` for output, `generateCsrfToken()` for forms

### Project Structure

```
classes/          → Service classes (API clients, business logic)
controllers/      → Request handlers (one per feature area)
config/helpers/   → Pure utility functions
templates/        → Shared HTML (header, footer)
database/         → SQL schema and migrations
tests/            → PHPUnit tests
```

### Running Tests

```bash
composer test
```

### Development Setup

The fastest way to get started:

```bash
# Using Docker
docker-compose up -d
# Open http://localhost:8080

# Or manually
git clone https://github.com/mrzcn/Zoho-Parasut-Sync.git
cd Zoho-Parasut-Sync
composer install
# Configure .env and open install.php
```

## Code of Conduct

This project follows the [Contributor Covenant](CODE_OF_CONDUCT.md). By participating, you are expected to uphold this code.

## Questions?

Open a [Discussion](https://github.com/mrzcn/Zoho-Parasut-Sync/discussions) or create an issue.
