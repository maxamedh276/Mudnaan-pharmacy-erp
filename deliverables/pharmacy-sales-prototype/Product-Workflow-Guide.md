# Mudnaan Pharmacy — Product & Workflow Guide


Retail Pharmacy Management System · Somali explanations / English workspace names


Offline product presentation. Sample scenarios; no live transactions.


## Navigation


9 modules · 15 sections · 47 workspaces · 14 workflows


## Home

Aragtida guud ee farmashiyaha

Sections: Overview


### Pharmacy Overview

**Ujeeddo:** Hal meel ka arag dhaqdhaqaaqa farmashiyaha, stock-ka iyo digniinaha expiry-ga.

**Goorta:** Bilowga maalinta iyo marka maamuluhu rabo xaaladda hadda.

**Waxa la qabto:** Eeg tirooyinka iyo digniinaha; u gudub bogga ay shaqadu khusayso.

**Faa’iido:** Waxay kuu fududaynaysaa inaad kala hormariso hawlaha: dib u dalab, stock baaris iyo iib.


## Medicines & Stock

Daawooyinka, units-ka iyo bakhaarka

Sections: Medicine Catalog / Stock Control


### Medicines & Units

**Ujeeddo:** Diiwaangeli aqoonsiga daawada iyo sida baakadaha kala duwan loogu iibiyo hal stock.

**Goorta:** Daawo cusub marka la bilaabayo, ama xogta iyo qiimaha unit-ka la diyaarinayo.

**Waxa la qabto:** Form-ku wuxuu leeyahay 3 tabs: Medicine Details, Medicine Units iyo Clinical Details. Details: magaca, generic, strength, dosage form, manufacturer, storage iyo Rx requirement. Units: base unit, factor, qiimaha iibka iyo barcode. Tusaale: tablet=1, strip=10, box=100; factor kastaa wuxuu ku salaysan yahay base unit-ka. Clinical Details: qor active ingredients badan, strength/concentration iyo route; review permission ayaa loo baahan yahay si clinical facts loo ansixiyo. Pending profiles lagama dispense gareyn karo. Dosage forms & custom units waxay kuu oggolaanayaan noocyo cusub, unit families iyo packaging variants sida strip-14. Stock source-ka unit kasta waxaa loo dejin karaa sealed, opened ama any released stock.

**Faa’iido:** Waxaad isla daawada u iibin kartaa box, strip iyo xabbo adigoo haysta tirin mideysan. Unit conversions-ka la keydiyey lama beddelo si taariikhda stock-ku u saxnaato.


### Medicines & Catalog

**Ujeeddo:** Maamul diiwaanka guud ee alaabta farmashiyaha, qiimayaasha iyo kala soocidda catalog-ka.

**Goorta:** Marka la raadinayo ama la saxayo product-ka guud ee ku xiran diiwaanka daawada.

**Waxa la qabto:** Raadi SKU, magac iyo category; eeg ama wax ka beddel xogta product-ka ee la oggol yahay. Faahfaahinta pharmacy-ga iyo conversions-ka waxaa laga dejinayaa Medicines & Units.

**Faa’iido:** Waxay isku xiraysaa catalog-ka, iibka iyo inventory-ga adigoon laba stock oo kala duwan samayn.


### Categories

**Ujeeddo:** U kala saar daawooyinka kooxo iyo koox-hoosaadyo.

**Goorta:** Ka hor diiwaangelinta catalog badan ama marka kala sooc cusub loo baahdo.

**Waxa la qabto:** Samee category iyo parent category haddii loo baahdo; ku xiro daawooyinka kooxda saxda ah. Category-gu waa kala sooc ganacsi; dosage form iyo unit waa xog gaar ah.

**Faa’iido:** Raadinta iyo maamulidda catalog weyn ayaa fududaanaya.


### Selling Options

**Ujeeddo:** Samee magacyada iyo quantity shortcuts-ka dheeraadka ah ee catalog-ka.

**Goorta:** Marka shaqaalaha u baahan yihiin xulashooyin tiro oo si joogto ah loo isticmaalo.

**Waxa la qabto:** Diiwaangeli group, unit, quantity shortcuts iyo status. Medicine packaging conversions-ka rasmiga ah waxaa lagu dejinayaa Medicines & Units.

**Faa’iido:** Waxay dedejisaa gelinta tirooyinka, iyadoo unit conversion-ka daawadu leeyahay meel cad oo laga maamulo.


### Batches & Stock

**Ujeeddo:** Maamul lot/batch, expiry, opening stock, quarantine iyo disposal.

**Goorta:** Marka la bilaabayo stock jira ama batch la baarayo, la xannibayo ama la saarayo.

**Waxa la qabto:** Geli batch number, expiry iyo quantity base units ah; eeg hadhaaga batch kasta. Isticmaal opening/correction, quarantine/recall iyo disposal marka ay hawshu sidaas tahay. Alaab purchase ah ku soo geli Goods Receiving.

**Faa’iido:** Stock sellable ah ayaa laga kala saaraa expired, recalled iyo quarantined. FEFO wuxuu kala hormariyaa batches-ka dhowaan dhacaya ee iibka loo oggol yahay.


