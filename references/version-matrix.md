# Elgg Version Compatibility Matrix

| Elgg | PHP | MySQL | MariaDB | PHPUnit | Key Dependencies |
|------|-----|-------|---------|---------|-----------------|
| 2.3 | >=5.6 | >=5.5 | >=5.5 | ~5.x | Zend\Mail, RequireJS, jQuery 2.x, Doctrine DBAL ~2.5 |
| 3.3 | >=7.2 | >=5.6 | >=10.1 | ~8.x | Zend\Mail, RequireJS, jQuery 3.x, Doctrine DBAL ~2.9 |
| 4.3 | >=7.4 | >=5.7 | >=10.3 | ~9.x | Laminas\Mail, RequireJS, jQuery 3.5, Doctrine DBAL ~3.0 |
| 5.1 | >=8.0 | >=5.7 | >=10.3 | ~9.x | Laminas\Mail, RequireJS, jQuery 3.x, Doctrine DBAL ~3.x |
| 6.1 | >=8.1 | >=8.0 | >=10.6 | ~10.5 | Laminas\Mail, ES Modules, jQuery 3.7, Doctrine DBAL ~4.0 |

## Required PHP Extensions (all versions)

- `ext-pdo` + `ext-pdo_mysql`
- `ext-gd`
- `ext-json`
- `ext-xml`
- `ext-mbstring`
- `ext-intl` (optional in 3.x, required from 6.x)

## Plugin Installation

| Elgg Version | Plugin Format | Installation Method |
|-------------|--------------|-------------------|
| 1.x-2.x | `manifest.xml` + `start.php` | Copy to `mod/` directory |
| 3.x | `manifest.xml` + `start.php` (deprecated) | Copy to `mod/` or Composer |
| 4.x+ | `elgg-plugin.php` + `composer.json` | Composer (`composer require`) or `mod/` |

## Docker Base Images

| Elgg | PHP Image | MySQL Image |
|------|----------|-------------|
| 2.x | `php:5.6-apache` | `mysql:5.5` |
| 3.x | `php:7.2-apache` | `mysql:5.7` |
| 4.x | `php:7.4-apache` | `mysql:5.7` |
| 5.x | `php:8.0-apache` | `mysql:5.7` |
| 6.x | `php:8.1-apache` | `mysql:8.0` |
