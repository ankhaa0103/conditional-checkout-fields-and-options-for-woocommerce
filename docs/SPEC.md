# SPEC — Conditional Checkout Fields and Options for WooCommerce

Хувилбар: 1.1 (2026-10-20; 0.1 — 2026-10-17). Техникийн хэрэгжүүлэлт: [ARCHITECTURE.md](ARCHITECTURE.md).

## 1. Зорилго

Checkout-ийн **одоо байгаа** элементүүдэд нөхцөлт дүрэм ажиллуулна:

- checkout талбарууд (billing, shipping, order, account, бусад plugin-ий бүртгэсэн талбар)
- төлбөрийн аргууд (ба тэдгээрийн дагалдах талбар)
- хүргэлтийн аргууд / rate-ууд (ба хаягийн талбарууд)
- татварын талбар, татварын мэдээллийн харагдац; 2-р шатанд татварын тооцоолол
- захиалгын хязгаарлалт (1.1): нөхцөл биелвэл checkout руу орох эсвэл захиалга өгөхийг хориглож, худалдан авагчид мессеж харуулна (§13)

Plugin шинэ талбар үүсгэхгүй, байгаа талбарын label/placeholder/default-ийг өөрчлөхгүй.

## 2. Нэр томъёо

| Нэр | Утга |
|---|---|
| **Rule** | Нэг target дээр нэг action, нэг буюу хэд хэдэн condition group. |
| **Target** | Дүрэм үйлчлэх зүйл: `field`, `payment`, `shipping`, `tax_display`, `tax_calc`, `order`. |
| **Action** | `show`, `hide`, `require`, `enable`, `disable`, `hide_tax_note`, `set_vat_exempt`, `set_tax_class`, `block_checkout`, `block_order`. |
| **Condition group** | Condition-уудын жагсаалт. Group дотор **AND**, group-уудын хооронд **OR**. |
| **Source** | Condition-ий шалгах утгын эх үүсвэр (§4). |
| **Context** | Тухайн хүсэлтийн бүх source-ийн утгууд (сервер дээр тооцогдоно). |

## 3. Action-уудын утга (semantics)

| Target | Action | Нөхцөл биелсэн үед | Нөхцөл биелээгүй үед |
|---|---|---|---|
| field | `show` | харагдана | **нуугдана** |
| field | `hide` | нуугдана | анхны төлөв |
| field | `require` | харагдана + required | анхны required төлөв (өөрчлөхгүй) |
| payment | `enable` | боломжтой | **боломжгүй** |
| payment | `disable` | боломжгүй | анхны төлөв |
| shipping | `enable` / `disable` | payment-тэй ижил, method (`flat_rate`) эсвэл instance (`flat_rate:3`) түвшинд |
| tax_display | `hide_tax_note` | Татвартай үнийн горимд (`tax_display_cart = incl`) Total-ийн доорх "(includes … tax)" тайлбарыг нууна. Татваргүй горимд тусдаа татварын мөрийг нуухыг **зөвшөөрөхгүй** — Subtotal+Shipping≠Total харагдаж худалдан авагчийг төөрөгдүүлнэ. Ийм хэрэгцээг `set_vat_exempt` шийднэ. | — |
| tax_calc (2-р шат) | `set_vat_exempt` | захиалгыг VAT exempt болгоно | — |
| tax_calc (2-р шат) | `set_tax_class` | сагсны барааг **байгаа** tax class руу шилжүүлнэ | — |
| order (1.1) | `block_checkout` | Cart-ийн "Proceed to checkout", mini cart-ийн "Checkout" товч идэвхгүй + мессеж; checkout дээр "Place order" идэвхгүй + мессеж; сервер захиалгыг татгалзана | хэвийн |
| order (1.1) | `block_order` | Зөвхөн checkout: "Place order" идэвхгүй + мессеж; сервер захиалгыг татгалзана. Cart-д нөлөөлөхгүй | хэвийн |

Нуугдсан талбар хэзээ ч required биш. Түүний утга захиалгад хадгалагдахгүй.

## 4. Source-ууд ба target-д ашиглах боломж

Тойрог хамаарлаас сэргийлэхийн тулд target бүрт зөвшөөрөгдөх source хязгаартай. Admin UI зөвшөөрөгдөөгүй хослолыг сонгуулахгүй, сервер мөн татгалзана.

