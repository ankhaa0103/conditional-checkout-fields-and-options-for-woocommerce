# COMPATIBILITY — Classic checkout vs Checkout Block

Хувилбар: 0.1.0 (2026-10-17). Туршсан орчин: WP 7.1.3, WC 11.2.1, PHP 8.4, Woodmart theme.

## Дэмжлэгийн хүснэгт

| Дүрэм | Classic checkout | Checkout Block | Тайлбар |
|---|---|---|---|
| Талбар `show` / `hide` | ✅ Шууд (JS) + серверт | ❌ | Block-ийн core талбарыг харуулах/нуух албан API байхгүй. Admin дээр дүрэм бүрт "classic only" анхааруулга гарна. |
| Талбар `require` | ✅ Шууд (`*` тэмдэг) + серверт | ⚠️ Зөвхөн захиалга илгээх үед | Block-ийн талбарууд: billing/shipping хаяг, имэйл, захиалгын тэмдэглэл. Форм дээр `*` гарахгүй, Store API алдааны мессеж буцаана. |
| Нуугдсан талбарын утгыг устгах | ✅ | — | |
| Payment `enable` / `disable` | ✅ | ✅ | Ижил `woocommerce_available_payment_gateways` filter. Block UI нь Store API-ийн `payment_methods`-ийг дагадаг. |
| Payment/shipping/tax дүрэм талбарын утгаас хамаарах (`field:*`) | ✅ Нөхцөлийн үр дүн өөрчлөгдөхөд checkout шинэчлэгдэнэ: талбараас гарахад шууд, бичиж байхад "Live update delay" (анхдагч 5 сек)-ийн дараа | ⚠️ Block хаягийн талбарын өөрчлөлтийг серверт өөрөө илгээдэг тул core хаягийн талбарт ажиллана. Бусад талбар Block-д байхгүй. | 0.1.1-д засагдсан: өмнө нь Classic-д зөвхөн профайлд хадгалсан утгаар ажилладаг байсан. |
| Shipping `enable` / `disable` | ✅ | ✅ | Ижил `woocommerce_package_rates` filter + кэшийн hash. Classic-д хасагдсан rate-ийг POST-оор хүчээр сонговол WooCommerce түүнийг санал болгож буй rate-ээр автоматаар сольдог (`wc_get_chosen_shipping_method_for_package`). Store API нь `woocommerce_rest_invalid_shipping_option` алдаа буцаадаг. Аль ч тохиолдолд хасагдсан rate захиалгад орохгүй. |
| Бүх аргыг хассан үеийн мессеж | ✅ Тохируулсан текст | ⚠️ Block-ийн өөрийн мессеж | Захиалга аль ч тохиолдолд үүсэхгүй. |
| `hide_tax_note` | ✅ | ✅ (CSS, `extensions.ccfo`) | Зөвхөн "татвартай үнэ" горимд утга учиртай. |
| `set_vat_exempt`, `set_tax_class` | ✅ | ✅ | WooCommerce-ийн тооцооллоор. Totals-ийг сервер тооцно. |
| `payment.method` нөхцөл (field/tax) | ✅ | ✅ (`extensionCartUpdate`-ээр сервер рүү) | |
| Order meta `_ccfo_applied_rules` | ✅ | ✅ | HPOS-safe CRUD. |

## WooCommerce хувилбар

| Шалгалт | 11.2.1 | 9.9.3 |
|---|---|---|
| Unit, integration, E2E (бүгд) | ✅ Ажиллуулсан | — |
| Ашигласан hook/API бүр байгаа эсэх (эх кодоос) | ✅ | ✅ |
| Нуугдсан required талбар | `hidden=true` + `required=false` | `required=false` хангалттай (9.9.3 нь `hidden`-ийг validation-д уншдаггүй; бид хоёуланг нь тавьдаг) |
| Shipping кэшийн hash-д `ccfo_ctx` орох | ✅ | ✅ (бүх package-ийг hash хийдэг) |

**9.9.3-ийг ажиллуулж туршаагүй.** Тусгаарлагдсан орчин (Docker/wp-env эсвэл тусдаа сайт) хэрэгтэй. Одоогоор зөвхөн эх кодоор шалгасан.

## Theme / plugin

| Компонент | Тэмдэглэл |
|---|---|
| Woodmart checkout fields manager | Бидний filter дараа нь ажилладаг (`PHP_INT_MAX - 10`). Тест сайтад manager унтраастай байсан тул түүнтэй хамт туршаагүй. |
| Woodmart checkout layout | WooCommerce-ийн **checkout хуудас** болгон тохируулсан хуудсан дээр Checkout Block-ийг өөрийн classic layout-оор солидог. Энэ тохиолдолд Classic-ийн бүх дүрэм ажиллана. Block-ийн тестийг checkout хуудас биш, тусдаа хуудсан дээр хийсэн. |
| WC `address-i18n.js` | Улс солигдоход required төлвийг дахин бичдэг. Бид locale талбаруудын шинэ төлвийг base болгож, дүрмээ дахин хэрэглэдэг. |
| Гуравдагч талын classic талбар | `woocommerce_checkout_fields` / `woocommerce_billing_fields`-ээр бүртгэгдсэн бол автоматаар танигдана (тестэд `billing_vat`). |
| Additional Checkout Fields API (Block) | Эзэмшигч plugin-ий өөрийн дүрмээр удирдагдана. Бид өөрчлөхгүй. Admin дээр "өөрийн plugin-оор удирдагддаг" жагсаалтаар харагдана. |