### Stock Levels & Ledger

**Ujeeddo:** Arag hadhaaga product-ka iyo dhaqdhaqaaqyada stock-ka.

**Goorta:** Marka la baarayo sababta quantity isu beddeshay ama la diyaarinayo tirin.

**Waxa la qabto:** Raadi daawo; la soco stock in/out iyo tixraacyada transactions-ka. Isbarbar dhig physical stock iyo sellable batches marka la hubinayo.

**Faa’iido:** Waxaad raadraaci kartaa receiving, sales, returns iyo adjustments halkii quantity kaliya la eegi lahaa.


### Stock Adjustments

**Ujeeddo:** Diiwaangeli farqiga tirinta, burburka iyo sixitaannada la oggol yahay.

**Goorta:** Marka tirinta dhabta ahi ka duwanaato diiwaanka ama stock la saaro.

**Waxa la qabto:** Dooro product-ka, quantity iyo sababta; isticmaal batch/disposal workflow-ga pharmacy-ga halka ay khusayso. Adjustment-ku wuxuu saamayn karaa stock iyo accounting.

**Faa’iido:** Sixitaanku wuxuu yeeshaa sabab iyo raad la hubin karo halkii tiro si qarsoodi ah loo beddeli lahaa.


### Medicine Import

**Ujeeddo:** Soo geli medicines, units iyo opening batches adigoo isticmaalaya CSV la hubiyey.

**Goorta:** Marka catalog-ka farmashiyaha la bilaabayo ama medicines badan la diiwaangelinayo.

**Waxa la qabto:** Soo dejiso template-ka; geli category paths, generic, strength, dosage form, units iyo qiimayaasha. Preview samee, sax row errors, kadib xaqiiji import. Opening quantity iyo cost waxay ku salaysan yihiin base units.

**Faa’iido:** Preview-gu wax ma keydiyo. Commit-ku wuxuu wada keydiyaa rows-ka saxda ah; file isku mid ah mar labaad stock ma labanlaabo.


### Storage & Cold Chain

**Ujeeddo:** Diiwaangeli meelaha kaydinta iyo heerkulkooda.

**Goorta:** Marka room, fridge ama freezer checks la samaynayo.

**Waxa la qabto:** Samee location iyo limits laga soo qaatay instructions-ka la ansixiyey. Qor temperature iyo waqtiga. Out-of-range reading wuxuu dalbanayaa action la qoray.

**Faa’iido:** Waxaad haysaa monitoring history iyo excursion records. Tani waa manual recording; system-ku kaligiis ma go’aamiyo batch release ama quarantine.


### Opened Packs & Use-by

**Ujeeddo:** Raadraac baakad la furay iyo inta stock ee opened lot-ka ku hartay.

**Goorta:** Marka bottle, strip ama pack la furayo si qaybo looga iibiyo.

**Waxa la qabto:** Unit-ka u deji stock source: sealed, opened ama any released stock. Dooro original batch, whole packs iyo use-by date/time uu pharmacist-ku dejiyey; record opening samee.

**Faa’iido:** Quantity ayaa uga wareegaysa original lot-ka opened lot cusub, iyadoo total stock/value aysan kordhin. POS-ku wuxuu raacaa source policy-ga, use-by dhacayna waa la xannibaa.


### Physical Stock Counts

**Ujeeddo:** Samee physical count leh draft, submission iyo approval.

**Goorta:** Marka stock la tirinayo oo quantity-ga dhabta ah la waafajinayo.

**Waxa la qabto:** Bilow count snapshot; geli base quantity batch kasta iyo sababta farqiga; submit garee. Qof leh stock-approval permission ayaa approve gareeya ama session-ka la cancel gareeyaa.

**Faa’iido:** Draft-ku stock ma beddelo. Approval-ku wuxuu sameeyaa adjustment-ka iyo accounting-ka khuseeya. Stock movement dhacay kadib snapshot-ka wuxuu joojiyaa approval-ka si dib loo tiriyo.


## Patients & Prescriptions

Bukaanka iyo maaraynta warqadaha daawada

Sections: Patient Care


### Patients

**Ujeeddo:** Hay xogta aqoonsiga bukaanka, xiriirka iyo allergies-ka la diiwaangeliyey.

**Goorta:** Ka hor prescription ama marka bukaanku soo laabto oo xogtiisa la cusboonaysiinayo.

**Waxa la qabto:** Raadi bukaanka jira; geli aqoonsiga, telefoonka iyo allergies-ka la yaqaan. Patient record iyo customer credit account waa laba ujeeddo oo kala duwan. Taabo patient name si aad u aragto recorded allergies, prescriptions, dispensing history iyo care notes: counselling, intervention, referral iyo reported adverse event.

**Faa’iido:** Shaqaaluhu waxay heli karaan xogta bukaanka ee loo diiwaangeliyey iyagoo ka fogaanaya records badan oo isku qof ah.


### Prescriptions

**Ujeeddo:** Diiwaangeli, ansixi oo la soco inta prescription laga bixiyey iyo inta hartay.

**Goorta:** Marka la bixinayo daawo Rx-required ah ama prescription qayb ahaan la fulinayo.

