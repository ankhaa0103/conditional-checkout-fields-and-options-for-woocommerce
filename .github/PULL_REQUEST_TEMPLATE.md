## What and why

<!-- What does this change, and why is it needed? Link the issue: "Fixes #123". -->

## How it was tested

<!-- Site, WordPress / WooCommerce / PHP versions, checkout type (classic or Checkout Block), steps. -->

## Checklist

- [ ] Within the scope of [docs/SPEC.md](../docs/SPEC.md) (no new fields, labels, fees, VIES, tax rates or layout changes)
- [ ] WooCommerce hooks/classes checked in the source; works with WooCommerce 9.9.3+
- [ ] Rules are still enforced on the server (classic checkout and Store API)
- [ ] PHP and JS evaluators still match (`UPDATE_MATRIX=1 npm run test:js` reviewed if behaviour changed)
- [ ] `composer lint`, `composer analyse`, `composer test`, `composer test:int` pass
- [ ] `npm run lint:js`, `npm run test:js`, `npm run test:e2e:smoke` pass
- [ ] Tests added or updated
- [ ] `CHANGELOG.md` updated; translation template regenerated if strings changed
- [ ] Docs updated (`docs/SPEC.md`, `docs/ARCHITECTURE.md`, `docs/COMPATIBILITY.md`, `README.md`) where needed
