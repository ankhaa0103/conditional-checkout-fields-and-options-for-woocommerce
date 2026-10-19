# ARCHITECTURE — Conditional Checkout Fields and Options for WooCommerce

Хувилбар: 0.2 (2026-10-17, хэрэгжүүлэлтийн дараа шинэчилсэн). Шаардлага: [SPEC.md](SPEC.md). Classic/Block дэмжлэг: [COMPATIBILITY.md](COMPATIBILITY.md).
Доорх hook-ийн мөрийн дугаарууд нь **WooCommerce 11.2.1**-ийн эх кодоос (`C:\wamp64\www\brand.mn\wp-content\plugins\woocommerce`) шалгагдсан.

## 1. Ерөнхий бүтэц

```
                    ┌──────────────── Admin (React, REST ccfo/v1) ────────────────┐
                    │ rule list · editor · catalog · lint · import/export         │
                    └──────────────┬──────────────────────────────────────────────┘
                                   │ wp_options: ccfo_rules (JSON, autoload=no)
                    ┌──────────────▼──────────────┐
                    │ RuleRepository → RuleIndex   │  (request-д нэг удаа ачаална)
                    └──────────────┬──────────────┘
        ┌──────────────────────────▼───────────────────────────┐
        │ Engine: ContextBuilder → Evaluator → Resolver         │  ← үнэний эх сурвалж
        └───┬──────────────────┬──────────────────┬────────────┘
   Classic adapter      Store API adapter     Shared JS evaluator
 (WC hooks, AJAX)     (Blocks, ExtendSchema)  (зөвхөн UI-г шууд шинэчлэх)
```

- **Сервер бол үнэний эх сурвалж.** Payment/shipping/tax шийдвэрийг зөвхөн PHP гаргана.
- JS evaluator нь `field:*`, `payment.method`, `shipping.method` зэрэг client дээр мэдэгдэх утгаар талбарын show/hide/require-ийг шууд шинэчилнэ.
- Сагс/хэрэглэгчийн утгууд (`cart.*`, `customer.*`) серверээс context JSON болж ирнэ.

## 2. Директорийн бүтэц

```
conditional-checkout-fields-and-options-for-woocommerce.php   # header, bootstrap, compat declarations
uninstall.php
src/
  Plugin.php                      # DI container (энгийн), hook бүртгэл
  Rules/      Rule.php, Condition.php, ConditionGroup.php, RuleRepository.php,
              RuleSchema.php (validate + migrate), RuleIndex.php, Linter.php
  Engine/     Evaluator.php, Operators.php, Resolver.php, Decision.php,
              Context/ContextBuilder.php, Context/Sources/*.php (source бүр нэг class)
  Classic/    Fields.php, Payments.php, Shipping.php, TaxDisplay.php, Assets.php, Notices.php
  Blocks/     Integration.php (IntegrationInterface), StoreApi.php, Locale.php, Validation.php
  Tax/        TaxRules.php (2-р шат)
  Order/      Restrictions.php (block_checkout / block_order), Messages.php (placeholder) — 1.1
  Compat/     Woodmart.php, ...
  Admin/      Menu.php, RestController.php, FieldCatalog.php, AdminNotices.php
assets/src/   admin/, classic/, blocks/, shared/evaluator.js
build/        (@wordpress/scripts output, git-д орохгүй)
languages/
tests/        unit/, integration/, js/, e2e/, fixtures/evaluator-cases.json
tools/        wp-cli.phar (gitignored), fixtures.sh
```

## 3. Rule өгөгдлийн загвар

Хадгалалт: нэг option `ccfo_rules` (autoload = `no`).

```json
{
  "schema": 1,
  "revision": 17,
  "rules": [
    {
      "id": "r_7f3a",
      "title": "Hide COD over 500k",
      "enabled": true,
      "priority": 10,
      "target": { "type": "payment", "key": "cod" },
      "action": "disable",
      "args": {},
      "groups": [
        [ { "source": "cart.total", "operator": "gt", "value": 500000 } ]
      ]
    },
    {
      "id": "r_91bc",
      "target": { "type": "shipping", "key": "free_shipping" },
      "action": "disable",
      "groups": [
        [ { "source": "cart.categories", "operator": "any_in", "value": [23] } ]
      ]
    }
  ]
}
```