**Waxa la qabto:** Dooro patient, qor prescriber/reference, medicines, authorized quantities iyo directions. Qof awood u leh ayaa approve gareeya. Dispensing-ku wuxuu ka jaraa quantity-ga la oggolaaday base units ahaan; label-ku wuxuu daabacaa directions-kii la qoray. Prescription details waxaa lagu lifaaqi karaa private PDF/JPEG/PNG, oo permission leh laga soo dejisan karo.

**Faa’iido:** Partial dispensing iyo isku darka strip/xabbo waxaa lagu cabbiraa hal base quantity. System-ku ma xisaabiyo dose ama drug interactions; clinical assessment-ka waxaa sameeya xirfadlaha.


## Sales & Dispensing

Iibka maalinlaha ah iyo bixinta daawada

Sections: Dispensing / Sales & Returns


### Dispensing Workspace

**Ujeeddo:** POS-ka farmashiyaha: raadi, scan garee, dooro unit oo dhammee iibka.

**Goorta:** Iib kasta oo OTC ama prescription la ansixiyey ku salaysan.

**Waxa la qabto:** Raadi magaca/generic/SKU ama scan barcode; ku dar cart. Dooro box, strip ama base unit, geli quantity, customer/prescription haddii loo baahan yahay, payment method iyo amount paid. Hal daawo waxay geli kartaa laba sadar: 1 strip + 5 tablets. POS-ku wuxuu leeyahay split payment, server-side hold/resume tickets iyo discount leh permission iyo reason la keydiyo. Iibka kadib stock-ka muuqda waa la cusboonaysiiyaa; patient/customer search-kuna server ayuu ka raadiyaa records-ka.

**Faa’iido:** Wuxuu xaqiijiyaa qiimaha, stock sellable ah iyo prescription-ka khuseeya. Iib guulaysta wuxuu soo bandhigaa receipt modal leh Print; recent invoices iyo Sales Orders waxay taageeraan reprint.


### Sales Orders

**Ujeeddo:** Raadi oo eeg invoices-ka iyo iibkii hore.

**Goorta:** Marka macmiil invoice weydiiyo, iib la hubinayo ama receipt dib loo daabacayo.

**Waxa la qabto:** Raadi invoice; eeg line items, payments iyo status. Dooro Receipt si preview iyo Print loo helo. Reprint-ku wuxuu isticmaalayaa isla invoice-kii la keydiyey.

**Faa’iido:** Dib-u-daabiciddu iib cusub ma abuurto, stock-na mar kale kama jarto.


### Sales Returns

**Ujeeddo:** Maamul alaab macmiil ka soo celiyey iyo saamaynta lacagta.

**Goorta:** Marka qayb ama dhammaan wax laga soo celiyo iib hore, iyadoo user-ku leeyahay refund permission.

**Waxa la qabto:** Dooro original sale iyo quantities la soo celinayo; raac refund ama debt reduction-ka khuseeya. Pharmacy return lots waxaa la geliyaa quarantine.

**Faa’iido:** Return-ku wuxuu yeeshaa tixraac iyo raad lacag/stock. Customer-return stock si caadi ah dib looguma fasaxo iibka; prescription entitlement si otomaatig ah uguma soo noqonayo.


### Cash Shifts

**Ujeeddo:** Waafaji lacagta khasnadda cashier-ka iyo dhaqdhaqaaqyada xisaabta cash-ka.

**Goorta:** Bilowga iyo dhammaadka shaqada cashier-ka marka integrated accounting la isticmaalo.

**Waxa la qabto:** Dooro cash account oo qor opening physical cash. Iibka iyo dhaqdhaqaaqyada cash-ka la diiwaangeliyey ayaa lagu daraa/lagaga jaraa expected amount. Geli counted cash iyo closing note; farqi jira wuxuu u baahan yahay sharaxaad.

**Faa’iido:** Mobile money ma kordhiso physical drawer-ka. Closing count iyo variance waa la hayaa; farqiga si otomaatig ah journal looguma sameeyo.


## Purchasing

Dalabka, alaab soo gelinta iyo bixinta supplier-ka

Sections: Procurement / Bills & Payments


### Suppliers List

**Ujeeddo:** Diiwaangeli shirkadaha ama dadka farmashiyuhu wax ka iibsado.

**Goorta:** Ka hor purchase order ama marka xogta supplier-ka la cusboonaysiinayo.

**Waxa la qabto:** Geli magaca, xiriirka iyo xogta vendor-ka; raadi supplier-ka jira.

**Faa’iido:** Dalab, receiving, bills iyo payments waxay yeeshaan supplier ay ku wada xiran yihiin.


### Purchase Orders

**Ujeeddo:** Diyaari dalabka medicines-ka iyo kharashka la filayo.

**Goorta:** Marka stock yar yahay ama la qorshaynayo replenishment.

**Waxa la qabto:** Dooro supplier, products, quantities iyo costs; keydi purchase order. Purchase order keliya ma kordhiyo stock-ka.

**Faa’iido:** Waxaad kala saari kartaa waxa la dalbaday iyo waxa dhab ahaan la keenay.


### Goods Receiving

**Ujeeddo:** Xaqiiji alaabta timid oo ku dar stock iyo supplier payable.

