# Library Management System

An English-language library management application for Bangladesh Public Service Commission (BPSC), built with Laravel and Tabler. Access uses four fixed roles: Administrator, Librarian, Library Staff and Library Member.

## Requirements

- PHP 8.3+, Composer 2 and MySQL 8+ (WAMP64 supported).
- PHP extensions required by `composer.lock`, including PDO MySQL, mbstring, XML, cURL, ZIP, GD, BCMath and fileinfo. SQLite is needed for the test suite.
- Node.js 22.12+ and npm for rebuilding frontend assets; compiled assets are included in deliveries.

## Installation

Use a new project folder and an empty database. From the project root:

```bat
composer install
copy .env.example .env
php artisan key:generate
```

Create the database and set `APP_URL`, database credentials and other environment settings in `.env`, then run:

```bat
php artisan migrate --seed
php artisan library:create-admin
php artisan serve
```

Enter your own administrator credentials; no default password is provided. For production, point the web server to `public/`, configure HTTPS and set `APP_ENV=production`, `APP_DEBUG=false` and `SESSION_SECURE_COOKIE=true`.

## Development

```bat
npm ci --ignore-scripts
npm run build
php artisan test
```

Use migrations for schema changes. Keep role checks on the server, record relevant events without passwords or tokens, and update tests when behavior changes. Keep the member portal separate from administration.

## Maintenance

Back up the database, `.env` and uploaded files before updates. Apply the supplied files, then follow the matching release guide under `docs/releases/`. Preserve existing credentials and application keys; never use `migrate:fresh` or `db:wipe` on an existing installation. Review audit/application logs and verify login and affected workflows after maintenance.

This README is a stable project guide. Store release changes, special update commands and validation results under `docs/`; do not rewrite this file for routine releases.