- `revision` нь admin-д optimistic locking хийнэ. PUT хүсэлт хуучин revision-тэй ирвэл 409 буцаана.
- `RuleSchema::validate()` нь §4 source/target матриц, operator/утгын төрлийг шалгана. Буруу дүрэм хадгалагдахгүй.
- `RuleSchema::migrate()` нь `schema` дугаараар шилжүүлэлт хийнэ.
- `RuleIndex` нь request бүрт `target.type → target.key → [rules]` бүтэцтэй индекс үүсгэж, static memo-д хадгална.

## 4. Engine

- **ContextBuilder** нь source-уудыг *lazy* тооцно: зөвхөн идэвхтэй дүрэмд ашиглагдсан source тооцогдоно. Орох эх сурвалжаас хамааран 3 хувилбартай:
  - `ClassicRender` (checkout хуудас render): session customer + cart
  - `ClassicAjax` (`update_order_review`): `$_POST['post_data']`-г `wp_parse_args`-аар задлана
  - `ClassicSubmit` (`wc-ajax=checkout`): `WC_Checkout::get_posted_data()`
  - `StoreApi`: cart session + request body + `extensionCartUpdate`-ээр ирсэн ccfo өгөгдөл
- **Evaluator** нь цэвэр PHP бөгөөд WordPress-ээс хамааралгүй. Ингэснээр unit тест хурдан ажиллана.
- **Resolver** нь target бүрт эцсийн `Decision`-ийг гаргана (visible, required, enabled, applied rule IDs, conflicts). Зөрчлийн бодлого: SPEC §8.
- **PHP ↔ JS parity:** `tests/fixtures/evaluator-cases.json`-ийг PHPUnit болон Jest хоёулаа уншина. Нэг fixture хоёр evaluator дээр ижил үр дүн өгөх ёстой.

## 5. Classic checkout — hook map

