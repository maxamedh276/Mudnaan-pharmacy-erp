# Inventory workflow deployment — 7 November 2026

Developer architecture, request flows and code map: [Inventory and POS developer guide](INVENTORY_POS_DEVELOPER_GUIDE.md).

## Production database updates

Back up the tenant database and deploy during a maintenance window. These scripts are applied once per tenant, against that tenant database. MySQL/MariaDB schema changes auto-commit; do not rely on a transaction to undo a partially applied script.

1. Check whether `products.package_unit` and `package_movements` already exist. If absent, run `docs/sql/2026-11-07_inventory_packages.sql` first. This is the prerequisite from the earlier package-inventory feature. If already installed, skip it.
2. Check whether `product_opening_stocks` exists and `products.creation_request_hash` is present. If this workflow update is not installed, run `docs/sql/2026-11-07_inventory_workflow.sql` once. If both already exist, verify the remaining columns below rather than running it again.
3. Deploy the PHP, views and asset files together after the schema update. The new code requires the new schema. Refresh browser tabs.
4. For each package product, review its low-stock threshold: it now means sealed package count, not total kg/litres. No threshold numbers are automatically rescaled because the intended business threshold must be chosen by the shop.

Example from the MySQL client (select your production tenant database first):

```sql
SOURCE /path/to/docs/sql/2026-11-07_inventory_packages.sql;
-- Run the preceding line only if package inventory has NOT already been installed.
SOURCE /path/to/docs/sql/2026-11-07_inventory_workflow.sql;
```

Do not rerun either SQL file blindly: existing columns/tables produce duplicate-object errors. If deployment was interrupted, compare the schema with this list and apply only missing statements after reviewing the database backup.

### Prerequisite package patch (17)

- `products`: package_unit, package_size, package_price; cost_price changes to DECIMAL(18,6).
- `sale_items` and `purchase_items`: stock_factor, unit_label, stock_mode.
- New `package_movements` ledger for sealed counts and opening packages.

### New workflow patch (18)

- New `selling_option_groups`: id, name, unit, options_json, is_active, created_at, updated_at. Options JSON stores ordered labels and quantities only, never product prices.
- `products`: selling_group_id (nullable), default_sale_mode (loose/sealed, default loose), creation_request_key (nullable unique), creation_request_hash (nullable). The request fields prevent duplicate product creation/opening balances after a network retry.
- `products.reorder_level`: changes from integer to DECIMAL(15,3), preserving existing values. Package thresholds must still be whole numbers, enforced by server validation.
- `web_stock_adjustment_details`: stock_mode (default loose), selected_quantity (nullable), unit_label (nullable). Existing adjustments remain base-unit adjustments; historical values are not rewritten.
- New `product_opening_stocks`: id, product_id, branch_id, sealed_quantity, opened_quantity, base_unit_cost, total_value, created_by, created_at. Unique product_id + branch_id prevents duplicate opening records.
- No separate stock_type column: Sealed means the product has configured package details; Non-Sealed has no package details.
- No static fraction names or quantities are inserted. Create Selling Options groups using the names your shop uses, then assign them to products.

These patches preserve existing stock quantities, financial documents, prices and package history. Existing stock is not guessed to be sealed. Do not replace real product rows with the test examples.

## Using the updated workflow

1. Inventory > Selling Options: create a group, choose its unit, and add named quantities (for example your own names for 0.25, 0.5 or 0.125 kg). Groups can be edited or deactivated. An assigned group cannot change its unit; create another group for a different unit.
2. Products: choose Sealed or Non-Sealed. Sealed requires package name, capacity and package selling price; use kg or litre as the base unit. Choose a default POS unit and optionally a matching active shortcut group.
3. New products can include opening stock. For Non-Sealed, enter the main quantity and cost per main unit. For Sealed, enter whole sealed packages, already-opened kg/litres, and cost per sealed package. The opened portion uses the same derived cost per kg/litre. The form is available to users with stock.adjust permission.
4. Opening stock is recorded once at creation in the current branch. It is not a supplier purchase or supplier debt. Premium debits Inventory and credits Owner’s Capital for the opening value; it does not create sales income or supplier debt. Product creation, opening stock, sealed counts and accounting all commit together or roll back together. A closed period rejects the stocked product creation.
5. Existing products use Adjust stock for later corrections; product editing cannot reset opening balances. When enabling packages for an existing product, existing quantities remain opened/base stock. Do not enter them again as opening stock.
6. Purchase order unit has its own column. Single-unit products show a plain unit label. The cost entered is per selected purchase unit.
7. Stock shows separate main, sealed, opened and total-equivalent columns. Non-applicable cells are empty, applicable zero balances show 0. Low-stock alerts compare sealed counts for package products and main quantities for ordinary products.
8. Adjust stock asks which pool to change for package products. Sealed +/-1 means one package; opened +/-1 means one kg/litre. The preview includes the selected unit. Decreases cannot borrow from the other pool. Opening a sealed package remains a separate action and does not change total value.
9. POS product clicks add the saved default unit directly. Type into the quantity field, use configured shortcuts to replace the quantity, or select another selling unit on the row. The selected quantity option also controls +/− increments; choosing kg/litre/custom restores steps of 1. Ordinary products need no shortcut group. The desktop ticket is wider; mobile retains the separate ticket view.

## Tests retained in the repository

```powershell
& C:\xampp\php\php.exe tests/inventory_workflow_smoke.php
& C:\xampp\php\php.exe tests/inventory_workflow_schema_smoke.php
& C:\xampp\php\php.exe tests/packaged_inventory_smoke.php
node tests/packaged_inventory_ui_smoke.cjs
node tests/purchase_quantity_units_smoke.cjs
```

The workflow suite covers both opening stock types, opening cost conversion, duplicate retries, sealed/opened adjustments, shortage guards, package opening, reusable selling options, sealed low-stock thresholds, and full rollback on accounting failure. It fingerprints the original records after dropping connection-local temporary tables. No test financial records are inserted into real tables. The previous purchasing, catalog, adjustments, returns, POS tax/reset/payment/printing tests are also retained.

Optional read-only previews use actual production views with temporary test data and block saving:

```powershell
& C:\xampp\php\php.exe tests/packaged_inventory_smoke.php --preview-products
& C:\xampp\php\php.exe tests/packaged_inventory_smoke.php --preview-pos
& C:\xampp\php\php.exe tests/packaged_inventory_smoke.php --preview-stock
& C:\xampp\php\php.exe tests/packaged_inventory_smoke.php --preview-selling
& C:\xampp\php\php.exe tests/packaged_inventory_smoke.php --preview-purchases
```

Generated HTML lives in ignored `results/packaged-inventory/`. These previews contain synthetic stock and cannot save transactions. Live authenticated checkout was not performed; financial scenarios are verified against temporary tables.

The local database already has patches 17 and 18. Production has not been modified. Mobile/API controllers and /api/v1 routes remain unchanged.
