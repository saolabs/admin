# Alpha release verification — 24 October 2026

- [GitHub v0.1.0-alpha.1](https://github.com/saolabs/admin/releases/tag/v0.1.0-alpha.1), commit `65777df8dce5fe307055a25f0277459113eb04d1`.
- [Packagist saola/admin](https://packagist.org/packages/saola/admin) indexed this tag and commit.
- [Tag CI](https://github.com/saolabs/admin/actions/runs/40108534460): all five jobs succeeded — 47 PHP tests per PHP 8.3/8.5 × Laravel 12/13 combination, plus installed frontend build, live SQLite HTTP and extracted Composer ZIP publication.
- Core 2.4.0 (`13716ad`) and compiler 3.0.0 (`6e13a38`) are on Packagist. Their isolated release suites passed 152 and 282 tests; unrelated local routing work was excluded from core.
- Client v1.3.0 (`f4cdc86`) and builder v1.2.0 (`48759a5`) are GitHub releases. Client build/packed ESM/CJS/NodeNext consumer passed; ecosystem client tests passed 560 cases. Builder passed 26 tests. No npm publication was performed.

A fresh directory with only the following Composer requirement, no custom repositories and no sibling checkout, successfully installed the published admin/core archives:

```json
{"require": {"saola/admin": "0.1.0-alpha.1"}}
```

That consumer booted the provider with panel/users still opt-in, verified install dry-run wrote no source, published frontend, migrated an in-memory SQLite database, synchronized language seeds and resolved the Vietnamese user label through DB-backed text(). A second independent test consumer installed from the Composer ZIP and passed the full 47-test suite on Laravel 13.

These checks validate the alpha scope in [RELEASE](../RELEASE.md). They do not claim User/role mutations, production identity policy, MySQL/Postgres concurrency, mobile/theme QA or AI translation are complete. No host application's auth, dependencies or database were changed during release verification.
