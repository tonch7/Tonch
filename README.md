# FinApp

FinApp is a lightweight financial management application built for people who want real control over their own money, without ads, trackers, abusive subscriptions, or hidden behavior.

The idea behind the project is simple: give anyone a practical and transparent way to organize income, expenses, bank accounts, invoices, monthly closings, and reports in a system they can host and control themselves.

This project was created to be useful, free to use, and open for anyone who wants a straightforward financial app without shady scripts, dark patterns, or “caixa 2”.

## What FinApp is

FinApp is a self-hosted web application focused on day-to-day financial control.

It was designed for simplicity in deployment and ownership of data:

- no external database server required
- no SaaS dependency required
- no ads
- no trackers
- no malicious scripts
- no forced third-party lock-in

You run it on your own server, keep your own data, and use it on your own terms.

## Main features

Based on the current codebase, FinApp includes:

- authentication with session-based access
- installation wizard for first-time setup
- bank account management
- income, expenses, and transfers
- monthly opening and closing workflow
- accounts payable / receivable
- customer registry
- payment methods and categories
- credit card invoice structure
- invoice / fiscal document area
- export tools for CSV, JSON, XML, and TXT
- audit log screen
- encrypted private vault for sensitive notes/files
- SQLite-based local data storage

## How it was built

FinApp is a classic server-rendered web app.

### Backend

- **PHP**
- **SQLite** via PDO
- custom bootstrap and helper layer
- installer flow written in PHP

### Frontend

- **HTML**
- **CSS**
- **JavaScript**
- no heavy framework dependency found in this package

### Architecture style

The project follows a lightweight structure, closer to a pragmatic custom PHP application than a framework-based monolith.

Important folders in the current project:

```text
assets/       Frontend CSS and JavaScript
config/       Runtime configuration
database/     SQLite schema and database files
install/      Installation wizard
public/       Main application pages
src/          Bootstrap, helpers, layout, database class
storage/      Sessions, logs, and cache
```

## Technologies used

- PHP 7.4+
- PDO SQLite
- OpenSSL functions for encryption-related features
- HTML5
- CSS3
- Vanilla JavaScript

## Security and privacy approach

From the code analysis, FinApp already includes some good security-oriented decisions:

- CSRF token validation
- password hashing with bcrypt
- login throttling / brute-force guard
- session-based authentication
- file protection using `.htaccess` in sensitive directories
- encrypted vault feature for protected content
- optional encrypted SQLite file workflow
- audit logging for important actions

That said, before publishing publicly, the repository should be cleaned and reviewed carefully.

## Before publishing on GitHub

**Do not publish the ZIP exactly as it is.**

The current package contains private and distribution-unfriendly files mixed into the repository.

### Files and content that should be removed before going public

Remove these kinds of files from the public repo:

- runtime logs
- database artifacts and local lock files
- uploaded real documents
- any APK, spreadsheet, or private internal file not required by the app
- any real sample fiscal XML/PDF containing personal or business data
- secrets, generated configs, or environment-specific files
- branding leftovers that do not belong to the public FinApp identity
- old commercial license file if you are switching to MIT

### Specifically identified in this package

These items should **not** go to a public GitHub repo in the current form:

```text
audit_log.jsonl
database/finapp.db.enc
database/finapp.db.lock
priv8/estoque.xls
priv8/scan.apk
public/uploads/notas_fiscais/*
LICENSE.txt   (current file is commercial, conflicts with MIT)
```

There are also branding remnants from earlier/private usage that should be revised:

- references to other names in comments and defaults
- author-identifying text in source comments if you want a neutral public release
- footer and UI leftovers not aligned with the FinApp public identity

## Recommended repository cleanup

A clean open-source GitHub version should ideally include:

```text
FinApp/
├── assets/
├── config/
│   └── .htaccess
├── database/
│   └── schema.sql
├── install/
├── public/
├── src/
├── storage/
│   └── .gitkeep
├── .gitignore
├── LICENSE
├── README.md
└── INSTALL.md
```

## Suggested `.gitignore`

```gitignore
/config/config.php
/config/.installed
/database/*.db
/database/*.db.enc
/database/*.db.lock
/database/*.sqlite
/database/*.sqlite3
/storage/logs/*
/storage/sessions/*
/storage/cache/*
!/storage/logs/.gitkeep
!/storage/sessions/.gitkeep
!/storage/cache/.gitkeep
/public/uploads/*
!/public/uploads/.htaccess
!/public/uploads/notas_fiscais/.htaccess
/audit_log.jsonl
/.env
/.DS_Store
Thumbs.db
```

## Minimum requirements

- PHP 7.4 or newer
- `pdo_sqlite` enabled
- `openssl` enabled
- web server with PHP support
- write permission for:
  - `config/`
  - `database/`
  - `storage/`
  - `public/uploads/`

## Installation

### 1. Upload the project to your server

Place the project in your desired web directory.

### 2. Ensure writable directories

Make sure the application can write to:

- `config/`
- `database/`
- `storage/`
- `public/uploads/`

### 3. Open the application in the browser

Access the root URL of the project.

The installer should start automatically if the app has not been configured yet.

### 4. Follow the installation wizard

The wizard will guide the user through:

- environment checks
- database initialization
- administrator creation
- base system setup

## Who this project is for

FinApp is a good fit for:

- freelancers
- solo professionals
- small business owners
- self-hosters
- users who prefer owning their own data
- people who want a simple financial app without ads or tracking

## Project philosophy

FinApp was created with a very direct idea:

> financial control should belong to the user, not to an ad network, a shady platform, or a black-box subscription model.

The goal is to offer a tool that is:

- useful
- honest
- lightweight
- transparent
- self-hostable
- free for everyone to use and improve

## Open-source release note

If you are publishing this project as MIT, make sure the repository no longer contains:

- private client data
- internal business assets
- third-party files without redistribution rights
- old proprietary/commercial license text

MIT means people can use, modify, distribute, and even commercialize the software, so the repository should contain only what you are truly willing to release publicly.

## Final note

FinApp is not trying to be a bloated enterprise ERP.

It is a practical app for people who want a financial system that feels like theirs.

No ads. No hidden scripts. No nonsense.

Just your app, your data, and your control.


Gabriel Perdigão --- www.tonch.com.br