**Goorta:** Marka alaabtii purchase order-ku timaaddo, xitaa haddii qayb keliya timid.

**Waxa la qabto:** Dooro purchase order; geli quantity-ga dhab ahaan la helay iyo batch/expiry-ga daawooyinka; xaqiiji receiving.

**Faa’iido:** Received batches ayaa stock noqda, accounting-na wuxuu diiwaangeliyaa qiimaha iyo daynta khuseysa. Dalab keliya looma tiriyo stock.


### Purchase Returns

**Ujeeddo:** Supplier-ka ugu celi alaab laga soo helay purchase order.

**Goorta:** Marka alaab received ah dib loo celinayo, user-kuna leeyahay purchases.return permission.

**Waxa la qabto:** Ka dooro original purchase receipt batches-ka; geli quantity-ga iyo sababta return-ka. Raac payable reduction/refund-ka ku habboon.

**Faa’iido:** Return-ku wuxuu ku xiran yahay alaabtii dhab ahaan laga helay supplier-kaas, stock iyo balance-na waa la raadraaci karaa.


### Supplier Bills (AP)

**Ujeeddo:** La soco waajibaadka lacagta ee suppliers-ka.

**Goorta:** Kadib receiving ama marka la hubinayo bills weli aan la wada bixin.

**Waxa la qabto:** Eeg bill, supplier, total, lacagta la bixiyey iyo hadhaaga; la xiriiri receiving/purchase reference-ka.

**Faa’iido:** Waxaad ogaan kartaa cidda farmashiyuhu lacag ku leeyahay iyo hadhaaga bill kasta.


### Supplier Payments

**Ujeeddo:** Diiwaangeli lacagaha supplier-ka la siiyey.

**Goorta:** Marka bill dhan ama qayb ka mid ah la bixinayo.

**Waxa la qabto:** Dooro supplier/bill-ka khuseeya, account/payment method, amount iyo reference.

**Faa’iido:** Lacag bixinta waxaa lala xiriiriyaa payable-ka iyo ledger-ka si looga hortago is-waafaq la’aan.


## Customers

Xisaabaadka macaamiisha iyo lacag ururinta

Sections: Customer Accounts


### Customers

**Ujeeddo:** Diiwaangeli macaamiisha leh account iyo iib lagu xiro.

**Goorta:** Marka loo baahan yahay taariikhda ganacsi ama iib dayn ah oo qof gaar ah.

**Waxa la qabto:** Geli magaca iyo xiriirka; ku xiro customer-ka sale-ka. Patient-ka waxaa loogu talagalay xogta bukaan-socodka; customer-ku waa xiriirka ganacsi.

**Faa’iido:** Waxaad kala ogaan kartaa iibka, collections-ka iyo customer balance-ka.


### Customer Receipts

**Ujeeddo:** Qabso lacagaha invoices hore oo aan weli la wada bixin.

**Goorta:** Marka customer-ku bixiyo dayn hore ama qayb ka mid ah invoice.

**Waxa la qabto:** Dooro customer iyo invoice-ka, amount, payment account iyo reference; hubi hadhaaga kadib receipt.

**Faa’iido:** Lacag cusub ayaa lagu xiraa iibkii hore; ma aha in iib kale loo sameeyo lacag ururinta.


### Customer Deposits

**Ujeeddo:** Diiwaangeli advance balances ama store credit-ka customer-ka.

**Goorta:** Marka macmiil lacag horudhac ah ku shubo account-kiisa.

**Waxa la qabto:** Dooro customer, deposit amount iyo payment details; maamul isticmaalka credit-ka sida workflow-ga account-ku oggol yahay.

**Faa’iido:** Advance-ka waxaa laga kala saaraa dakhliga iibka iyo lacag ururinta invoice hore.


## Finance

Xisaabaadka iyo dejinta lacagaha

Sections: Accounting / Configuration


### Chart of Accounts

**Ujeeddo:** Qaabee xisaabaadka ledger-ka farmashiyaha.

**Goorta:** Xilliga setup-ka iyo marka accountant-ku account cusub u baahan yahay.

**Waxa la qabto:** Groups-ka accounts: Assets, Liabilities, Equity, Revenue iyo Expenses; dooro group oo geli account-ka ku habboon.

**Faa’iido:** Iibka, purchases, cash, inventory iyo expenses waxay leeyihiin meel sax ah oo lagu soo bandhigo.


### Journal Entries

**Ujeeddo:** Eeg ama geli journal debit/credit ah oo la oggol yahay.

**Goorta:** Marka accountant-ku baarayo postings ama sixitaan accounting loo baahan yahay.

**Waxa la qabto:** Dooro accounts, debit/credit, date iyo sharaxaad; hubi in debit iyo credit isu dheellitiran yihiin.

**Faa’iido:** Waxay taageertaa raadraaca iyo saxidda xisaabaadka iyadoo business transactions-ka lala xiriirin karo.


### POS Payment Methods

**Ujeeddo:** Ku xiro hababka lacag bixinta POS-ka ledger accounts-kooda.

**Goorta:** Ka hor iibka ama marka la kordhinayo payment method la isticmaalo.