| Зорилго | Hook | WC 11.2.1 байршил | Тайлбар |
|---|---|---|---|
| Талбарын эх жагсаалт, required/hidden | `woocommerce_checkout_fields` (filter) | `includes/class-wc-checkout.php:321` | Woodmart 99999-д ажилладаг тул бид `PHP_INT_MAX - 10`-д ажиллана. Render үед `class[]` дээр `ccfo-hidden` нэмнэ. Submit үед `hidden=true` / `required=true` болгоно. |
| Required-ийг алгасах | `validate_posted_data()` | `class-wc-checkout.php:924` | `$required = !empty(required) && true !== hidden` — `hidden=true` болговол core required алдаа гарахгүй. ✔ |
| Нуугдсан утгыг устгах | `woocommerce_checkout_posted_data` (filter) | `class-wc-checkout.php:872` | Нуугдсан талбарын утгыг `''` болгоно → order-т хадгалагдахгүй. |
| Нэмэлт шалгалт | `woocommerce_after_checkout_validation` (action) | `class-wc-checkout.php:1070` | Хориглосон payment/shipping, бүх арга хасагдсан үеийн алдаа. |
| Payment шүүх | `woocommerce_available_payment_gateways` | `includes/class-wc-payment-gateways.php:388` | Зөвхөн checkout context-д (order-pay, my-account-д биш). |
| Shipping шүүх | `woocommerce_package_rates` | `includes/class-wc-shipping.php:390` | ⚠ §7 кэшийн асуудал. |
| Кэш түлхүүр | `woocommerce_cart_shipping_packages` | `includes/class-wc-cart.php:1733` | Package-д `ccfo_ctx` hash нэмнэ. |
| AJAX context | `woocommerce_checkout_update_order_review` | `includes/class-wc-ajax.php:408` | `post_data`-г context-д авна. |
| Client-д context дамжуулах | `woocommerce_update_order_review_fragments` | `class-wc-ajax.php:380, 514` | `<script type="application/json" class="ccfo-ctx">` fragment. |
| Хоосон үеийн мессеж | `woocommerce_no_available_payment_methods_message`, `woocommerce_no_shipping_available_html`, `woocommerce_cart_no_shipping_available_html` | `templates/checkout/payment.php:34`, `templates/cart/cart-shipping.php:67,79` | SPEC §8.3 |
| Order meta | `woocommerce_checkout_create_order` | `class-wc-checkout.php:487` | `_ccfo_applied_rules` (HPOS-safe CRUD). |
| Cart: "Proceed to checkout" (1.1) | `woocommerce_proceed_to_checkout` (action) | `includes/wc-template-hooks.php:247–248` | Priority 1-д `wc_get_pay_buttons`, `woocommerce_button_proceed_to_checkout`-ийг түр авч, идэвхгүй товч + мессеж хэвлэнэ; `PHP_INT_MAX`-д буцааж нэмнэ. Cart totals AJAX-аар дахин зурагдах бүрт шинэчлэгдэнэ. |
| Mini cart (1.1) | `woocommerce_widget_shopping_cart_buttons` | `wc-template-hooks.php:233` | `woocommerce_widget_shopping_cart_proceed_to_checkout`-ийг идэвхгүй товчоор солино. Cart fragments-аар шинэчлэгдэнэ. |
| "Place order" (1.1) | `woocommerce_order_button_html` (filter), `woocommerce_review_order_before_submit` (action) | `templates/checkout/payment.php:53` | `disabled` + мессеж. Payment хэсэг `update_order_review` бүрт дахин зурагддаг тул JS шаардлагагүй. |
| Express payment (1.1) | `woocommerce_checkout_before_customer_details` | `wc-template-hooks.php:227` | Хориглогдсон үед `wc_get_pay_buttons`-ийг харуулахгүй. |
| Захиалга татгалзах (1.1) | `woocommerce_after_checkout_validation` | `class-wc-checkout.php:1070` | `ccfo_order_blocked` алдаа. `woocommerce_check_cart_items`-ийг **ашиглаагүй**: checkout хуудсанд алдаа гарвал WC бүх формыг `cart-errors.php`-аар солих тул худалдан авагч талбараа засах боломжгүй болно. |

**Classic JS (`assets/src/classic`)**:
- Талбарын `change`/`input` (debounce 150ms), `updated_checkout`, `country_to_state_changed`, `payment_method_selected` event-уудыг сонсож, `.form-row`-д `ccfo-hidden` toggle хийнэ.
- Нуугдсан input-ийг `disabled` болгодог тул POST-д орохгүй. Сервер мөн давхар хамгаална.
- Required төлөвийг тохируулна: `validate-required` class, `<span class="required">*</span>` / `(optional)` span (WC 11-ийн `woocommerce_form_field` болон `address-i18n.js`-тэй ижил markup).
- Payment/shipping/tax дүрэмд ашиглагдсан талбар өөрчлөгдвөл `$(document.body).trigger('update_checkout')` дуудна. WC default-аар зөвхөн address талбарт үүнийг хийдэг.
- `address-i18n.js` болон Woodmart нь улс солигдоход required-ийг дахин бичдэг. Тиймээс бид тэдний event-ийн **дараа** дахин хэрэглэнэ (§9).

## 6. Checkout Block / Store API

