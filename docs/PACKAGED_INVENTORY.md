# Stock xiran iyo stock furan

For developers: [Inventory and POS developer guide](INVENTORY_POS_DEVELOPER_GUIDE.md).

Latest simplified workflow and production SQL: [Inventory workflow deployment](INVENTORY_WORKFLOW_DEPLOYMENT.md). The instructions below describe the initial package feature; the latest guide supersedes its POS popup workflow.

## Sida loo isticmaalo

1. Inventory > Products: edit Bariis. Unit = kg, package name = Loor, capacity = 25. Geli qiimaha hal kg iyo qiimaha hal Loor. Saliid: unit = litre, package name = Jirgaan, geli litirrada saxda ah ee jirgaankaaga.
2. Purchase order: dooro alaabta, kadib purchase unit = Loor/Jirgaan. Quantity = tirada baakadaha; unit cost = qiimaha hal baakad. Purchase unit = kg/litre waxaa loo isticmaali karaa alaab aad si furan u soo iibsatay.
3. Receiving: xaqiiji inta dhab ahaan timid. Baakadaha xiran waxay galaan stock-ga xiran. Dalab keliya stock ma kordhiyo.
4. Inventory > Stock: guji Open Loor / Open Jirgaan. Geli tiro dhan. Hal Loor oo 25 kg ah wuxuu xiran ka jarayaa 1, furan-na ku darayaa 25 kg. Taariikhda furista, tirada iyo qofka furay ayaa muuqda.
5. POS: dooro kg/litre ama Loor/Jirgaan, kadib geli tirada. Tirada cart-ka waad gujin kartaa si aad u beddesho.
6. Haddii kuwa furan aysan ku filnayn, fariintu waxay sheegaysaa inta furan iyo inta la dalbaday. Go to Stock wuxuu furayaa tab cusub; cart-ka POS-ka wuu sii jirayaa. Stock-ga ka fur baakad, kusoo noqo POS oo dib u geli/isku day tiradii.
7. Marka stock-ga furan gaaro 0, fariin ayaa soo baxaysa; stock page-kuna wuxuu muujinayaa inuu dhammaaday.

Baakad si otomaatig ah looma furo. Iibka xiran kama baxo kuwa furan, iibka furanna kama baxo kuwa xiran.

## Xogta hadda jirta

Migration-ka waxaa lagu dabaqay database-ka local-ka. Waxba lagama beddelin tirada, qiimaha ama diiwaannada iibka/purchase-ka ee alaabtaada.

Bariis-ka jira lama siin qiime Loor oo la qiyaasay: marka hore ku dejiso product form-ka. Stock-gii hore wuxuu ahaanayaa base units (kg/litre) oo furan marka packages la hawlgeliyo. System-ku ma qiyaasayo inta baakad xiran ku jirtay diiwaan hore. Haddii diiwaannadii hore ay dhab ahaan baakado matalayeen, waa in marka hore la xaqiijiyaa oo la saxaa tirada iyo cost-ka.

Package capacity, magaca iyo base unit lama beddeli karo marka package transactions ama purchase lines ay jiraan. Baakad cabbir kale leh u samee product kale. Qiimaha iibka weli waa la beddeli karaa.

Customer return wuxuu alaabta ku celiyaa pool-kii laga iibiyey; supplier return-na wuxuu ka jarayaa pool-kii laga soo iibsaday. Package return waa tiro dhan; package hore loo furay supplier-ka looguma celin karo sidii baakad xiran. Returned sealed packages waa inay dhab ahaan xiran yihiin.

## Tests-ka la reebay

Ka orod project root-ka:

```powershell
& C:\xampp\php\php.exe tests/packaged_inventory_smoke.php
node tests/packaged_inventory_ui_smoke.cjs
```

PHP test-ku wuxuu sameeyaa 98 checks, oo ay ku jiraan:

