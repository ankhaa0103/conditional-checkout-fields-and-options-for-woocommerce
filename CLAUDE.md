# CLAUDE.md — Conditional Checkout Fields and Options for WooCommerce

Энэ файл нь энэ repo дээр ажиллах агентын **байнгын дүрэм**. Бүтээгдэхүүний шаардлага: [docs/SPEC.md](docs/SPEC.md). Техникийн шийдвэрүүд: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Ажлын дүрэм (заавал)

1. **Hook/API-г таамаглахгүй.** WooCommerce-ийн hook, class, method ашиглахаасаа өмнө локал эх кодоос шалгана:
   `C:\wamp64\www\brand.mn\wp-content\plugins\woocommerce\` (одоогоор WC 11.2.1). Plugin-ий доод хувилбар 9.9.3 тул шинэ API ашиглахдаа `@since`-ийг шалгаж, 9.9.3-д байхгүй бол fallback бич.
2. **Сервер бол үнэний эх сурвалж.** JS зөвхөн UI-г шууд шинэчилнэ. Захиалга илгээх үед дүрэм бүрийг PHP дахин шалгана.
3. **Milestone бүрийн төгсгөлд:** `composer lint`, `composer analyse`, `composer test` ажиллуулж, үр дүнг тайлагнаад commit хийнэ. Улаан тесттэй commit хийхгүй.
4. **Шалгаагүй зүйлийг "ажиллаж байна" гэж бичихгүй.** Тайланд "туршсан / туршаагүй"-г тусад нь бич.
5. **Хамрах хүрээнээс гарахгүй.** Талбар нэмэх/устгах, label/placeholder/default засах, gateway/shipping method үүсгэх, нэмэлт төлбөр, VIES, татварын хувь зохиох, checkout дизайн/дараалал — **хийхгүй** (SPEC §6).
6. Totals-ыг өөрөө тооцохгүй. Зөвхөн WooCommerce-ийн тооцооллын hook-оор нөлөөлнө.

## Орчин

| Зүйл | Утга |
|---|---|
| Тест сайт | `https://brand.mn` → `C:\wamp64\www\brand.mn` (hosts → 127.0.0.1, self-signed SSL) |
| WP / WC | 7.1.3 / 11.2.1, HPOS асаалттай, Classic checkout (page 9, `[woocommerce_checkout]`) |
| Theme | Woodmart + child (өөрийн checkout fields manager-тэй — ARCHITECTURE §9) |
| PHP | 8.4.26 (CLI ба Apache). `C:\wamp64\bin\php\php8.4.26\php.exe` |
| DB | MySQL 9.7, DB `brand`, user `root` (нууц үггүй), prefix `br_` |
| DB backup | `C:\wamp64\backups\brand-before-ccfo-2026-10-17.sql` — сэргээх: `mysql -uroot brand < файл` |
| Tools | Composer, Node 26 / npm 11, git. WP-CLI: `tools/wp-cli.phar` (gitignored) |
| wp-config | WP_DEBUG/WP_DEBUG_LOG асаасан, backup: `C:\wamp64\backups\wp-config-before-ccfo.php` |

Plugin-ийг тест сайтад **хуулж** байршуулна: `powershell -File tools/deploy.ps1` (анхдагч нь brand.mn, `-Site testwoo.mn` гэж өөр сайт сонгоно).

⚠️ **Junction/symlink хэзээ ч бүү ашигла.** 2026-10-18-нд WordPress-ийн "Upload Plugin → Replace current with uploaded" үйлдэл хуучин plugin хавтсыг устгахдаа junction-ийг дагаж, энэ repo-ийн агуулга болон `.git` түүхийг устгасан. Сайт дээрх plugin хавтас нь зөвхөн хуулбар байх ёстой.

## Командууд

```bash
composer install && npm install
composer lint        # PHPCS (WPCS + WooCommerce sniffs)
composer analyse     # PHPStan level 6 (WP + WC stubs)
composer test        # PHPUnit unit (WP-гүй, хурдан)
composer test:int    # PHPUnit integration (phpunit-integration.xml.dist, brand.mn-ийн wp-load.php)
npm run build        # @wordpress/scripts → build/
npm run test:js      # Jest (JS evaluator, shared fixtures)
npm run test:e2e     # Playwright → https://brand.mn
php tools/wp-cli.phar --path=C:/wamp64/www/brand.mn <cmd>
php tools/wp-cli.phar --path=C:/wamp64/www/brand.mn eval-file tools/fixtures.php setup|teardown
npx wp-scripts lint-js assets/src tests/js tests/e2e
php tools/wp-cli.phar i18n make-pot . languages/conditional-checkout-fields-and-options-for-woocommerce.pot --exclude=vendor,node_modules,tests,tools,assets/src,test-results
```

