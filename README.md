# Quickpay CLI

En PHP-baseret kommandolinjeklient til Quickpay. Projektet er på nuværende
tidspunkt kun fundamentet; Quickpay-funktioner kommer i de efterfølgende trin.

## Krav

- PHP 8.4 eller nyere
- Composer

## Lokal udvikling

```bash
composer install
php quickpay list
composer check
```

## PHAR

Byg den distribuerbare CLI med Laravel Zeros Box-flow:

```bash
composer build
builds/quickpay --version
builds/quickpay list
```

`builds/quickpay` er også Composer-binærtargetet for en senere
Packagist-installation. Der findes endnu ingen publiceret pakke eller remote.

## Kvalitet

```bash
composer test
composer analyse
composer format:test
composer format
composer check
```

## Licens

MIT. Se [LICENSE.md](LICENSE.md).