- Bariis (25 kg/Loor) iyo Saliid (20 litre/Jirgaan): order, partial/full receiving, package cost, stock split.
- Manual opening, request retries, branch isolation, insufficient packages iyo permissions.
- 0.4, 0.2, 0.5 iyo tiro ka badan 1; mixed Loor/kg ticket; duplicate lines.
- 0.5 furan, dalab 2: iibku waa diidmaa; furis cusub kadibna iibku wuu shaqeeyaa.
- Customer/supplier returns iyo kharashka alaabta iibantay.
- Closed financial period: sale, payment, stock iyo package ledger dhammaantood rollback.
- Journal entries waa balanced.
- Hashes-ka dhammaan real tables-ka la taabankaro waa isku mid ka hor iyo kadib test-ka.

Tests-ku waxay isticmaalaan connection-local TEMPORARY TABLES oo leh qaabkii schema-ga dhabta ah. Xogta test-ku waxay baaba'daa marka connection-ku xirmo; test files-ka iyo assertions-ka waxaa lagu reebay repository-ga. Ma abuuraan financial records gudaha real tables-ka.

UI test-ku wuxuu tijaabiyaa JavaScript-ka POS-ka: units kala duwan, quantity editing, stock-yaraan, cart preservation iyo package validation. Tests-kii hore ee purchasing, stock adjustment, inventory, document returns, POS tax/reset/payment/printing sidoo kale waa la orday.

## Read-only browser previews

Waxaa lagu render-gareeyaa isla views-ka app-ka iyadoo la adeegsanayo temporary test data. Dhammaan fetch mutations waa blocked. Kuwani ma aha live stock-gaaga.

```powershell
& C:\xampp\php\php.exe tests/packaged_inventory_smoke.php --preview-stock
& C:\xampp\php\php.exe tests/packaged_inventory_smoke.php --preview-pos
& C:\xampp\php\php.exe tests/packaged_inventory_smoke.php --preview-products
```

Kadib fur `http://localhost/Mudnaan-ERP-BOOKSAFE/results/packaged-inventory/stock.html`, `pos.html` ama `products.html`. Generated previews-ku waxay ku jiraan ignored results directory; mar kasta tests-ka ayaa dib u abuuri kara. Browser-ka waxaa lagu hubiyey layouts-ka 390px, 903px iyo 1366px, manual opening dialog, unit selection, 0.5 kg cart iyo shortage notice. Real authenticated web checkout lama sameyn; demo login-ka seed-ka wuu diiday credentials-kii documented-ka ahaa.

## Kaydinta gudaha

- stock_levels iyo stock_movements: wadarta oo dhan waxay ku jiraan base units (kg/litre), si valuation iyo ledger-ku isu waafaqaan.
- package_movements: signed count of sealed packages. Opened = total base quantity - sealed count * package capacity.
- Furistu total stock iyo journal midna ma beddesho. Waxay leedahay package ledger row iyo audit record.
- purchase_items iyo sale_items: quantity iyo price waxay ku jiraan unit-kii la doortay; stock_factor, stock_mode iyo unit_label waa snapshot.
- Cost waxaa lagu kaydiyaa base unit iyadoo la adeegsanayo 6 decimal places, amounts-ka lacagta iyo journal-kuna waa 2 decimals.
- Product locks iyo database transactions waxay isku hayaan sale/receipt/return iyo labada stock pools.
- Mobile/API controllers iyo /api/v1 routes lama beddelin. Shared services waxay ilaaliyaan stock pools; quantity aan mode lagu soo dirin waxaa loo qaataa base/loose unit sidii hore.

Schema migration: app/Database/Migrations/2026-11-07-120000_AddPackagedInventory.php. Installation-ka kale ee legacy migrations qaarkood gacanta loogu dabaqay, `php docs/apply_packaged_inventory.php` wuxuu dabaqayaa migration-kan keliya. Ha rollback-gareyn package history; ka soo celi backup la xaqiijiyey haddii reverse loo baahan yahay.
