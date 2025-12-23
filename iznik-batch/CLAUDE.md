# iznik-batch (Laravel Batch Jobs Processor)

This is the Laravel-based batch job processor for Freegle. It handles background tasks like email notifications, digests, and data cleanup.

## Architecture

- **Laravel 12** application with PHP 8.3+
- Uses the main `iznik` database directly (same as iznik-server)
- PHPUnit tests use `iznik_batch_test` database
- Uses MJML for email templates via `spatie/laravel-mjml`
- Container name: `freegle-batch`

## Configuration

All domain-specific configuration is in `config/freegle.php` and can be overridden via environment variables:

- `FREEGLE_USER_SITE` - User-facing website URL (default: https://www.ilovefreegle.org)
- `FREEGLE_MOD_SITE` - Moderator tools URL (default: https://modtools.org)
- `FREEGLE_SITE_NAME` - Site name for branding (default: Freegle)
- `FREEGLE_LOGO_URL` - Logo image URL
- `FREEGLE_WALLPAPER_URL` - Email header background image
- `FREEGLE_SRID` - Geospatial SRID (default: 3857, Web Mercator)

## Running Tests

```bash
# Run all unit and feature tests (uses iznik_test database)
docker exec freegle-batch php artisan test --testsuite=Unit,Feature

# Run integration tests (requires MailHog)
docker exec freegle-batch php artisan test --testsuite=Integration

# Run specific test
docker exec freegle-batch php artisan test --filter="test_method_name"
```

## Test Suites

- **Unit** - Unit tests using iznik_batch_test database
- **Feature** - Feature tests using iznik_batch_test database
- **Integration** - Tests that require external services (MailHog)

## Database Migrations

Migrations are generated from the existing `iznik` database schema using `kitloong/laravel-migrations-generator`:

```bash
# Re-generate migrations from iznik database (if schema changes)
docker exec freegle-batch php artisan migrate:generate
```

## Code Style

- Follow Laravel conventions
- Use `config()` helper for all configurable values - never hardcode domains or magic numbers
- Use MJML templates in `resources/views/emails/mjml/`
- All Mailable classes must extend `MjmlMailable` and implement `getSubject()`

## Email Guidelines

- **Never use base64 data URIs in emails** - Gmail and most email clients strip them for security reasons. Use hosted image URLs instead.
- Email images should be hosted on a CDN or web server and referenced via HTTPS URLs.
- Configure image URLs in `config/freegle.php` under the `images` key.

## Reference Material

When implementing services, check the PHPUnit tests in `iznik-server/test/ut/php` for the original business logic and schema usage. This helps understand the intention of the original code.

## Migration Rules

**CRITICAL: Tests are specifications, not suggestions.**

When migrating code from iznik-server to this Laravel application:

1. **Never change test assertions to make tests pass.** When a test fails, the test is telling you what the code *should* do. The implementation must be fixed to match the test, not the other way around.

2. **Always verify against iznik-server first.** Before changing any constant, enum value, or business logic, grep `iznik-server` to see what the original values/behavior are. The source of truth is always the PHP code in iznik-server, not database introspection or generated migrations.

3. **Fix the source, not the symptom.** If a migration is missing an enum value that iznik-server defines, fix the migration - don't remove the constant from the model.

4. **Red flag: changing test expectations.** Any time you're about to change what a test *expects* (not how it sets up data), stop and verify the expected behavior against iznik-server before proceeding.

5. **Document discrepancies.** If you find the database schema differs from iznik-server constants, document it and fix the migration rather than silently changing behavior.

See `MIGRATION-STATUS.md` for progress tracking on migrating cron scripts.

## Container Commands

```bash
# Rebuild container after Dockerfile changes
docker-compose build batch && docker-compose up -d batch

# View logs
docker logs freegle-batch

# Run artisan commands
docker exec freegle-batch php artisan <command>
```
