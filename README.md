# Project Name

A Skeleton-based PHP application.

## Requirements

- PHP 8.1+
- MySQL
- Composer

## Setup

```bash
composer install
# Create config/environment.php with your database DSN and secrets
util/bin/skeleton migrate:up
```

Point your web server's document root at `webroot/` (or use the `.htaccess` rewrite to `handler.php`).

## Structure

```
app/front/          Application modules, templates, events, media
config/             Global configuration
lib/base/           Framework bootstrap and utilities
lib/model/          Application models
lib/component/      Reusable components
migration/          Database migrations
store/              File storage (email templates, uploads, exports)
util/bin/           CLI tools
```

## CLI

```bash
util/bin/skeleton migrate:up       # Run migrations
util/bin/skeleton test:run         # Run tests
```
