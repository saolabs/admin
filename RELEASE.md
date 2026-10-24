# saola/admin v0.1.0-alpha.1

First installable alpha: source ownership/publishing, admin shell and read-only Resource renderer, DB translations with JSON preview/draft/publish, schema-based Settings editor and scoped host User directory. User create/update/password, role/grant UI, translation UI/AI, upload and audit are not part of this alpha. Panel and user directory are disabled by default.

## Coordinated dependencies

| Component | Minimum tested version / distribution |
| --- | --- |
| PHP | 8.3; CI also runs 8.5 |
| Laravel / Testbench | 12 / 10 and 13 / 11 |
| saola/core | 2.4.0, Composer/Packagist |
| saola/compiler | 3.0.0 for compiling .sao, Composer/Packagist |
| @saolabs/client | GitHub tag v1.3.0, includes built dist |
| @saolabs/builder | GitHub tag v1.2.0 |
| Database validation | SQLite; MySQL/Postgres concurrency not certified |

Compiler 3 is a deliberate major: bare __() in .sao now emits text()/Helper.text() for key-to-text DB catalogs. Existing templates using Laravel translation files must use trans()/choice() explicitly. Laravel __() outside .sao is unchanged. Do not mix older core/compiler/client with the alpha's compiled views.

## Install

After Packagist indexes this repository and tag:

```bash
composer require saola/admin:0.1.0-alpha.1
composer require --dev saola/compiler:^3.0
```

Before the initial Packagist submission, consumers can use the same public GitHub tag via a VCS repository:

```bash
composer config repositories.saola-admin vcs https://github.com/saolabs/admin.git
composer require saola/admin:0.1.0-alpha.1
```

Frontend tool versions are pinned to GitHub release tags for this coordinated alpha; this does not claim an npm registry publication. The workbench package.json is private. See [installation](docs/INSTALLATION.md) for frontend dependencies, host document, guard/Gate, scopes, source publishing, migration/sync and build. Installing or publishing source does not grant an operator, alter host auth, rename old DB tables or automatically migrate/synchronize data.

## Release verification

The PHP suite uses the package's own Composer dependencies and a committed host fixture. Laravel 12 and 13 are checked independently. CI adds the PHP 8.3/8.5 matrix, installed frontend package build, live disposable SQLite HTTP and extracted Composer ZIP inventory/idempotent source publication. The ecosystem client suite additionally covers actual SSR/hydrate/CSR, form/language/navigation guards and private projections.

No test credentials or database are installed into a consumer. Theme support consists of stable layout/slots/tokens and isolated assets; theme management will be a separate package. Production auth/scope integration, mobile/theme overrides and MySQL/Postgres concurrency remain host/release validation work before a stable admin release.