**Waxa la qabto:** Samee magaca method-ka sida Cash ama Mobile Money; ku xiro ledger account sax ah, dejina active status. Tani waa diiwaangelin accounting; provider integration gaar ah lama ballanqaadayo.

**Faa’iido:** Cash iyo hababka kale si kala cad ayay ugu muuqan karaan xisaabaadka.


### Financial Periods

**Ujeeddo:** Maamul xilliyada xisaabeed iyo xiritaankooda.

**Goorta:** Marka la diyaarinayo bil ama sannad cusub ama xisaab la xirayo.

**Waxa la qabto:** Eeg periods-ka; isticmaal furid/xirid iyadoo la raacayo awoodda iyo nidaamka accounting-ka.

**Faa’iido:** Waxay taageertaa kala dambaynta xilliyada warbixinta iyo posting-ka.


### Tax Management

**Ujeeddo:** Deji tax percentage-ka firfircoon ee POS.

**Goorta:** Marka la bilaabayo system-ka ama cashuurta ganacsigu is beddesho.

**Waxa la qabto:** User awood u leh ayaa dejinaya rate-ka khuseeya; hubi sale tusaale ah ka hor isticmaalka rasmiga ah.

**Faa’iido:** Tax-ka iibka waxaa loo xisaabiyaa sida configuration-ku yahay. Rate-ka iyo shuruudaha waddanka waxaa xaqiijiya ganacsiga/accountant-ka.


## Reports & Insights

Warbixinnada hawlgalka iyo maaliyadda

Sections: Operations / Financial Statements


### Pharmacy Reports

**Ujeeddo:** La soco expiry, low stock iyo batch balances.

**Goorta:** Maalin kasta marka dib u dalab iyo stock-risk la eegayo.

**Waxa la qabto:** Eeg medicines dhowaan dhacaya, dhacay, stock hooseeya iyo physical/sellable quantities.

**Faa’iido:** Waxay caawisaa qorshaynta purchase-ka iyo ka shaqaynta batches u baahan baaris.


### Sales Reports

**Ujeeddo:** Falanqee iibka muddo la doortay iyo alaabta la iibiyey.

**Goorta:** Dhamaadka maalinta, toddobaadka ama bisha.

**Waxa la qabto:** Dooro date range; eeg iibka iyo breakdown-ka report-ku bixiyo.

**Faa’iido:** Waxaad garan kartaa dhaqdhaqaaqa iibka iyo medicines-ka dalabkoodu badan yahay.


### Inventory Valuation

**Ujeeddo:** Eeg qiimaha stock-ka ku jira inventory-ga.

**Goorta:** Marka maamulaha ama accountant-ku rabo cost-basis stock value.

**Waxa la qabto:** Dooro filters-ka report-ka; dib u eeg quantities iyo values marka la waafajinayo ledger-ka.

**Faa’iido:** Waxaad aragtaa lacagta ku xiran alaabta harsan.


### Customer Report

**Ujeeddo:** Isku eeg customer sales, collections iyo balances.

**Goorta:** Marka daymaha macaamiisha la daba galayo ama statement loo diyaarinayo.

**Waxa la qabto:** Dooro customer/period; isbarbar dhig invoices, receipts iyo closing balance.

**Faa’iido:** Waxay fududaysaa collections-ka iyo ka jawaabista su’aalaha account-ka.


### Supplier Report

**Ujeeddo:** Isku eeg purchases, receiving, bills iyo supplier balances.

**Goorta:** Marka supplier statement la waafajinayo ama lacag bixin la qorshaynayo.

**Waxa la qabto:** Dooro supplier/period; eeg dhaqdhaqaaqyada iyo closing balance.

**Faa’iido:** Waxay taageertaa hubinta waxa la helay iyo waxa weli la bixinayo.


### Financial Statements

**Ujeeddo:** Ka gal ururinta warbixinnada maaliyadda.

**Goorta:** Marka maamulka ama accountant-ku qiimaynayo xaaladda ganacsiga.

**Waxa la qabto:** Dooro muddada iyo statement-ka ku habboon; la barbar dhig reports-ka faahfaahsan.

**Faa’iido:** Hal meel ayaa laga gaaraa warbixinnada ledger-ku saldhigga u yahay.


### Income Statement

**Ujeeddo:** Soo bandhig dakhliga iyo kharashaadka muddada.

**Goorta:** Marka faa’iidada ama khasaaraha xilli la qiimaynayo.

**Waxa la qabto:** Dooro date range; eeg revenue, costs iyo expenses lagu diiwaangeliyey ledger-ka.

**Faa’iido:** Waxay muujisaa waxqabadka maaliyadeed ee muddada marka xogta la geliyey dhammaystiran tahay.


### Balance Sheet

**Ujeeddo:** Eeg assets, liabilities iyo equity taariikh gaar ah.

**Goorta:** Marka xaaladda maaliyadeed ee farmashiyaha la baarayo.

**Waxa la qabto:** Dooro as-of date; eeg stock/cash/receivables iyo payables/accounts kale.

**Faa’iido:** Waxay muujisaa waxa ganacsigu leeyahay iyo waajibaadkiisa.


### Cash Flow

