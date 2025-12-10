# iznik-batch

**Work in Progress**

This is a Laravel-based replacement for the bulk/batch PHP code in `iznik-server`. It handles background tasks like email notifications, digests, and data cleanup that previously ran as cron jobs or background scripts.

## Status

This project is under active development. The goal is to migrate batch processing from the legacy PHP codebase to a modern Laravel application with proper testing, queue support, and maintainability.

## What This Replaces

The batch jobs in `iznik-server/scripts/cron/` and similar bulk operations are being reimplemented here using:
- Laravel's queue system for job processing
- Eloquent models mapped to the existing `iznik` database schema
- MJML templates for email rendering
- PHPUnit tests to ensure correctness

## Architecture

- **Laravel 12** application with PHP 8.3+
- Connects to two databases:
  - `iznik_laravel` - Laravel's own database for testing
  - `iznik` - Read-only access to main Freegle database
- Container name: `freegle-batch`

## Getting Started

See `CLAUDE.md` for detailed documentation on:
- Configuration options
- Running tests
- Code style guidelines
- Container commands

## License

Part of the Freegle project.