| Зорилго | Механизм | Байршил | Тайлбар |
|---|---|---|---|
| Payment | `woocommerce_available_payment_gateways` (ижил filter) | Store API: `Routes/V1/Checkout.php:973`, `Schemas/V1/CartSchema.php:377`, `Payments/PaymentContext.php:56` | Cart response-ийн `payment_methods` болон checkout validation хоёулаа энэ filter-ээр дамжина → Classic-тай ижил шийдэл. ✔ |
| Shipping | `woocommerce_package_rates` + `validate_selected_shipping_methods()` | `StoreApi/Utilities/OrderController.php:148, 694` | Ижил filter, ижил кэш шийдэл. ✔ |
| Client төлөв серверт | `woocommerce_store_api_register_update_callback` / JS `extensionCartUpdate` | `StoreApi/Schemas/ExtendSchema.php:140` | Сонгосон payment method зэргийг session-д бичиж, totals-ийг дахин тооцуулна. |
| Context client-д | `register_endpoint_data` (cart endpoint) | `ExtendSchema.php:85` | `extensions.ccfo` дотор decision-ууд. |
| Submit validation | `woocommerce_store_api_checkout_update_order_from_request` | `StoreApi/Utilities/CheckoutTrait.php:254` | `RouteException` шидэж хориглоно. Order meta-г энд бичнэ. 1.1-ээс зөвхөн `POST` (захиалга өгөх) хүсэлтэд шалгана: ижил action draft шинэчлэх PUT/PATCH-д ч ажилладаг (WC 9.9.3, failed-payment retry). |
| Order дүрэм (1.1) | `extensions.ccfo.checkoutBlocked` / `orderBlocked` / `orderBlockedByMethod` | `StoreApi.php` | Cart/Checkout Block-д `build/blocks.js` notice (`core/notices`, context `wc/cart` / `wc/checkout`) гаргаж, товчийг барина. Checkout Block-д `wc/store/validation`-д нуугдмал алдаа нэмж submit-ийг зогсооно. Захиалгыг сервер `ccfo_order_blocked` (400)-оор татгалзана. |
| Additional fields (бусад plugin) | Тэдний өөрийн JSON-schema `required`/`hidden` rules | `Blocks/Domain/Services/CheckoutFields.php:255–309` | Бид өөрчлөхгүй, зөвхөн catalog-д "managed by owner" гэж харуулна. |
| Payment UI | — | — | `registerPaymentMethodExtensionCallbacks` **хэрэггүй** болох нь батлагдсан: Block UI нь Store API-ийн `payment_methods`-ийг дагадаг (E2E B1). |
| Block хуудсын preload | `woocommerce_available_payment_gateways` | — | Хуудас render хийхэд cart-ийг preload хийдэг тул payment дүрэм `has_block( 'woocommerce/checkout' )` хуудсан дээр ч хэрэгжинэ (E2E B1-ээр илэрсэн алдаа, засагдсан). |

**Block дээрх талбарын дүрэм — эцсийн шийдвэр:**
1. `require` дүрэм нь Block-ийн талбаруудад (billing/shipping хаяг, `billing_email`, `order_comments`) захиалга илгээх үед серверт шалгагдана (`Blocks\StoreApi::validate_order`, E2E B3). Форм дээр `*` гарахгүй.
2. `show`/`hide` дүрэм Block-д **хэрэгжихгүй**. `woocommerce_get_country_locale`-ийг ашиглах хувилбараас татгалзсан. Шалтгаан: зөвхөн улсаар нөхцөлдөнө, billing/shipping хоёрт ижил үйлчилнэ, мөн Classic-ийн `address-i18n.js`-д давхар нөлөөлнө. Linter нь дүрэм бүрт "classic only" анхааруулга гаргана.
3. Additional Checkout Fields API-ийн талбарыг эзэмшигч plugin нь удирдана. Catalog нь тэдгээрийг `additionalFields` нэрээр тусад нь буцаадаг (`StoreInfo::additional_fields()`). Admin дээр "өөрийн plugin-оор удирдагддаг" гэж харуулна, rule-ийн target болгон санал болгохгүй.

## 7. Shipping rate-ийн кэш (чухал)

`WC_Shipping::calculate_shipping_for_package()` нь rate-уудыг session-д `package_hash`-аар кэшилдэг. `woocommerce_package_rates` filter нь **зөвхөн кэш шинэчлэгдэх үед** ажилладаг (`class-wc-shipping.php:329–405`).