**Ujeeddo:** La soco dhaqdhaqaaqa lacagta ee muddada.

**Goorta:** Marka la qorshaynayo awoodda bixinta iyo baahida cash-ka.

**Waxa la qabto:** Dooro period; dib u eeg lacagaha soo galay iyo kuwa baxay sida ledger-ku u kala saaray.

**Faa’iido:** Waxay ka caawisaa maamulka fahamka lacagta dhaqdhaqaaqaysa, oo ka duwan iibka invoice ahaan la diiwaangeliyey.


### Trial Balance

**Ujeeddo:** Isbarbar dhig account balances, debits iyo credits.

**Goorta:** Ka hor xiritaanka period ama marka accountant-ku hubinayo ledger-ka.

**Waxa la qabto:** Dooro period/date; hubi totals-ka iyo accounts aan caadi ahayn; raadi journals-ka saldhigga u ah.

**Faa’iido:** Waa qalab muhiim u ah reconciliation-ka iyo diyaargarowga financial statements.


## Administration

Shaqaalaha, permissions-ka iyo aqoonsiga farmashiyaha

Sections: People & Access / Settings


### Staff Users

**Ujeeddo:** Maamul accounts-ka shaqaalaha iyo xaaladdooda.

**Goorta:** Marka shaqaale cusub bilaabo, role is beddelo ama access la joojiyo.

**Waxa la qabto:** Samee user, u qoondee role-ka ku habboon, maamul active status iyo xogta account-ka.

**Faa’iido:** Qof walba wuxuu isticmaalaa account u gaar ah si hawlaha loo kala garan karo.


### Roles & Permissions

**Ujeeddo:** Xaddid modules-ka, menu access iyo actions-ka shaqaalaha.

**Goorta:** Ka hor shaqaalaha la siinayo system-ka ama masuuliyad cusub.

**Waxa la qabto:** Deji role; dooro menu grants iyo action permissions sida iib, prescriptions approval, receiving iyo refunds. Operational Settings waxaa loogu talagalay superadmin.

**Faa’iido:** Shaqaale kastaa wuxuu arkaa oo sameeyaa waxa masuuliyaddiisa loo oggolaaday.


### Audit Trail Log

**Ujeeddo:** Dib u eeg hawlaha system-ka ee audit-ku diiwaangeliyey.

**Goorta:** Marka dhacdo la baarayo ama maamuluhu hubinayo isbeddel.

**Waxa la qabto:** Raadi/filter garee user, action iyo waqtiga sida boggu taageero; tixraac transaction-ka khuseeya.

**Faa’iido:** Waxay kordhisaa isla xisaabtanka iyo baarista dhaqdhaqaaqyada.


### Pharmacy Settings

**Ujeeddo:** Deji aqoonsiga farmashiyaha iyo muuqaalka receipt-ka.

**Goorta:** Xilliga onboarding-ka ama marka branding/configuration la beddelayo.

**Waxa la qabto:** Tabs: Business Profile — magaca iyo xiriirka; Receipt & Tax — receipt branding iyo dejinta khuseysa; Operational Controls — features configuration. Full Audit Trail waa link bogga audit-ka. Appearance iyo printer setup waxaa laga maamulaa dejimaha ay khuseeyaan.

**Faa’iido:** Invoices iyo receipts-ku waxay yeeshaan aqoonsiga ganacsiga; configuration-ka waxaa maamula user awood u leh.


### Operational Settings

**Ujeeddo:** Dejimaha hawlgalka ee la ilaaliyo.

**Goorta:** Marka superadmin-ku u baahan yahay configuration heer hawlgal ah.

**Waxa la qabto:** Kaliya qofka awoodda gaarka ah leh ayaa geli kara; dib u eeg saamaynta dejinta ka hor keydin.

**Faa’iido:** Waxay kala saartaa hawlaha maalinlaha ah iyo dejimaha xasaasiga ah.


## Workflows


### Bilowga farmashiyaha

Masuul: Owner / Manager / Accountant

1. **Aqoonsi iyo accounts — Pharmacy Settings:** Deji magaca, receipt identity, currency/settings-ka jira iyo cash/payment accounts.

2. **Shaqaale iyo access — Roles & Permissions:** Abuur users iyo roles; kala saar selling, approval, receiving iyo refund permissions.

3. **Catalog diyaari — Medicines & Units:** Samee categories; geli medicine details, dosage form iyo unit conversions/qiimayaal.

4. **Opening stock — Batches & Stock:** Tiri stock-ka jira; geli quantities, batches iyo expiry. Stock laga soo iibsaday supplier cusub raaci receiving workflow.

5. **Xaqiiji bilowga — Trial Balance:** Accountant-ku ha waafajiyo opening stock iyo opening balances; tababar shaqaalaha ka hor go-live.


### Dalab ilaa supplier payment

Masuul: Storekeeper / Purchasing / Accountant

1. **Dooro supplier — Suppliers List:** Diiwaangeli supplier-ka iyo xiriirkiisa.

2. **Dalbo medicines — Purchase Orders:** Geli medicines, quantities iyo costs. Tallaabadani stock ma kordhiso.

