# saola/admin v0.1.0-alpha.4

- Theme view names follow the source path: `admin.themes.<theme>.*` (was `admin.<theme>.*`); host sao.config.json must use the `admin.themes.*` keys and `admin/themes/<theme>` Blade directories. Shared screens stay in `pages/`; `modules/` is for host screens (`panel.host_modules`).
- Inputs: one file per type in `components/inputs/`; `components/input.sao` only lists the kinds and includes `inputs/{template}` (fallback `text`).
- Placeholders: settings fields accept `placeholderKey`; defaults, languages, media and search now carry placeholders (vi/en).
- Table edges align with the card's 16px gutter.
- Languages: optional country and currency per locale (migration `add_locale_country_currency`); settings store sanitizes `richtext` values.

# saola/admin v0.1.0-alpha.3

Settings: new write-only `secret` field type (encrypted with APP_KEY, masked on every read, never public or localized; blank keeps the stored value).

# saola/admin v0.1.0-alpha.2

Host-screen mechanism (`panel.host_modules`, `HostScreenController`), one-table permission `matrix` input (`Resource::fieldMatrix`), `.sao` themes in script-first layout, `admin` prefix as the default panel path. Requires `@saolabs/client` v1.4.0 (adds `App.Admin`); v1.3.0 is not enough.

Install this release with `composer require saola/admin:0.1.0-alpha.2`, compiler `^3.0`, client GitHub tag `v1.4.0` and builder GitHub tag `v1.2.0`. Existing publications keep their customized files through the source publisher. Run publication dry-run, migrate, language sync and rebuild explicitly. The alpha.1 and previously unreleased sections below are historical scope notes; their source additions are included in alpha.2.

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

## Unreleased — language management

Source after alpha.1 adds the language management page, locale metadata and DB defaults for UI/fallback/content, actor-bound JSON download/upload preview/draft/publish and separate capabilities. Requires the additive locale-management migration, frontend publish/build and language seed sync. This is not included in the alpha.1 Packagist tag and no replacement tag has been published. See [language management and upgrade](docs/LANGUAGE_MANAGEMENT.md).

## Unreleased — settings groups and media library

Source after alpha.1 includes Website/System settings subgroups and one shared set of operational settings. A reusable media library/picker provides upload, search/type filters, metadata revision checks, recoverable trash and selection for branding/SEO. Requires the media migration, host Gates, source publish/build and language sync. Media bytes live on a Laravel filesystem disk, outside frontend source; public UUID URLs remain stable across metadata changes, trash and panel disablement. No new release tag or Packagist publication was performed. See [media integration](docs/MEDIA_LIBRARY.md).

Source theme update (unreleased): UI now lives in admin/themes/{theme}/, with Classic retained and Material using supplied distribution assets. See docs/THEMES.md for manifests, context selection, independent CSS and ownership-preserving source migration. This does not change an existing release tag.


## Unreleased — reusable inputs and interface consistency

The package now includes Classic/Material under `resources/saola/admin/themes/{name}`, shared view controllers, declarative Resource list/form definitions and 26 input presets. Form sections/tabs, scoped relation options, array/JSON values, media selection and basic Markdown/HTML source editing use one implementation across themes. HTML source editing is not WYSIWYG; relation options are bounded, not remotely paginated.

Interface review corrected dropdown anchoring/arrows, removed redundant signed-in captions, compacted media/search/empty states and language toolbars, and added consistent feedback severity and Settings field-error focus/discard reset. See [UI contract](docs/UI_CONTRACT.md) and [Resource integration](docs/RESOURCES.md). These source changes do not publish a release or expand the capabilities of an existing tag.

## Unreleased — context package source builds (P4)

Composer metadata now exposes the context manifest and its type entry. The matching builder can build installed package sources with per-file project overrides, generated aliases/declarations, isolated theme assets and separate theme registry chunks. The matching core resolves generated CSS sources; admin exports the selected theme and trusted bundle URL classifications through request-scoped shared data. `admin:doctor` supports this source mode without requiring a source publication. Existing managed publications continue until deliberately migrated. See [installation](docs/INSTALLATION.md).

Requires the coordinated P1/P4 client, core and builder sources; no release tag was changed. The client source fixtures were updated to register the P3 `App.Admin` namespace and constructor correctly.

## Unreleased — canonical app source migration

The `_shared/app` re-export tree has been removed from runtime source. Manifest renames and publisher ownership checks migrate old publications into `app/`, aborting on local changes or custom bootstrap imports of removed paths. Templates consume `App.Admin`; client fixtures now import canonical source. Gehihi was migrated through the publisher, retaining its bootstrap, with the matching local client archive installed for factory support. Registry tags have not been updated; run the host's installed-client runtime check before accepting a build.