Тиймээс shipping дүрэм package-аас гадуурх утгаас (`customer.role`, `field:*`, `payment.method`) хамаарвал хуучин rate гарах эрсдэлтэй.

**Шийдэл:** `woocommerce_cart_shipping_packages` filter-ээр package бүрт `ccfo_ctx = md5(shipping дүрэмд ашиглагдсан source-уудын утга + ccfo_rules revision)` нэмнэ. Hash өөрчлөгдөхөд WC өөрөө дахин тооцоолно. Энэ шийдэл 9.9.3 дээр ч ажиллана. `woocommerce_shipping_package_hash_ignored_fields` нь 11.0+ тул ашиглахгүй.

## 8. Татвар

- **`hide_tax_note` (1-р шат) — зөвхөн харагдац:**
  - Шалгасан баримт: order tax item нь `get_cart_contents_taxes()`-ээс үүсдэг (`class-wc-checkout.php:705`), `get_tax_totals()`-ийг ашигладаггүй. Нэг мөрийн горим (`review-order.php:112`) `get_taxes_total()`-ийг ашигладаг тул өгөгдлийн filter хангалтгүй. Store API `tax_lines` (`CartSchema.php:420`)-ийг бусад extension хэрэглэдэг.
  - Тиймээс **өгөгдлийн filter ашиглахгүй**. Сервер шийдвэр гаргаж, `body`-д `ccfo-hide-tax-note` class тавина (render + `update_checkout` fragment + Block `extensions.ccfo`). CSS нь `small.includes_tax` болон Block-ийн "Including … in taxes" тайлбарыг нууна.
  - Classic-ийн татварын мөр (`review-order.php:103`) зөвхөн exclusive горимд зурагддаг, харин exclusive горимд нуухыг SPEC хориглосон тул `hide_tax_rows` action **байхгүй**. Linter нь exclusive горимд `hide_tax_note`-д "нөлөөгүй" анхааруулга өгнө. Зөвхөн checkout хуудсанд үйлчилнэ.
- **`set_vat_exempt` (2-р шат):**
  - `woocommerce_before_calculate_totals` дээр `WC()->customer->set_is_vat_exempt()` (`class-wc-customer.php:393`) хэрэглэнэ. Энэ нь Classic болон Store API-ийн бүх тооцоололд ажиллана.
  - WC core нь exemption-ийг reset хийдэггүй. Тиймээс бид өөрсдийн тавьсан exemption-ийг session-д (`ccfo_vat_exempt`) тэмдэглэж, зөвхөн түүнийгээ буцааж авдаг. Бусад кодын тавьсан exemption-д хүрэхгүй (integration тест).
- **`set_tax_class` (2-р шат):**
  - Зөвхөн байгаа class руу шилжүүлнэ (RuleSchema env `tax_classes`).
  - `woocommerce_cart_totals_get_item_tax_rates` (`class-wc-cart-totals.php:524`) нь сагсны татварыг тооцно. `woocommerce_checkout_create_order_line_item` нь order line-д ижил class бичнэ. Ингэснээр admin дахин тооцоолоход ижил үр дүн гарна. Shipping-ийн татварын class өөрчлөгдөхгүй.
- Totals-ийг бид хэзээ ч өөрсдөө тооцохгүй.

## 9. Нийцэл