3. **Qaabil alaabta — Goods Receiving:** Hubi waxa yimid; geli batch, expiry iyo quantity-ga dhab ahaan la helay. Halkan stock iyo payable ayaa samaysma.

4. **Hubi bill-ka — Supplier Bills (AP):** Eeg total-ka, lacagtii hore iyo hadhaaga bill-ka.

5. **Bixi lacagta — Supplier Payments:** Diiwaangeli lacag bixinta iyo account-ka; hubi supplier balance-ka report-ka.


### OTC: 1 strip + 5 tablets ilaa receipt

Masuul: Cashier / Pharmacist

1. **Raadi ama scan — Dispensing Workspace:** Dooro medicine aan prescription u baahnayn; hubi unit options iyo sellable stock.

2. **Ku dar strip — Dispensing Workspace:** Samee line: quantity 1, unit strip. Tusaalaha conversion-ku waa 10 tablets.

3. **Ku dar loose units — Dispensing Workspace:** Isla medicine ku dar line kale: quantity 5, unit tablet. Wadar stock impact = 15 base units.

4. **Lacag iyo save — Dispensing Workspace:** Hubi totals; dooro payment method, geli paid amount oo complete sale samee.

5. **Receipt & reprint — Sales Orders:** Receipt modal ayaa furma; Print samee. Mar dambe isla invoice-ka ka hel recent receipts ama Sales Orders si dib loogu daabaco.


### Prescription ilaa partial/full dispensing

Masuul: Pharmacist / Authorized approver

1. **Bukaanka raadi — Patients:** Hubi identity, contact iyo allergies-ka la diiwaangeliyey.

2. **Qor prescription — Prescriptions:** Geli prescriber/reference, medicines, authorized quantities iyo directions.

3. **Ansixi — Prescriptions:** Qof awood u leh ayaa dib u eegaya oo approve gareeya. System-ku ma bixinayo talo dose.

4. **Bixi qaybta la rabo — Dispensing Workspace:** Dooro prescription-ka la ansixiyey. Quantities-ka unit kasta waxaa la isu geeyaa base units si looga hubiyo inta hartay.

5. **Raad iyo label — Prescriptions:** La soco inta la bixiyey iyo inta hartay; print garee directions-ka hore loo qoray haddii label loo baahan yahay. Return-ku entitlement si otomaatig ah uma soo celiyo.


### Iib dayn ah ilaa lacag ururin

Masuul: Cashier / Accounts

1. **Customer account — Customers:** Dooro ama samee customer-ka iibka lagu xiriirinayo.

2. **Samee iibka — Dispensing Workspace:** Ku xiro customer-ka; paid amount-ka dhabta ah geli. Hadhaaga waxaa lagu hayaa invoice-ka.

3. **Qabso dayn bixinta — Customer Receipts:** Marka lacag dambe timaaddo, ku qor invoice-kii hore; iib kale ha abuurin.

4. **Waafaji account-ka — Customer Report:** Hubi invoices, receipts iyo closing balance. Advance lacag ah ku maamul Customer Deposits marka ay khusayso.


### Customer return ilaa quarantine

Masuul: Authorized refunds / Pharmacist

1. **Raadi original sale — Sales Orders:** Xaqiiji invoice-ka iyo alaabtii hore loo iibiyey.

2. **Diiwaangeli return — Sales Returns:** Dooro quantity la soo celinayo iyo sababta; raac refund/debt adjustment-ka.

3. **Kala saar stock-ka — Batches & Stock:** Customer-return stock wuxuu galayaa quarantine; si caadi ah dib looguma fasaxo sale.

4. **Hubi saamaynta — Stock Levels & Ledger:** Dib u eeg stock movement iyo lacagta; raac disposal workflow-ga haddii alaabta la saarayo.


### Supplier return iyo balance adjustment

Masuul: Purchasing / Accounts

1. **Hel receipt-kii hore — Goods Receiving:** Aqoonso purchase order iyo batch-kii dhab ahaan la helay.

2. **Samee return — Purchase Returns:** Dooro received batch iyo quantity-ga la celinayo; qoraal sabab ah raaci.

3. **Hubi xisaabta — Supplier Report:** Dib u eeg payable reduction ama refund-ka khuseeya iyo stock reduction-ka.


### Expiry, quarantine iyo disposal

Masuul: Pharmacist / Storekeeper

1. **Soo hel batches-ka — Pharmacy Reports:** Eeg near-expiry, expired iyo low-stock medicines.

2. **Xaddid batch-ka — Batches & Stock:** Baar batch-ka; quarantine ama recall ku samee marka loo baahan yahay. Expired/blocked batches lagama iibin karo POS.

3. **Disposition — Batches & Stock:** Raac go’aanka qofka awoodda leh; disposal/correction ku qor sabab. Customer-return lots dib looguma fasaxo ordinary release.

4. **Waafaji stock — Stock Levels & Ledger:** Hubi hadhaaga iyo movement-ka; financial impact-ka accountant-ku ha dib u eego.


### Hubinta maalinta ilaa financial review

Masuul: Manager / Accountant

1. **Iibka hubi — Sales Reports:** Dooro maanta; hubi invoices, returns iyo totals.