"tax" багана нь `tax_display` ба `tax_calc` хоёуланг илэрхийлнэ. `tax_calc` дүрэм WooCommerce totals тооцохоос **өмнө** ажилладаг тул тэр тооцооноос гарах дүнг (хөнгөлөлтийн дараах дүн, хүргэлт, татвар, нийт дүн) уншиж чадахгүй. `order` дүрэм юуг ч өөрчлөхгүй тул бүх source-ийг ашиглана.

| Source | Утга | field | payment | shipping | tax | order |
|---|---|:-:|:-:|:-:|:-:|:-:|
| `field:<key>` | өөр талбарын утга | ✓ | ✓ | ✓ | ✓ | ✓ |
| `cart.subtotal` | барааны дүн (`WC_Cart::get_subtotal()`, хөнгөлөлтөөс өмнө, татваргүй) | ✓ | ✓ | ✓ | ✓ | ✓ |
| `cart.subtotal_incl_tax` | барааны дүн татвартай (`get_subtotal() + get_subtotal_tax()`, хөнгөлөлтөөс өмнө) (1.1) | ✓ | ✓ | ✓ | ✗ | ✓ |
| `cart.contents_total` | хөнгөлөлтийн дараах барааны дүн, татваргүй (`get_cart_contents_total()`) (1.1) | ✓ | ✓ | ✓ | ✗ | ✓ |
| `cart.discount_total` | хөнгөлөлтийн дүн, татваргүй (`get_discount_total()`) (1.1) | ✓ | ✓ | ✓ | ✗ | ✓ |
| `cart.shipping_total` | хүргэлтийн төлбөр, татваргүй (`get_shipping_total()`) (1.1) | ✓ | ✓ | ✗ | ✗ | ✓ |
| `cart.tax_total` | татварын нийт дүн (`get_total_tax()`) (1.1) | ✓ | ✓ | ✗ | ✗ | ✓ |
| `cart.total` | нийт дүн (хүргэлт, татвар орсон) | ✓ | ✓ | ✗ | ✗ | ✓ |
| `cart.max_item_price`, `cart.min_item_price` | сагсан дахь хамгийн үнэтэй / хямд барааны нэгж үнэ, татваргүй; дэлгүүрийн үнийн нарийвчлалаар бөөрөнхийлсөн; хоосон сагсанд 0 (1.1) | ✓ | ✓ | ✓ | ✓ | ✓ |
| `cart.category_subtotal:<term id>` | тухайн ангилал (дэд ангиллыг оруулаад) дахь барааны дүн: үнэ (татваргүй) × тоо, мөр бүрийг үнийн нарийвчлалаар бөөрөнхийлж, хөнгөлөлтөөс өмнө (1.1) | ✓ | ✓ | ✓ | ✓ | ✓ |
| `cart.coupons` | хэрэглэсэн купоны кодууд (1.1) | ✓ | ✓ | ✓ | ✓ | ✓ |
| `cart.item_count`, `cart.qty`, `cart.weight` | тоо, жин | ✓ | ✓ | ✓ | ✓ | ✓ |
| `cart.products`, `cart.categories`, `cart.tags`, `cart.shipping_classes` | any/all/none + include/exclude | ✓ | ✓ | ✓ | ✓ | ✓ |
| `payment.method` | сонгосон төлбөр | ✓ | ✗ | ✗ (Classic-д ч) | ✓ (2-р шат) | ✓ |
| `shipping.method` | сонгосон хүргэлт | ✓ | ✓ | ✗ | ✓ | ✓ |
| `customer.is_guest`, `customer.role` | хэрэглэгч | ✓ | ✓ | ✓ | ✓ | ✓ |
| `billing.country/state/postcode`, `shipping.country/state/postcode` | хаяг | ✓ | ✓ | ✓ | ✓ | ✓ |
| `tax.vat_exempt`, `tax.has_tax` | татварын төлөв | ✓ | ✓ | ✗ | ✗ | ✓ |

Төрөл: `cart.coupons` — list (any/all/none/only/empty), бусад шинэ `cart.*` дүн — number. Мөнгөн дүн бүхий source: `cart.subtotal`, `cart.subtotal_incl_tax`, `cart.contents_total`, `cart.discount_total`, `cart.shipping_total`, `cart.tax_total`, `cart.total`, `cart.max_item_price`, `cart.min_item_price`, `cart.category_subtotal:*`.