| Компонент | Асуудал | Шийдэл |
|---|---|---|
| Woodmart checkout fields manager (`woodmart/inc/integrations/woocommerce/modules/checkout-fields/class-frontend.php`) | `woocommerce_checkout_fields` @99999 дээр талбар unset/required өөрчилдөг. Locale filter-үүд, JS localized `required`. | Бид дараа нь (`PHP_INT_MAX-10`) ажилладаг тул эцсийн талбаруудыг харна. Unset хийгдсэн талбар catalog-д орохгүй, linter "not active" гэж анхааруулна. Улс солигдоход JS дүрмийг хамгийн сүүлд (`setTimeout 0`) дахин хэрэглэнэ. Тусдаа `Compat/Woodmart.php` хэрэггүй болсон. Тест сайтад manager унтраастай байсан. |
| Woodmart checkout layout | Checkout хуудас болгон тохируулсан хуудсан дээр Checkout Block-ийг classic layout-оор солидог. | Энэ тохиолдолд Classic-ийн бүх дүрэм ажиллана. Block-ийн E2E нь тусдаа хуудсан дээр явагдана. |
| WC `address-i18n.js` | Улс солигдоход required/label-ийг дахин бичдэг. | `country_to_state_changing/changed`-ийн дараа locale талбаруудын шинэ required төлвийг base болгож, дүрмээ дахин хэрэглэнэ. |
| HPOS | — | `FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)`. Order-т зөвхөн CRUD ашиглана. |
| Blocks | — | `declare_compatibility('cart_checkout_blocks', __FILE__, true)` |

## 10. Admin

- Цэс: **WooCommerce → Checkout Rules** (`manage_woocommerce`).
- UI нь React (`@wordpress/components`, `@wordpress/data`, `@wordpress/api-fetch`) дээр бичигдэнэ.
- Дэлгэцүүд:
  - Rules (жагсаалт, toggle, priority, ⚠ зөрчил)
  - Editor (target → action → groups)
  - Messages (хоосон үеийн текстүүд)
  - Tools (JSON import/export)
- REST `ccfo/v1`:
  - `GET/PUT /rules` (`revision` шалгана)
  - `POST /lint`
  - `GET /catalog` нь fields, gateways, shipping methods + zone instances, roles, countries/states, tax classes, categories/tags/shipping classes (≤1000 тус бүр)-ийг буцаана.
  - `GET /products?search=|include[]=` нь бүтээгдэхүүний хайлт болон ID-аас нэр олох үйлдлийг хийнэ.
  - `GET/POST /settings` нь хэрэглэгчид харагдах мессежүүдийг хадгална. `GET/DELETE /blocked` нь "бүх арга хасагдсан" event-үүдийг харуулж, цэвэрлэнэ.
- **FieldCatalog:**
  - REST handler дотор `wc_load_cart()` дуудаад `WC()->checkout()->get_checkout_fields()` авна.
  - Үүнээс гадна checkout хуудас render хийгдэх бүрт snapshot (`ccfo_field_snapshot`) хадгалж, live жагсаалттай нэгтгэнэ.
  - Талбар бүрт `block: submit|none` гэж тэмдэглэнэ.
- **Linter:**
  - Эсрэг action-тай, ижил priority-тэй давхцал илрүүлнэ.
  - Бүх gateway/rate-ийг хасах боломжтой дүрмийг илрүүлнэ.
  - Устгагдсан gateway/талбар/term руу заасан дүрмийг олно.
  - Зөвшөөрөгдөөгүй source/target хослолыг шалгана.

## 11. Тест стратеги

| Түвшин | Хэрэгсэл | Хамрах хүрээ | Орчин |
|---|---|---|---|
| Unit | PHPUnit 10 (PHP 8.1 platform) | Evaluator, Operators, Resolver, RuleSchema, Linter | WP-гүй |
| JS unit | Jest (`@wordpress/scripts`) | shared evaluator (ижил fixture), classic DOM toggle | jsdom |
| Integration | PHPUnit + `wp-load.php` | Hook-ууд, Store API хүсэлтүүд (`rest_do_request`), кэш, order meta, order дүрэм (бодит Store API checkout-оор), дүнгийн source-ууд горим бүрт | brand.mn DB. Fixture-ийг `ccfo-test-` prefix-тэй үүсгээд цэвэрлэнэ. |
| E2E | Playwright (`ignoreHTTPSErrors`) | SPEC §11 сценари Classic + Block | https://brand.mn |