2. **Lacagaha iyo daymaha — Customer Report:** Waafaji cash/payment accounts, collections iyo customer balances; supplier balances-na eeg.

3. **Stock iyo expiry — Pharmacy Reports:** Dib u eeg low stock iyo batches u baahan tallaabo; diyaari dalabyada berrito.

4. **Xisaab review — Trial Balance:** Accountant-ku ha eego journals, trial balance iyo statements. Period xiriddu waa hawl la oggolaaday oo ka dambaysa reconciliation.


### CSV onboarding ilaa opening batches

Masuul: Manager / Stockkeeper

1. **Diyaari master data — Medicines & Units:** Samee categories, dosage forms iyo units-ka loo baahan yahay.

2. **Preview file — Medicine Import:** Buuxi CSV template-ka oo hubi row errors. Preview wax ma keydiyo.

3. **Import xaqiiji — Medicine Import:** Keydi medicines, units iyo opening batches hal transaction; duplicate retries stock ma labanlaabaan.

4. **Hubi stock-ka — Batches & Stock:** Waafaji opening base quantities, batches iyo values-ka la soo geliyey.


### Sealed bottle ilaa opened-unit dispensing

Masuul: Pharmacist / Stockkeeper

1. **Deji source policy — Medicines & Units:** Bottle/pack u dooro sealed; ml ama loose units u dooro opened haddii workflow-gaasi khuseeyo.

2. **Fur pack-ka — Opened Packs & Use-by:** Dooro source batch, whole packs iyo staff-approved use-by date/time.

3. **Quantity transfer — Opened Packs & Use-by:** System-ku wuxuu sameeyaa opened lot; physical quantity iyo inventory value waa isku mid.

4. **Qayb ahaan iibi — Dispensing Workspace:** Dooro loose unit; POS-ku wuxuu ka bixiyaa opened pool-ka. Past-use-by lots waa blocked.


### Cashier shift ilaa closing variance

Masuul: Cashier / Manager

1. **Drawer fur — Cash Shifts:** Dooro cash account oo qor physical opening float.

2. **Iib iyo payments — Dispensing Workspace:** Samee sales; split tender waa la taageeraa. Cash-ka la applied gareeyey ayaa khasnadda khuseeya; electronic tender waa gooni.

3. **Count & close — Cash Shifts:** Geli lacagta dhab ahaan la tiriyey; farqi jira ku sharax closing note.

4. **Review — Cash Shifts:** Management-ku wuxuu eegaa expected, counted iyo variance. Correction journal waxaa la sameeyaa kaliya marka la ansixiyo, si gaar ah.


### Physical count ilaa approved adjustment

Masuul: Stockkeeper / Authorised approver

1. **Snapshot bilow — Physical Stock Counts:** Dooro medicine ama dhammaan batches-ka la diiwaangeliyey.

2. **Tiri oo qor — Physical Stock Counts:** Geli counted base quantity iyo sababta farqiga; draft kaydi haddii loo baahan yahay.

3. **Submit — Physical Stock Counts:** Dhammaan batches-ka tiri oo u gudbi review. Stock wali lama beddelin.

4. **Approve ama recount — Physical Stock Counts:** Approval permission ayaa loo baahan yahay. Movement dhacay kadib snapshot-ka wuxuu dalbanayaa cancel iyo recount.

5. **Ledger hubi — Stock Levels & Ledger:** Adjustment-ka la approve gareeyey wuxuu ku xiran yahay count session-ka iyo accounting-ka khuseeya.


### Storage check ilaa excursion action

Masuul: Pharmacist / Storekeeper

1. **Location diyaari — Storage & Cold Chain:** Magac iyo acceptable limits ka deji instructions-ka la ansixiyey.

2. **Qor reading — Storage & Cold Chain:** Geli temperature iyo observation time.

3. **Record action — Storage & Cold Chain:** Out-of-range reading wuxuu dalbanayaa action; history-ga waa la hayaa.

4. **Stock decision — Batches & Stock:** Qofka awoodda leh ayaa baaraya batches-ka oo sameeya quarantine/recall/disposal haddii loo baahan yahay.


## Units example

Tablet = 1 base unit; strip = 10; box = 100. 1 strip + 5 tablets = 15 base units. Unit prices are configured independently. Dosage form (tablet/syrup/etc.), category and selling unit are separate concepts.


## Configuration & delivery

Core pharmacy labels support English/Somali; inherited finance/admin screens are mainly English. Receipt browser/PDF printing is supported; physical printer compatibility and any QZ setup must be verified for the customer. Tax, payment accounts and operational settings require local configuration. Prescription directions are recorded by staff; no automated dosing or interaction checking is represented. This proposal describes the current retail pharmacy web application; it does not promise insurance integration, e-prescribing integration or certification. Pricing, hosting, support and rollout scope are agreed separately.


## First meeting / demo agenda

1. Understand the pharmacy’s medicines, units, staff and current stock.

2. Demonstrate medicine setup and batch receiving.

3. Demonstrate a strip + loose-unit sale, receipt and reprint.

4. Demonstrate prescription approval, partial dispensing and returns.

5. Review expiry reports, customer/supplier balances and permissions.

6. Agree deployment, data preparation, training, support and commercial terms.