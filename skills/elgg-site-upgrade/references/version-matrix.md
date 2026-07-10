# Elgg Version Compatibility Matrix

| Elgg | PHP | MySQL | MariaDB | PHPUnit | Key Dependencies |
|------|-----|-------|---------|---------|-----------------|
| 2.3 | >=5.6 | >=5.5 | >=5.5 | ~5.x | Zend\Mail, RequireJS, jQuery 2.x, Doctrine DBAL ~2.5 |
| 3.3 | >=7.2 | >=5.6 | >=10.1 | ~8.x | Zend\Mail, RequireJS, jQuery 3.x, Doctrine DBAL ~2.9 |
| 4.3 | >=7.4 | >=5.7 | >=10.3 | ~9.x | Laminas\Mail, RequireJS, jQuery 3.5, Doctrine DBAL ~3.0 |
| 5.1 | >=8.0 | >=5.7 | >=10.3 | ~9.x | Laminas\Mail, RequireJS, jQuery 3.x, Doctrine DBAL ~3.x |
| 6.1 | >=8.1 | >=8.0 | >=10.6 | ~10.5 | Laminas\Mail, ES Modules, jQuery 3.7, Doctrine DBAL ~4.0 |
| 7.0 | >=8.3 | >=8.0 | >=10.6 | ~12.5 | Symfony\Mailer, ES Modules, jQuery 3.7, Doctrine DBAL ~4.0, Font Awesome 7 |

## Required PHP Extensions (all versions)

- `ext-pdo` + `ext-pdo_mysql`
- `ext-gd`
- `ext-json`
- `ext-xml`
- `ext-mbstring`
- `ext-intl` (optional in 3.x, required from 6.x+)

## Plugin Installation

| Elgg Version | Plugin Format | Installation Method |
|-------------|--------------|-------------------|
| 1.x-2.x | `manifest.xml` + `start.php` | Copy to `mod/` directory |
| 3.x | `manifest.xml` + `start.php` (deprecated) | Copy to `mod/` or Composer |
| 4.x+ | `elgg-plugin.php` + `composer.json` | Composer (`composer require`) or `mod/` |

## Canonical composer.json Constraints Per Migration Branch

These are the **required** values for `elgg/elgg` and `php` in `composer.json`
on each migrate branch. Use the exact constraints below — do not round up or
use `^` where `~` is listed.

| Branch | `elgg/elgg` | `php` | Docker PHP | `composer/installers` |
|--------|-------------|-------|-----------|----------------------|
| `migrate/elgg-3.x` | `^3.0` | `>=7.2` | 7.4 | `~1.0` |
| `migrate/elgg-4.x` | `^4.0` | `>=7.4` | 7.4 | `^2.0` |
| `migrate/elgg-5.x` | `~5.1.0` | `>=8.0` | 8.2 | `^2.0` |
| `migrate/elgg-6.x` | `~6.1.0` | `>=8.1` | 8.2 | `^2.0` |
| `migrate/elgg-7.x` | `~7.0.0` | `>=8.3` | 8.3 | `^2.0` |

Run `python3 bin/verify-plugin-branches.py <plugin-dir>` after updating to confirm.

## Docker Base Images

| Elgg | PHP Image | MySQL Image |
|------|----------|-------------|
| 2.x | `php:5.6-apache` | `mysql:5.5` |
| 3.x | `php:7.2-apache` | `mysql:5.7` |
| 4.x | `php:7.4-apache` | `mysql:5.7` |
| 5.x | `php:8.0-apache` | `mysql:5.7` |
| 6.x | `php:8.1-apache` | `mysql:8.0` |
| 7.x | `php:8.3-apache` | `mysql:8.0` |
