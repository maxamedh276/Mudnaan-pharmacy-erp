# Mudnaan-pharmacy-erp

Retail pharmacy management built from the existing Mudnaan ERP interface, inventory, purchasing, returns and accounting services. The active application is in this project root on `main`.

The pharmacy supports medicine categories, generic/brand names, strengths, dosage forms (tablet, capsule, syrup, injection, cream and others), and multiple selling units such as tablet, strip and box. Unit conversions and prices are saved per medicine; old documents retain their original conversion factors.

Batch inventory supports receiving with batch/expiry dates, FEFO sales allocation, expired-stock blocking, quarantine, recalls, disposal and legacy stock reconciliation. Customer medicine returns enter quarantine; supplier returns remove stock from the original purchase batches.

Patients, prescriptions, pharmacist approval, partial/final dispensing and remaining prescription quantities are integrated with the existing sales transaction. The POS supports medicine units, barcode/SKU entry, payment accounts, tax, customer credit and printable dispensing labels. Financial reports, suppliers, invoices, payments, staff, permissions and audit records reuse the ERP implementation.

English and Somali are available for pharmacy screens. Existing finance/admin screens retain their English interface.

## Run locally

Requirements: PHP 8.2+, Composer and MySQL/MariaDB. XAMPP's installed PHP is 8.0, so this workspace uses the separate, ignored runtime `.tools/php84/php.exe`.

```powershell
# Fresh installation only; refuses to overwrite an existing pharmacy_main database.
php tools/install_pharmacy.php
.tools/php84/php.exe -S 127.0.0.1:8085 tools/dev_router.php
```

The current local application is available at `http://127.0.0.1:8085`. Initial credentials are stored privately in the ignored `.tools/LOCAL_ACCESS.json`. Change the administrator password after signing in.

`pharmacy_main` is a fresh installation. The previous configured `erp_tenant_demo` database was absent; no existing business database was reset or populated with test transactions. Review existing schema/migration history before upgrading a populated ERP database. Do not use the fresh installer as an upgrade tool.

Set `PHARMACY_DATABASE` for a different pharmacy database and use standard CodeIgniter environment settings for its connection. Currency and business identity remain configurable through Settings; timezone defaults to `Africa/Mogadishu` and can be overridden with `app.appTimezone` in `.env`.

## Verification

```powershell
php tests/pharmacy_input_smoke.php
php tests/pharmacy_service_smoke.php
php tests/pharmacy_operations_smoke.php
php tests/pharmacy_concurrency_smoke.php
node tests/pharmacy_browser_smoke.cjs
```

Database and browser workflows use new `pharmacy_test_*` fixture databases and remove only their own fixtures. The browser suite uses Edge and the development dependency in `.tools/browser`; it does not create financial records in the local pharmacy database.

See [implementation notes](docs/PHARMACY_IMPLEMENTATION.md) for migration and operating details. Clinical dose calculation, interaction checking, insurance claims, universal regulatory compliance and real printer hardware are not certified by these tests.

---

# Framework reference

## What is CodeIgniter?

CodeIgniter is a PHP full-stack web framework that is light, fast, flexible and secure.
More information can be found at the [official site](https://codeigniter.com).

This repository holds a composer-installable app starter.
It has been built from the
[development repository](https://github.com/codeigniter4/CodeIgniter4).

More information about the plans for version 4 can be found in [CodeIgniter 4](https://forum.codeigniter.com/forumdisplay.php?fid=28) on the forums.

You can read the [user guide](https://codeigniter.com/user_guide/)
corresponding to the latest version of the framework.

## Installation & updates

`composer create-project codeigniter4/appstarter` then `composer update` whenever
there is a new release of the framework.

When updating, check the release notes to see if there are any changes you might need to apply
to your `app` folder. The affected files can be copied or merged from
`vendor/codeigniter4/framework/app`.

## Setup

Copy `env` to `.env` and tailor for your app, specifically the baseURL
and any database settings.

## Important Change with index.php

`index.php` is no longer in the root of the project! It has been moved inside the *public* folder,
for better security and separation of components.

This means that you should configure your web server to "point" to your project's *public* folder, and
not to the project root. A better practice would be to configure a virtual host to point there. A poor practice would be to point your web server to the project root and expect to enter *public/...*, as the rest of your logic and the
framework are exposed.

**Please** read the user guide for a better explanation of how CI4 works!

## Repository Management

We use GitHub issues, in our main repository, to track **BUGS** and to track approved **DEVELOPMENT** work packages.
We use our [forum](http://forum.codeigniter.com) to provide SUPPORT and to discuss
FEATURE REQUESTS.

This repository is a "distribution" one, built by our release preparation script.
Problems with it can be raised on our forum, or as issues in the main repository.

## Server Requirements

PHP version 8.2 or higher is required, with the following extensions installed:

- [intl](http://php.net/manual/en/intl.requirements.php)
- [mbstring](http://php.net/manual/en/mbstring.installation.php)

> [!WARNING]
> - The end of life date for PHP 7.4 was November 28, 2022.
> - The end of life date for PHP 8.0 was November 26, 2023.
> - The end of life date for PHP 8.1 was December 31, 2025.
> - If you are still using below PHP 8.2, you should upgrade immediately.
> - The end of life date for PHP 8.2 will be December 31, 2026.

Additionally, make sure that the following extensions are enabled in your PHP:

- json (enabled by default - don't turn it off)
- [mysqlnd](http://php.net/manual/en/mysqlnd.install.php) if you plan to use MySQL
- [libcurl](http://php.net/manual/en/curl.requirements.php) if you plan to use the HTTP\CURLRequest library