Integration болон E2E тест нь fixture шаарддаг.

E2E хурдны дүрэм (`tests/e2e/helpers.js`):
- Admin хуудсанд `h.useAdmin( page )` ашиглана. Энэ нь `global-setup.js`-ийн хадгалсан cookie-г нэмдэг. Хуудсаар нэвтрэхийг зөвхөн admin биш хэрэглэгчид ашиглана (`h.login( page, 'ccfo_customer' )`). Тохиргоонд `test.use({ storageState })` бүү хэрэглэ: `browser.newPage()`-ээр нээсэн худалдан авагчийн хуудсууд ч admin болно.
- Сагсыг `h.fillCart()` (Store API) дүүргэнэ. Navigation хийхдээ `waitUntil: 'domcontentloaded'` ашиглана. Classic checkout-ыг `h.openClassicCheckout()`-оор нээнэ, энэ нь `update_order_review`-ийг хүлээдэг.
- WP-CLI-ийг `h.wp()`-оор дуудна: Xdebug унтраастай, хувийн OPcache кэш (`.cache/opcache-cli`) ашиглаж, crash гарвал кэшийг цэвэрлээд дахин оролдоно. Option унших, `ccfo_*` option бичих, user meta үйлдэлд plugin ачаалахгүй. WooCommerce-ийн option бичих эсвэл `eval` хийхэд plugin ачаална. Байхгүй байж болох option-ийг `h.deleteOption()`-оор устгана.
- `workers: 1` хэвээр: тестүүд нэг `ccfo_rules` option-ийг хуваалцдаг.

Тестийн бүтэц (E2E-ээс бусад):
- `tests/unit/MatrixTest.php` + `tests/js/matrix.test.js`: `tests/fixtures/matrix.json` (operator × утга, action-уудын хослол, AND/OR хэлбэр) дээр PHP = JS = SPEC oracle. Зан төлөвийг зориуд өөрчилбөл `UPDATE_MATRIX=1 npm run test:js`-ээр дахин үүсгэнэ.
- `tests/unit/SchemaMatrixTest.php`: SPEC §3–§5-ийн хүснэгтүүдийг кодоос биш SPEC-ээс хуулж, бүх target × source × operator хослолыг шалгана. SPEC өөрчлөгдвөл энэ хүснэгтийг эхэлж засна.
- `tests/integration/`: `SourcesTest` (source бүр горим бүрт), `HookMatrixTest` (target × action WooCommerce hook-оор, серверийн татгалзал), `AdminApiTest` (REST, catalog, тохиргоо, header, HPOS), `SpecExtrasTest` (татвар, priority, uninstall).
- `tests/js/runtime.test.js`: classic runtime-ийг jsdom + jQuery 3.7.1 дээр шалгана. Tooling-ийн тэмдэглэл: TypeScript 5-ийг dev dependency болгон тогтоосон (ESLint-ийн TS plugin TS 6-тай нийцдэггүй). Windows дээр Python-оор файл бичихдээ `newline=''` ашиглана, эс бөгөөс CRLF болж PHPCS алдаа өгнө.

## Кодын стандарт

- PHP 8.1+ syntax, `declare(strict_types=1);`, namespace `CCFO\`, Composer PSR-4 (`src/`).
- WordPress Coding Standards (PHPCS), гэхдээ PSR-4 файлын нэр зөвшөөрнө.
- Prefix: функц/option/hook `ccfo_`, JS global `ccfo`, REST namespace `ccfo/v1`, CSS `.ccfo-`.
- Text domain: `conditional-checkout-fields-and-options-for-woocommerce`. Бүх UI string англиар + `__()`.
- Security: `manage_woocommerce` capability, REST `permission_callback`, nonce, input sanitize / output escape.
- Order өгөгдөл зөвхөн WC CRUD (`$order->update_meta_data()`), шууд SQL/post meta хэрэглэхгүй (HPOS).
- Тест бүрт fixture-ээ өөрөө үүсгээд цэвэрлэнэ. Тест сайтын бодит өгөгдлийг устгахгүй.