**Fixture (`tools/fixtures.php setup|teardown`, WP-CLI eval-file):**
- Бараа: энгийн, `heavy` ангилалтай.
- Shipping zone MN: flat_rate, free_shipping, local_pickup.
- Payment: COD, BACS асаана.
- Хэрэглэгч: customer, `ccfo_wholesale` role, test admin.
- Block checkout тест хуудас, Block cart тест хуудас (1.1, `WC_Install::get_cart_block_content()`).
- Test-only mu-plugin (`tests/e2e/mu-plugins/ccfo-test-fields.php`): гуравдагч талын талбарыг дуурайх `billing_vat` (classic).
- Block checkout хуудас нь WooCommerce-ийн `WC_Install::get_checkout_block_content()` markup-аар үүснэ (inner block-гүй бол classic формоор render хийгддэг).
- Татварын тест (A11, A12) ажиллах үед `woocommerce_calc_taxes`-ийг түр асааж, дараа нь буцаана.

**WC 9.9.3 нийцэл:** brand.mn-ийн өгөгдлийг эрсдэлд оруулахгүйн тулд WooCommerce-ийг downgrade хийгээгүй. Оронд нь 9.9.3-ийн эх кодоос ашигласан hook/API бүрийг статик байдлаар шалгасан (COMPATIBILITY.md). Ажиллуулж турших нь тусгаарлагдсан орчин шаардана.

## 12. Milestone-ууд

| M | Агуулга | Дууссаны шалгуур |
|---|---|---|
| M0 | Tooling: composer/npm, PHPCS/PHPStan/PHPUnit/Jest/Playwright config, plugin header + compat declarations, junction + activate, WP-CLI, fixtures script | Хоосон plugin notice-гүй идэвхжинэ. Бүх команд ногоон. |
| M1 | Rule model, schema, Evaluator/Resolver (PHP) + JS evaluator + shared fixtures | §4, §5-ийн бүх хослол fixture-тэй, PHP=JS |
| M2 | Admin UI + REST + FieldCatalog + Linter | Дүрэм үүсгэх/засах/import/export. A10-ийн анхааруулга харагдана. |
| M3 | Classic талбар: show/hide/require, submit validation, утга устгах, Woodmart compat | A1, A2, A3, A8 (C) |
| M4 | Classic payment/shipping/tax display, кэш hash, хоосон үеийн бодлого | A4–A7, A9, A11 (C) |
| M5 | Block: spike → COMPATIBILITY.md, Store API extension, payment/shipping parity, field дэмжлэг §6 | A1, A3–A7, A9 (B) |
| M6 | Татварын тооцооллын дүрэм | A12 (C+B), totals ижил |
| M7 | Adapter-ууд, i18n, readme, zip, MANUAL-TEST, WC 9.9.3 шалгалт | SPEC §12 |
| M8 (1.1) | Дүнгийн source-ууд, ангиллын дэд дүн, order дүрэм (Classic + Block), placeholder, linter | SPEC §13, A13–A17 |

## 13. Эрсдэл ба нээлттэй асуулт

