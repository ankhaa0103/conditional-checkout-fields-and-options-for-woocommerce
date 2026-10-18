# SPEC — Conditional Checkout Fields and Options for WooCommerce

Хувилбар: 0.1 (2026-10-17). Техникийн хэрэгжүүлэлт: [ARCHITECTURE.md](ARCHITECTURE.md).

## 1. Зорилго

Checkout-ийн **одоо байгаа** элементүүдэд нөхцөлт дүрэм ажиллуулна:

- checkout талбарууд (billing, shipping, order, account, бусад plugin-ий бүртгэсэн талбар)
- төлбөрийн аргууд (ба тэдгээрийн дагалдах талбар)
- хүргэлтийн аргууд / rate-ууд (ба хаягийн талбарууд)
- татварын талбар, татварын мэдээллийн харагдац; 2-р шатанд татварын тооцоолол

Plugin шинэ талбар үүсгэхгүй, байгаа талбарын label/placeholder/default-ийг өөрчлөхгүй.

## 2. Нэр томъёо

| Нэр | Утга |
|---|---|
| **Rule** | Нэг target дээр нэг action, нэг буюу хэд хэдэн condition group. |
| **Target** | Дүрэм үйлчлэх зүйл: `field`, `payment`, `shipping`, `tax_display`, `tax_calc`. |
| **Action** | `show`, `hide`, `require`, `enable`, `disable`, `hide_tax_note`, `set_vat_exempt`, `set_tax_class`. |
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

Нуугдсан талбар хэзээ ч required биш. Түүний утга захиалгад хадгалагдахгүй.

## 4. Source-ууд ба target-д ашиглах боломж

Тойрог хамаарлаас сэргийлэхийн тулд target бүрт зөвшөөрөгдөх source хязгаартай. Admin UI зөвшөөрөгдөөгүй хослолыг сонгуулахгүй, сервер мөн татгалзана.

| Source | Утга | field | payment | shipping | tax |
|---|---|:-:|:-:|:-:|:-:|
| `field:<key>` | өөр талбарын утга | ✓ | ✓ | ✓ | ✓ |
| `cart.subtotal` | барааны дүн (`WC_Cart::get_subtotal()`, хөнгөлөлтөөс өмнө, татваргүй) | ✓ | ✓ | ✓ | ✓ |
| `cart.total` | нийт дүн (хүргэлт, татвар орсон) | ✓ | ✓ | ✗ | ✗ |
| `cart.item_count`, `cart.qty`, `cart.weight` | тоо, жин | ✓ | ✓ | ✓ | ✓ |
| `cart.products`, `cart.categories`, `cart.tags`, `cart.shipping_classes` | any/all/none + include/exclude | ✓ | ✓ | ✓ | ✓ |
| `payment.method` | сонгосон төлбөр | ✓ | ✗ | ✗ (Classic-д ч) | ✓ (2-р шат) |
| `shipping.method` | сонгосон хүргэлт | ✓ | ✓ | ✗ | ✓ |
| `customer.is_guest`, `customer.role` | хэрэглэгч | ✓ | ✓ | ✓ | ✓ |
| `billing.country/state/postcode`, `shipping.country/state/postcode` | хаяг | ✓ | ✓ | ✓ | ✓ |
| `tax.vat_exempt`, `tax.has_tax` | татварын төлөв | ✓ | ✓ | ✗ | ✗ |

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
3. **Бүх payment эсвэл бүх shipping арга хасагдвал** checkout зогсоно:
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

## 12. Хүлээлгэн өгөх зүйлс (Definition of Done)

- PHPCS, PHPStan, PHPUnit (unit + integration), Jest, Playwright бүгд ногоон
- `readme.txt` (wp.org формат), `CHANGELOG.md`, `languages/*.pot`
- `npm run plugin-zip`-ээр build хийсэн, dev файлгүй zip
- `docs/MANUAL-TEST.md` — гараар шалгах checklist
- `docs/COMPATIBILITY.md` — Classic/Block-оор дэмжигдэх ба дэмжигдэхгүй боломжийн хүснэгт