## 5. Operator-ууд

| Утгын төрөл | Operator |
|---|---|
| string / select | `equals`, `not_equals`, `contains`, `not_contains`, `in`, `not_in`, `empty`, `not_empty` |
| number | `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `between` |
| checkbox | `checked`, `unchecked` |
| cart collection / list | `any_in` (аль нэг нь), `all_in` (бүгд), `none_in` (exclude), `only_in` (зөвхөн эдгээр), `empty`, `not_empty` |
| boolean | `is_true`, `is_false` |

## 6. Хамрахгүй зүйлс

- Талбар нэмэх/устгах, label/placeholder/default засах
- Payment gateway эсвэл shipping service шинээр үүсгэх
- Талбар сонгосны төлөө нэмэлт төлбөр (fee) авах
- VAT дугаарын VIES баталгаажуулалт, татварын хувь зохиох
- Checkout-ийн section, дизайн, талбарын дарааллыг удирдах
- Үнэ, хөнгөлөлт, нэмэлт төлбөр, үнэгүй хүргэлт үүсгэх эсвэл өөрчлөх. Захиалгын хязгаарлалт (§13) зөвхөн хориглож, мессеж харуулна; дүнг WooCommerce тооцно
- Татварын харагдацын дүрэм нь зөвхөн checkout хуудсанд, зөвхөн CSS түвшинд үйлчилнэ: `get_tax_totals()`, Store API `tax_lines`/`total_tax`, order tax item, имэйл, нэхэмжлэл, thank-you хуудас өөрчлөгдөхгүй

## 7. Шат 1 — Classic checkout (P0 = заавал, P1 = боломжтой бол)

| # | Боломж | Эрэмбэ | Хүлээн авах шалгуур |
|---|---|---|---|
| 1.1 | Талбарыг автоматаар таних | P0 | Admin-ийн field picker-т billing/shipping/order/account болон бусад plugin-ий `woocommerce_checkout_fields`-ээр бүртгэсэн талбар бүгд гарна. Block-д дэмжигдэхгүй талбарт тэмдэг харагдана. |
| 1.2 | Талбар show/hide | P0 | Нөхцөлд хамаарах утга өөрчлөгдөхөд хуудас reload хийлгүйгээр 300ms дотор шинэчлэгдэнэ. |
| 1.3 | Нөхцөлт required | P0 | Нөхцөл биелсэн үед `*` тэмдэг гарч, хоосон илгээхэд алдаа өгнө. Биелээгүй үед анхны төлөв хэвээр байна. |
| 1.4 | Payment enable/disable | P0 | Хасагдсан арга жагсаалтад харагдахгүй. POST-оор хүчээр илгээвэл сервер татгалзана. Gateway-ийн дагалдах талбар зөвхөн тухайн арга сонгогдсон үед харагдана (WC-ийн анхны зан төлөв хадгалагдана). |
| 1.5 | Shipping enable/disable | P0 | Rate жагсаалт серверийн тооцоотой яг таарна. Кэшлэгдсэн хуучин rate гарахгүй. Хаягийн талбарууд дээр 1.2/1.3 мөн ажиллана. |
| 1.6 | Татварын талбар ба харагдац | P0 | VAT/tax ID талбар 1.2/1.3-аар удирдагдана. `hide_tax_note` нь тайлбарыг нуухаас өөр юуг ч өөрчлөхгүй. |
| 1.7 | Source-ууд (§4) | P0 | §4-ийн бүх source unit тесттэй. |
| 1.8 | Group / operator (§5) | P0 | AND/OR, бүх operator-ыг PHP ба JS evaluator ижил shared fixture-ээр тестэлнэ. |
| 1.9 | Бүтээгдэхүүний any/all/none, include/exclude | P1 | Fixture тесттэй. |
| 1.10 | Сервер талын баталгаажуулалт | P0 | Нуугдсан required талбар алдаа гаргахгүй. Хориглосон payment/shipping-ээр захиалга үүсэхгүй. Нуугдсан талбарын утга order-т орохгүй. |
| 1.11 | Зөрчил тайлбарлах | **P0** | Admin: эсрэг action-тай давхцсан дүрэм, бүх аргыг хасах боломжтой дүрмийг rule list дээр анхааруулна. Худалдан авагч: бүх арга хасагдвал ойлгомжтой мессеж (§8). |

## 8. Зөрчлийн бодлого

1. Ижил target дээр олон дүрэм биелвэл **priority** бага тоотой нь давамгайлна (1 > 10).
2. Priority тэнцүү бол **хязгаарлах action давамгайлна**: `hide` > `show`, `disable` > `enable`. `require` нь `hide`-д ялагдана.
3. **Order дүрэм (§13)** зөрчилгүй: биелсэн аль ч `block_*` дүрэм хориглоно. Priority зөвхөн мессежийн дарааллыг тодорхойлно.
4. **Бүх payment эсвэл бүх shipping арга хасагдвал** checkout зогсоно:
   - Худалдан авагчид: "Таны сонголтод тохирох төлбөрийн/хүргэлтийн арга алга…" (admin засах боломжтой текст).
   - Admin-д: тухайн аргуудыг хассан дүрмүүдийн жагсаалттай notice + `wc_get_logger()` (source `ccfo`) log.
   - Захиалга үүсэхгүй.

## 9. Шат 2 — Checkout Block, татвар, өргөтгөл

| # | Боломж | Эрэмбэ | Хүлээн авах шалгуур |
|---|---|---|---|
| 2.1 | Block дээрх дүрэм | P0 | Payment/shipping дүрэм Classic-тай ижил үр дүн өгнө (ижил E2E сценари хоёр урсгалд). Талбарын дүрэм **албан API-аар дэмжигдсэн хэмжээнд** ажиллана. Дэмжигдэхгүйг admin-д "Classic only" гэж тэмдэглэнэ. |
| 2.2 | Block payment | P0 | Store API-ийн `payment_methods` ба UI таарна. Хориглосон аргаар Store API checkout татгалзана. |
| 2.3 | Block shipping | P0 | Store API rate, totals, UI синхрон. |
| 2.4 | Татварын тооцооллын дүрэм | P1 | `set_vat_exempt`, `set_tax_class` (байгаа class-аас). Classic ба Block-ийн totals cent-ийн нарийвчлалтай ижил. Order дээр аль дүрэм хэрэгжсэнийг meta-д бичнэ. |
| 2.5 | 3-дагч талын adapter | P1 | `woocommerce_checkout_fields`, `woocommerce_register_additional_checkout_field`-ээр бүртгэсэн талбар автоматаар дэмжигдэнэ. Woodmart checkout fields manager-тэй зөрчилгүй. Дэмжигдээгүй талбарыг admin-д тэмдэглэнэ. |
| 2.6 | Totals серверээр | P0 | Payment/shipping/tax солигдоход totals-ийг WC дахин тооцно. Plugin өөрөө totals тооцохгүй. |
| 2.7 | HPOS / нийцэл | P0 | `custom_order_tables`, `cart_checkout_blocks` compatibility зарласан. Checkout-ийн төрлөөр ялгаатай боломжийг admin-д харуулна. |

## 10. Хувилбарын шаардлага

- WordPress 6.6+, WooCommerce 9.9.3+, PHP 8.1+. Тест: WP 7.1.3 / WC 11.2.1 / PHP 8.4.
- Plugin header: `Requires Plugins: woocommerce`, `WC requires at least: 9.9.3`.

## 11. Acceptance сценари (E2E болгоно)

Classic = C, Block = B. Сценари бүр хоёр урсгалд ажиллах ёстой, хэрэв "C only" гэж заагаагүй бол.

| ID | Given | When | Then |
|---|---|---|---|
| A1 | Rule: `billing_company` → `show` if `billing.country = DE` | Улс MN → DE | Компани талбар гарна. Буцаад MN → алга болно. (B: country locale-аар) |
| A2 | Rule: `billing_vat` (3-дагч талын талбар) → `require` if `billing_company not_empty` | Компани бөглөөд VAT хоосон илгээх | "VAT is a required field" алдаа. Компани хоосон бол алдаагүй. (C only, хэрэв B-д API байхгүй бол) |
| A3 | A1-ийн талбар нуугдсан, анх required байсан | Хоосон илгээх | Захиалга амжилттай, order-т `billing_company` хоосон. |
| A4 | Rule: `cod` → `disable` if `cart.total gt 500000` | Сагс 600,000₮ | COD харагдахгүй. Store API/POST-оор `payment_method=cod` илгээвэл татгалзана. |
| A5 | Rule: `free_shipping` → `disable` if `cart.categories any_in [heavy]` | Heavy бараа нэмэх | Free shipping алга. Барааг хасахад буцаж гарна (кэш саадгүй). |
| A6 | Rule: `flat_rate:N` → `disable` if `customer.role = wholesale` | Wholesale-аар нэвтрэх | Rate алга. Guest-д харагдана. Ижил сагс, ижил хаягтай. |
| A7 | Rule: бүх payment-ийг disable | Checkout нээх | §8.3-ийн мессеж, захиалга үүсэхгүй, admin notice гарна. |
| A8 | Rule: `order_comments` → `show` if `shipping.method = local_pickup` | Pickup сонгох | Тэмдэглэл талбар гарна. |
| A9 | Rule: `bacs` → `enable` if `billing.country in [MN]` | Улс солих | MN үед л BACS харагдана. |
| A10 | Ижил талбар дээр `show` (p10) ба `hide` (p10) хоёулаа биелсэн | — | Нуугдана. Admin rule list-д зөрчлийн анхааруулга гарна. |
| A11 | Татвар асаалттай, inclusive горим. Rule: `hide_tax_note` if `customer.is_guest` | Guest checkout | "(includes …)" алга, **Total өөрчлөгдөөгүй**, order-ийн tax item бүрэн. Exclusive горимд admin "нөлөөгүй" гэж анхааруулна. |
| A12 | (2-р шат) `set_vat_exempt` if `field:billing_vat not_empty` AND `billing.country in EU` | VAT бөглөх | Татвар 0, C ба B-ийн totals ижил, order meta-д дүрмийн ID бичигдсэн. |

| A13 | (1.1) Rule: `order` → `block_checkout` if `cart.subtotal lt 400000`, мессеж "Дахиад {remaining}…" | Сагс 300,000 | C: Cart-ийн "Proceed to checkout" идэвхгүй, мессежид үлдэгдэл 100,000 харагдана; тоо нэмэхэд товч идэвхжинэ. B: Cart Block-ийн товч хориглогдож, notice гарна. |
| A14 | (1.1) Rule: `order` → `block_order` if `cart.total gt 500000` | Сагс 600,000 | Checkout-ийн "Place order" идэвхгүй, мессеж; cart-ийн товч идэвхтэй. POST / Store API checkout татгалзана, захиалга үүсэхгүй. |
| A15 | (1.1) Rule: `block_order` if `payment.method = cod` AND `cart.total gt 500000` | COD ↔ BACS солих | COD үед хориглогдоно, BACS үед захиалга амжилттай (C ба B). |
| A16 | (1.1) Rule: `bacs` → `enable` if `cart.category_subtotal:<heavy> gte 200000` | Heavy ×1 → ×2 | BACS ×2 үед л гарна. |
| A17 | (1.1) Rule: `cod` → `disable` if `cart.coupons any_in [bigsale]` | Купон хэрэглэх | COD алга болно; `cart.contents_total` хөнгөлөлтийн дараах дүнг өгнө. |

## 12. Хүлээлгэн өгөх зүйлс (Definition of Done)

- PHPCS, PHPStan, PHPUnit (unit + integration), Jest, Playwright бүгд ногоон
- `readme.txt` (wp.org формат), `CHANGELOG.md`, `languages/*.pot`
- `npm run plugin-zip`-ээр build хийсэн, dev файлгүй zip
- `docs/MANUAL-TEST.md` — гараар шалгах checklist
- `docs/COMPATIBILITY.md` — Classic/Block-оор дэмжигдэх ба дэмжигдэхгүй боломжийн хүснэгт

## 13. Шат 3 — Захиалгын хязгаарлалт ба дүнгийн нөхцөл (1.1.0)

### 13.1 Боломжууд

| # | Боломж | Эрэмбэ | Хүлээн авах шалгуур |
|---|---|---|---|
| 3.1 | `block_checkout` (Classic) | P0 | Нөхцөл биелвэл cart-ийн "Proceed to checkout" ба express payment товчны оронд идэвхгүй товч, mini cart-ийн "Checkout" товч идэвхгүй, түүний дээр богино мессеж. Мессеж cart хуудасны **дээд хэсэгт** WooCommerce-ийн мэдэгдлийн дараа (`woocommerce-info`) гарна (1.1.1). Checkout хуудсанд "Place order" идэвхгүй. Cart-ийг шинэчлэхэд (тоо, хүргэлт, купон) товч ба мессеж шууд зөв төлөвт орно. |
| 3.2 | `block_order` (Classic) | P0 | Checkout-ийн "Place order" товч `disabled`; мессеж checkout хуудасны **дээд хэсэгт** (1.1.1), шинээр гарвал хуудас тийш гүйлгэгдэнэ. Талбар, төлбөр, хүргэлт солигдох бүрт (`update_order_review`) төлөв шинэчлэгдэнэ. Гараар POST илгээвэл `woocommerce_after_checkout_validation` алдаа өгч, захиалга үүсэхгүй. |
| 3.3 | Block (Cart/Checkout Block) | P0 | Store API cart-ийн `extensions.ccfo.checkoutBlocked` / `orderBlocked` мессежүүд. Cart Block: notice + "Proceed to checkout" хориглогдоно. Checkout Block: notice + "Place order" хориглогдоно (validation store). Store API checkout `ccfo_order_blocked` (400)-оор татгалзана. |
| 3.4 | Шинэ дүнгийн source-ууд (§4) | P0 | PHP-ийн утга WooCommerce-ийн `WC_Cart` getter-тэй тэнцүү (ангиллын дэд дүн ба барааны үнэ: `wc_get_price_excluding_tax()`). Request горим бүрт тест. |
| 3.5 | Ангиллын дэд дүн | P0 | Дэд ангиллын бараа эцэг ангилалд тооцогдоно, variation-ий ангилал эцэг бүтээгдэхүүнээс. Admin-д ангилал бүрээр сонгоно. |
| 3.6 | Admin | P0 | "Order" target, хоёр action, мессеж засварлагч (placeholder-ийн тайлбартай), Settings-д анхдагч мессеж. 1.1.1: талбарын утга ба ангиллын дэд дүнг хоёр алхмаар сонгоно (нөхцөлийн төрөл → талбар/ангилал); хайлтын жагсаалт агуулгын дээгүүр хөвж гарна. |
| 3.7 | Linter | P0 | Нөхцөлгүй order дүрэм (бүх захиалгыг хориглоно), хоорондоо зөрчилдсөн тоон нөхцөлтэй group (хэзээ ч биелэхгүй), утгагүй эсвэл үл мэдэгдэх placeholder, устсан ангилал. |

### 13.2 Хязгаарлалт

- Express payment товч (Apple Pay, PayPal г.м.) захиалгыг өөрийн урсгалаар үүсгэвэл серверийн шалгалтаас гадуур гарч болно. Cart ба checkout хуудсанд хориглогдсон үед plugin эдгээр товчийг (`wc_get_pay_buttons`) харуулахгүй.
- Mini-Cart **block**-ийн "Go to checkout" товчийг удирдахгүй (албан API байхгүй); тэндээс checkout руу орсон ч Checkout Block захиалгыг хориглоно.
- Мессеж нь энгийн текст (HTML-гүй), дээд тал нь 500 тэмдэгт.

### 13.3 Мессежийн placeholder

| Placeholder | Утга |
|---|---|
| `{min}` | Биелсэн group-ийн эхний `lt`/`lte` нөхцөлийн хязгаар |
| `{remaining}` | `{min}` − одоогийн утга (0-ээс багагүй) |
| `{max}` | Эхний `gt`/`gte` нөхцөлийн хязгаар |
| `{excess}` | Одоогийн утга − `{max}` (0-ээс багагүй) |
| `{current}` | Эхний тоон харьцуулалтын (`lt`, `lte`, `gt`, `gte`, `eq`, `neq`, `between`) одоогийн утга |

Мөнгөн source-ийг дэлгүүрийн валютаар (`wc_price()`), `cart.weight`-ийг жингийн нэгжтэй, бусдыг энгийн тоогоор харуулна. Тохирох нөхцөлгүй placeholder хоосон болно (admin linter анхааруулна).
