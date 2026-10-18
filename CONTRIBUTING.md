# Contributing

Thank you for helping improve Conditional Checkout Fields and Options for WooCommerce. This guide explains how to set up the project, the standards the code follows, and what a pull request needs.

## Before you start

- Read [docs/SPEC.md](docs/SPEC.md) (what the plugin does and does not do) and [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) (how it does it).
- Out of scope, by design: adding or removing checkout fields, changing labels/placeholders/defaults, creating gateways or shipping services, fees, VAT number validation, creating tax rates, checkout layout. Proposals in these areas will be declined.
- For a larger change, open an issue first so the approach can be agreed.

## Development setup

Requirements: PHP 8.1+, Composer, Node.js and npm, and a local WordPress site with WooCommerce 9.9.3+.

```bash
composer install
npm ci
npm run build
```

Deploy the plugin to your local site **as a copy**:

```powershell
powershell -File tools/deploy.ps1 -Site your-site.test
```

> Do not symlink or junction the repository into `wp-content/plugins`. Deleting or replacing the plugin from the WordPress admin follows the link and deletes the repository's files.

Create test data for integration and E2E tests:

```bash
php tools/wp-cli.phar --path=<site path> eval-file tools/fixtures.php setup
# remove everything again
php tools/wp-cli.phar --path=<site path> eval-file tools/fixtures.php teardown
```

## Rules for code changes

1. **Check WooCommerce before using it.** Verify every hook, class and method in the WooCommerce source. The minimum supported version is 9.9.3; if an API is newer, add a fallback.
2. **The server decides.** JavaScript only updates the page early; every rule is enforced again in PHP when the order is placed.
3. **Never calculate totals.** Influence taxes and totals only through WooCommerce's own calculation hooks.
4. **Keep PHP and JS evaluators identical.** `src/Engine/Operators.php`, `Evaluator.php`, `Resolver.php` and `assets/src/shared/evaluator.js` are mirrors. After an intended behaviour change, regenerate the parity matrix with `UPDATE_MATRIX=1 npm run test:js` and review the diff of `tests/fixtures/matrix.json`.
5. **The spec is the source of truth.** `tests/unit/SchemaMatrixTest.php` copies the spec's tables. Change `docs/SPEC.md` and that test together with the code.
6. **Orders through CRUD only** (`$order->update_meta_data()`), no direct SQL or post meta (HPOS).

## Coding standards

- PHP 8.1 syntax, `declare(strict_types=1);`, namespace `CCFO\`, PSR-4 files in `src/`.
- WordPress Coding Standards (PHPCS) and PHPStan level 6.
- Prefixes: functions, options and hooks `ccfo_`; REST namespace `ccfo/v1`; CSS `.ccfo-`.
- All UI text in English through the i18n functions with the text domain `conditional-checkout-fields-and-options-for-woocommerce` as a literal string (JS too, so `make-pot` finds it).
- Security: `manage_woocommerce` capability, REST `permission_callback`, sanitise input, escape output.
- Files use LF line endings (`.gitattributes`).

## Tests

Run before every pull request:

```bash
composer lint
composer analyse
composer test
composer test:int        # needs the local site and fixtures
npm run lint:js
npm run test:js
npm run test:e2e:smoke   # needs the local site and fixtures
```

Run the full `npm run test:e2e` when a change touches several areas. Add tests with every change:

| Change | Test |
|---|---|
| Operator, evaluator or resolver | regenerate the parity matrix; add a hand-written case to `tests/fixtures/evaluator-cases.json` |
| New source, target or action | `SchemaMatrixTest`, `SourcesTest`, `HookMatrixTest` |
| WooCommerce hook behaviour | `tests/integration/` |
| Checkout script | `tests/js/runtime.test.js` or `tests/js/refresher.test.js` |
| User-visible flow | an E2E test; tag it `@smoke` if it represents a rule type not covered by the smoke set |

## Commits and pull requests

- Use [Conventional Commits](https://www.conventionalcommits.org/): `feat:`, `fix:`, `test:`, `docs:`, `chore:`, `refactor:`.
- One logical change per pull request.
- Update `CHANGELOG.md` under *Unreleased*.
- Regenerate the translation template when strings change:
  ```bash
  php tools/wp-cli.phar i18n make-pot . languages/conditional-checkout-fields-and-options-for-woocommerce.pot --exclude=vendor,node_modules,tests,tools,assets/src,test-results,.cache
  ```
- Fill in the pull request template checklist.

## License

By contributing you agree that your contributions are licensed under GPL-2.0-or-later.