1. **Block core талбар** (§6): "бүх талбар" гэж амлахгүй. Дэмжлэгийн түвшинг admin болон COMPATIBILITY.md-д ил харуулна.
2. ~~Татварын мөр нуух нь order tax line-д нөлөөлөх эсэх~~ — шийдэгдсэн (§8): харагдацын түвшинд л хэрэгжинэ.
3. Classic дээр payment method солигдоход WC default-аар totals дахин тооцогдохгүй. Payment-аас хамаарсан татварын дүрэм байвал л `update_checkout` trigger хийнэ.
4. Store API checkout дээр `payment_method`-ийг сонгох хүртэл серверт мэдэгдэхгүй. Payment-аас хамаарсан tax дүрэмд `extensionCartUpdate` шаардлагатай (M6). 1.1: түүний cart хариу WooCommerce серверт хараахан илгээгээгүй хаягийн утгыг дарж бичдэг нь E2E-гээр илэрсэн; одоо (а) юу ч сонгоогүй үед сервер Block шиг эхний боломжит аргыг ашиглаж, `extensions.ccfo.paymentMethod`-оор мэдэгдэх тул ачаалах үед синк хийхгүй; (б) хэрэглэгч солих үед локал хаягийн сүүлийн өөрчлөлтөөс хойш 3.5 сек (WC хаягийг ~2–3 сек-ийн дараа илгээдэг) ба `isCustomerDataUpdating()` дуустал хүлээж, хамгийн ихдээ 10 сек-ийн дараа илгээнэ (`assets/src/blocks/sync.js`). `getCartData()`-ийн хаяг локал засварыг шууд тусгадаг тул "серверийн хуулбартай харьцуулах" боломжгүй. E2E нь хүлээлтгүй хувилбар дээр 3/3 унаж, засвартай 3/3 давсан. Order дүрэмд синк хэрэггүй: сервер төлбөрийн арга бүрийн мессежийг (`orderBlockedByMethod`) урьдчилан өгнө.
5. WC 9.9.3: статик байдлаар шалгасан, ажиллуулж туршаагүй.
6. ~~Block талбарын дүрэм~~ — шийдсэн (§6): `require`-ийг submit үед шалгана, `show`/`hide` нь Classic-д л ажиллана.
7. Order дүрэм (1.1): өөрийн урсгалаар захиалга үүсгэдэг express payment товч серверийн шалгалтаас гадуур гарч болзошгүй. Хориглогдсон үед cart/checkout дээр `wc_get_pay_buttons`-ийг харуулахгүй. Mini-Cart **block**-ийн товчийг удирдахгүй (Checkout Block захиалгыг хориглоно).

## 14. Захиалгын хязгаарлалт ба дүнгийн source (1.1)

**Шийдвэрүүд:**

- **Тусдаа `order` target, хоёр action.** `block_checkout` = cart шат (cart, mini cart) + checkout шат; `block_order` = зөвхөн checkout шат. Аль ч биелсэн дүрэм хориглоно, priority зөвхөн мессежийн дарааллыг тогтооно (`Order\Restrictions::decide()`).
- **Сервер л шийднэ.** Classic-д товч, мессеж нь WooCommerce-ийн серверээр зурдаг хэсэгт (cart totals, payment section, mini cart fragments) байдаг тул тусгай JS хэрэггүй. Block-д `extensions.ccfo`-ийн мессежийг `assets/src/blocks/order.js` харуулна.
- **Placeholder** (`Order\Messages`): биелсэн group-ийн нөхцөлөөс `{min}`/`{remaining}` (`lt`/`lte`), `{max}`/`{excess}` (`gt`/`gte`), `{current}` гаргана. Мөнгийг `wc_price()`, жинг нэгжтэй форматална. Unit тест нь форматлагчийг орлуулж WP-гүй ажиллана.
- **Дүнгийн source-ууд:** `WC_Cart` getter-ээр (`get_subtotal_tax`, `get_cart_contents_total`, `get_discount_total`, `get_shipping_total`, `get_total_tax`, `get_applied_coupons`). `calculate_item_totals()` нь `calculate_shipping_totals()`-оос өмнө ажилладаг тул (`class-wc-cart-totals.php:153`) хөнгөлөлтийн дараах дүнг shipping дүрэм уншиж болно. `tax_calc` нь totals-оос өмнө ажилладаг тул эдгээрийг уншихгүй.
- **Ангиллын дэд дүн, барааны үнэ:** totals-аас биш `wc_get_price_excluding_tax()`-аас, мөр бүрийг үнийн нарийвчлалаар бөөрөнхийлж тооцно. Тиймээс `tax_calc` дүрэм ч тухайн тооцооны шинэ тоог шууд хардаг (integration тестээр баталсан). Variation-ий ангилал эцэг бүтээгдэхүүнээс, дэд ангилал эцэгтээ тооцогдоно.
- **Checkout Block дахь төлбөрийн арга:** order дүрэм `payment.method` уншвал cart data-д төлбөрийн арга бүрийн мессеж (`orderBlockedByMethod`) ирнэ. JS идэвхтэй аргынхыг шууд сонгоно, серверт синк хийхгүй.
