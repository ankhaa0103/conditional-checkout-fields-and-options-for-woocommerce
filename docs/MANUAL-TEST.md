# MANUAL-TEST — шалгах жагсаалт

Ихэнх зүйл `tests/e2e/manual.spec.js`-д автоматжсан (✅ = E2E, 👁 = хүний нүдээр харах). Fixture: `php tools/wp-cli.phar --path=C:/wamp64/www/brand.mn eval-file tools/fixtures.php setup`, ажиллуулах: `npx playwright test --config tests/e2e/playwright.config.js manual.spec.js`.

## Payment/shipping дүрэм талбарын утгаас хамаарах (0.1.1 regression)

- ✅ Зочин хэрэглэгч компанийн нэр бичээд талбараас гарахад BACS шууд гарч ирнэ, арилгахад алга болно.
- ✅ Бичиж байх үед (талбараас гаралгүй) "Live update delay"-ийн дараа ганц удаа шинэчлэгдэнэ. Нөхцөлийн үр дүн өөрчлөгдөөгүй бол дахин шинэчлэгдэхгүй.
- ✅ Зөвхөн payment дүрэм байхад ч checkout script ачаалагдана.

## Admin

- ✅ WooCommerce → Checkout Rules цэс болон REST API `manage_woocommerce` эрхгүй хэрэглэгчид хаалттай.
- ✅ Дүрэм нэмэх, хуулах, устгах, идэвхгүй болгох. Хадгалаагүй өөрчлөлттэй хуудсаас гарахад хөтөч анхааруулна.
- ✅ Хоёр цонхонд зэрэг засахад хоёр дахь нь "changed in another window" гэж татгалзана, "Reload rules" ажиллана.
- ✅ Export → Import дүрмийг сэргээнэ. Буруу JSON алдааны мессеж өгнө.
- ✅ Бүтээгдэхүүний нөхцөл: нэрээр хайна, хадгалсны дараа нэр нь (ID биш) харагдана.
- ✅ Татвар унтраастай үед татварын дүрэм анхааруулгатай.
- ✅ Бүх payment арга хасагдсан checkout-ийн дараа WooCommerce дэлгэц дээр admin notice гарна. Rules хуудсан дээр хаахад алга болно.
- 👁 Admin UI-ийн ерөнхий харагдац, текстийн ойлгомж.

## Classic checkout

- ✅ Талбар нуухад layout эвдрэхгүй (хэвтээ scroll байхгүй, мөрүүд давхцахгүй). 👁 Woodmart дээрх харагдацыг нүдээр шалгах.
- ✅ Улс солиход WooCommerce-ийн locale (postcode required) болон бидний дүрэм хоёулаа зөв.
- ✅ Нэвтэрсэн хэрэглэгчийн хадгалсан хаягаар анхны төлөв серверээс зөв ирнэ (JS унтраастай шалгасан). Тэмдэглэл: WooCommerce идэвхтэй session-ий customer өгөгдлийг профайлаас илүүд үздэг.
- ✅ Order admin дэлгэц дээр "Checkout rules applied" хэсэг харагдана.
- ✅ Татвар асаалттай, "prices incl. tax" горимд `hide_tax_note` "(includes … tax)"-ийг нууна, Total өөрчлөгдөхгүй.

## Checkout Block

- ✅ Payment method солиход `payment.method`-оос хамаарах татварын дүрэм totals-ийг шинэчилнэ.
- ✅ `require` дүрмийн алдааны мессеж талбарын нэртэй.
- ✅ Block нь checkout хуудас үед admin дээр Block-ийн тайлбар болон "classic only" анхааруулга гарна.

## Орчны тэмдэглэл

Энэ компьютер дээрх Apache PHP-д Xdebug (develop) асаалттай, OPcache унтраастай. WooCommerce-ийн AJAX бүр ~4 сек болдог тул checkout шинэчлэгдэх нь удаан харагдана. Production орчинд (OPcache асаалттай, Xdebug-гүй) хамаагүй хурдан байна.

## Цэвэрлэх

`php tools/wp-cli.phar --path=C:/wamp64/www/brand.mn eval-file tools/fixtures.php teardown`
