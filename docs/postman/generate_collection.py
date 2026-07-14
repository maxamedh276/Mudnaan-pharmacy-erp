#!/usr/bin/env python3
"""
Generates the Mudnaan ERP Postman collection + environment.

Run:  python generate_collection.py
Emits: MudnaanERP.postman_collection.json
       MudnaanERP-Local.postman_environment.json

Regenerate whenever endpoints change — never hand-edit the JSON.
"""
import json
import uuid
from pathlib import Path

BASE = "{{base_url}}"


def var(key, value=""):
    return {"key": key, "value": value}


def test_script(lines):
    return {
        "listen": "test",
        "script": {"type": "text/javascript", "exec": lines},
    }


def status_tests(code, success=None):
    lines = [
        f"pm.test('Status is {code}', () => pm.response.to.have.status({code}));",
    ]
    if success is True:
        lines += [
            "pm.test('Envelope success=true', () => {",
            "    const body = pm.response.json();",
            "    pm.expect(body.success).to.be.true;",
            "    pm.expect(body).to.have.property('data');",
            "});",
        ]
    elif success is False:
        lines += [
            "pm.test('Envelope success=false with error code', () => {",
            "    const body = pm.response.json();",
            "    pm.expect(body.success).to.be.false;",
            "    pm.expect(body.error).to.have.property('code');",
            "    pm.expect(body.error).to.have.property('message');",
            "});",
        ]
    return lines


def capture(var_name, json_path="data.id"):
    return [
        f"pm.collectionVariables.set('{var_name}', "
        f"pm.response.json().{json_path});",
    ]


def error_code_test(code):
    return [
        f"pm.test('Error code is {code}', () => "
        f"pm.expect(pm.response.json().error.code).to.eql('{code}'));",
    ]


def req(name, method, path, *, body=None, description="", tests=None,
        auth_token="{{token}}", no_auth=False, query=None):
    url_path = path.lstrip("/")
    url = {
        "raw": f"{BASE}/{url_path}" + (f"?{query}" if query else ""),
        "host": [BASE],
        "path": url_path.split("/"),
    }
    if query:
        url["query"] = [
            {"key": part.split("=")[0], "value": part.split("=", 1)[1]}
            for part in query.split("&")
        ]
    headers = [{"key": "Content-Type", "value": "application/json"}]
    if not no_auth:
        # Explicit header instead of Postman's auth helper — works
        # identically in the Postman app, Newman and CI.
        headers.append({"key": "Authorization", "value": f"Bearer {auth_token}"})
    request = {
        "method": method,
        "header": headers,
        "url": url,
        "description": description,
        "auth": {"type": "noauth"},
    }
    if body is not None:
        request["body"] = {
            "mode": "raw",
            "raw": json.dumps(body, indent=2),
            "options": {"raw": {"language": "json"}},
        }
    item = {"name": name, "request": request}
    if tests:
        item["event"] = [test_script(tests)]
    return item


def folder(name, description, items):
    return {"name": name, "description": description, "item": items}


# ----------------------------------------------------------------------
#  FOLDERS — ordered so the whole collection can run top-to-bottom.
# ----------------------------------------------------------------------

authentication = folder("Authentication", (
    "Login stores the JWT in {{token}} automatically; every other request "
    "uses it via collection-level bearer auth. Demo users: yasir@demo.com "
    "(Owner, all permissions) and cashier@demo.com (Cashier, POS-level "
    "permissions), both password Secret123!."
), [
    req("Login (Owner) — stores token", "POST", "auth/login",
        no_auth=True,
        body={"identity": "{{identity}}", "password": "{{password}}"},
        description="Public. Returns token + user + plan + permissions. "
                    "Stores {{token}} for the whole collection.",
        tests=status_tests(200, True) + [
            "// 'data' is reserved in the Postman sandbox — use another name.",
            "const payload = pm.response.json().data;",
            "pm.collectionVariables.set('token', payload.token);",
            "pm.test('Token + permissions present', () => {",
            "    pm.expect(payload.token).to.be.a('string');",
            "    pm.expect(payload.permissions).to.be.an('array');",
            "});",
        ]),
    req("Login (Cashier) — stores cashier_token", "POST", "auth/login",
        no_auth=True,
        body={"identity": "cashier@demo.com", "password": "Secret123!"},
        description="Used by the Access Control folder to prove 403s.",
        tests=status_tests(200, True) + [
            "pm.collectionVariables.set('cashier_token', pm.response.json().data.token);",
        ]),
    req("Login — wrong password (401)", "POST", "auth/login",
        no_auth=True,
        body={"identity": "{{identity}}", "password": "definitely-wrong"},
        description="Expected error case: INVALID_CREDENTIALS.",
        tests=status_tests(401, False) + error_code_test("INVALID_CREDENTIALS")),
    req("Login — missing fields (422)", "POST", "auth/login",
        no_auth=True, body={},
        description="Validation failure: identity and password required.",
        tests=status_tests(422, False) + error_code_test("VALIDATION_ERROR")),
    req("Me (current session)", "GET", "auth/me",
        description="Requires a valid token. Returns user + plan + permissions.",
        tests=status_tests(200, True)),
    req("Me — no token (401)", "GET", "auth/me", no_auth=True,
        description="Expected error case: UNAUTHORIZED.",
        tests=status_tests(401, False)),
    req("Refresh token", "POST", "auth/refresh",
        description="Issues a fresh JWT for a still-valid session.",
        tests=status_tests(200, True)),
    req("Logout", "POST", "auth/logout",
        description="Stateless: client discards the token afterwards. "
                    "(The collection keeps using {{token}}, which stays valid.)",
        tests=status_tests(200, True)),
])

dashboard = folder("Dashboard", (
    "Home-screen KPIs. Permission: reports.basic (all roles, all plans)."
), [
    req("Dashboard KPIs", "GET", "reports/dashboard",
        description="today {total_sales, net_profit, transactions}, stock "
                    "alerts, pending purchases, recent sales, 7-day curve.",
        tests=status_tests(200, True) + [
            "const d = pm.response.json().data;",
            "pm.test('Has KPI sections', () => {",
            "    pm.expect(d).to.have.property('today');",
            "    pm.expect(d).to.have.property('stock');",
            "    pm.expect(d).to.have.property('recent_sales');",
            "});",
        ]),
])

categories = folder("Categories", (
    "Product grouping. Permissions: products.view / products.create."
), [
    req("List categories", "GET", "categories",
        tests=status_tests(200, True)),
    req("Create category", "POST", "categories",
        body={"name": "Postman Test Category"},
        description="Stores {{category_id}} for the product create below.",
        tests=status_tests(201, True) + capture("category_id")),
])

products = folder("Products", (
    "Catalog with per-branch stock. Permissions: products.view/create/edit/delete."
), [
    req("List products (paginated)", "GET", "products", query="page=1&per_page=10",
        description="Returns items + meta {page, per_page, total, total_pages}.",
        tests=status_tests(200, True) + [
            "pm.test('Has pagination meta', () => "
            "pm.expect(pm.response.json().meta).to.have.property('total'));",
        ]),
    req("Search products", "GET", "products", query="q=cola",
        tests=status_tests(200, True)),
    req("Filter — low stock", "GET", "products", query="stock=low",
        tests=status_tests(200, True)),
    req("Filter — out of stock", "GET", "products", query="stock=out",
        tests=status_tests(200, True)),
    req("Create product (opening stock 100)", "POST", "products",
        body={"name": "Postman Energy Drink 250ml", "sku": "PM-ED250",
              "barcode": "6291041500213", "category_id": "{{category_id}}",
              "cost_price": 0.55, "sell_price": 1.00, "tax_rate": 0,
              "reorder_level": 10, "opening_stock": 100},
        description="opening_stock writes an 'opening' ledger row so the "
                    "product is sellable immediately. Stores {{product_id}}.",
        tests=status_tests(201, True) + capture("product_id") + [
            "pm.test('Opening stock applied', () => "
            "pm.expect(pm.response.json().data.quantity).to.eql(100));",
        ]),
    req("Get product by id", "GET", "products/{{product_id}}",
        tests=status_tests(200, True)),
    req("Update product", "PUT", "products/{{product_id}}",
        body={"sell_price": 1.10, "reorder_level": 12},
        description="Partial update — only sent fields change.",
        tests=status_tests(200, True) + [
            "pm.test('Price updated', () => "
            "pm.expect(pm.response.json().data.sell_price).to.eql(1.1));",
        ]),
    req("Product not found (404)", "GET", "products/999999",
        tests=status_tests(404, False) + error_code_test("NOT_FOUND")),
    req("Create product — validation error (422)", "POST", "products",
        body={"sell_price": -5},
        description="Missing name + negative price.",
        tests=status_tests(422, False) + error_code_test("VALIDATION_ERROR")),
])

inventory = folder("Inventory — Stock", (
    "The stock ledger AND its accounting. Every manual adjustment posts "
    "at cost to the books (Inventory <-> Inventory Adjustment 5100), so "
    "stock_value and book_value stay reconciled. Opening stock on a new "
    "product posts Inventory / Owner's Capital. "
    "Permissions: stock.view / stock.adjust."
), [
    req("Stock overview", "GET", "stock", query="per_page=10",
        description="summary {total_units, stock_value, book_value (the "
                    "Inventory GL balance, Premium), low_stock, out_of_stock} "
                    "+ per-product levels.",
        tests=status_tests(200, True) + [
            "const s = pm.response.json().data.summary;",
            "pm.test('Summary has financial figures', () => {",
            "    pm.expect(s).to.have.property('stock_value');",
            "    pm.expect(s).to.have.property('book_value');",
            "});",
            "pm.collectionVariables.set('stock_value_before', s.stock_value);",
        ]),
    req("Stock movements (all)", "GET", "stock/movements", query="per_page=10",
        description="Each row carries cost_value (|qty| × cost price).",
        tests=status_tests(200, True)),
    req("Stock movements (one product)", "GET", "stock/movements",
        query="product_id={{product_id}}",
        tests=status_tests(200, True)),
    req("Stock in +5 (posts to Inventory)", "POST", "stock/adjustments",
        body={"product_id": "{{product_id}}", "qty_change": 5,
              "note": "Postman count correction"},
        description="Writes a +5 ledger row and, on Premium, posts value "
                    "at cost: DEBIT Inventory / CREDIT Inventory Adjustment. "
                    "Returns {product, value}.",
        tests=status_tests(201, True) + [
            "pm.test('Returns product + value moved', () => {",
            "    const d = pm.response.json().data;",
            "    pm.expect(d).to.have.property('product');",
            "    pm.expect(d).to.have.property('value');",
            "});",
        ]),
    req("Write-off -2 (damage)", "POST", "stock/adjustments",
        body={"product_id": "{{product_id}}", "qty_change": -2,
              "note": "Postman damaged stock write-off"},
        description="Negative adjustment posts DEBIT Inventory Adjustment / "
                    "CREDIT Inventory — a stock loss on the P&L.",
        tests=status_tests(201, True)),
    req("Book value tracks stock value", "GET", "stock", query="per_page=1",
        description="After the adjustments, book_value should still equal "
                    "stock_value (inventory and the ledger stay in step).",
        tests=status_tests(200, True) + [
            "const s = pm.response.json().data.summary;",
            "pm.test('stock_value == book_value', () => {",
            "    if (s.book_value !== null)",
            "        pm.expect(Math.abs(s.stock_value - s.book_value))"
            ".to.be.below(0.01);",
            "});",
        ]),
    req("Adjustment below zero (422)", "POST", "stock/adjustments",
        body={"product_id": "{{product_id}}", "qty_change": -99999},
        description="Guard: stock can never go negative.",
        tests=status_tests(422, False) + error_code_test("INSUFFICIENT_STOCK")),
])

customers = folder("Customers", (
    "The tenant's shoppers. balance: + store credit / - owes us. "
    "Permissions: customers.view / customers.manage."
), [
    req("List customers", "GET", "customers", query="page=1&per_page=10",
        tests=status_tests(200, True)),
    req("Search customers", "GET", "customers", query="q=hassan",
        tests=status_tests(200, True)),
    req("Create customer", "POST", "customers",
        body={"name": "Postman Customer", "phone": "+252 61 5550101",
              "email": "postman.customer@example.com", "address": "Mogadishu"},
        description="Stores {{customer_id}} — used by sales, invoices, "
                    "receipts and deposits below.",
        tests=status_tests(201, True) + capture("customer_id")),
    req("Get customer", "GET", "customers/{{customer_id}}",
        tests=status_tests(200, True)),
    req("Update customer", "PUT", "customers/{{customer_id}}",
        body={"address": "Hodan District, Mogadishu"},
        tests=status_tests(200, True)),
])

suppliers = folder("Suppliers", (
    "Who the tenant buys from. Permissions: suppliers.view / suppliers.manage."
), [
    req("List suppliers", "GET", "suppliers",
        tests=status_tests(200, True)),
    req("Create supplier", "POST", "suppliers",
        body={"name": "Postman Trading LLC", "phone": "+252 61 5550202",
              "email": "sales@postmantrading.example"},
        description="Stores {{supplier_id}} — used by purchases and bills.",
        tests=status_tests(201, True) + capture("supplier_id")),
    req("Get supplier", "GET", "suppliers/{{supplier_id}}",
        tests=status_tests(200, True)),
    req("Update supplier", "PUT", "suppliers/{{supplier_id}}",
        body={"address": "Bakara Market, Mogadishu"},
        tests=status_tests(200, True)),
])

purchases = folder("Purchases", (
    "Goods received — ADDS stock (+qty ledger rows). A purchase that isn't "
    "fully paid auto-creates a linked supplier bill. Premium tenants also "
    "get the journal posting (Inventory / Cash + Accounts Payable). "
    "Permissions: purchases.view / purchases.create."
), [
    req("List purchases", "GET", "purchases", query="page=1&per_page=10",
        tests=status_tests(200, True)),
    req("Create purchase (partially paid → auto-bill)", "POST", "purchases",
        body={"supplier_id": "{{supplier_id}}", "reference_no": "PO-PM-001",
              "paid_amount": 20,
              "items": [{"product_id": "{{product_id}}", "quantity": 50,
                         "unit_cost": 0.50}]},
        description="Total 25.00, pays 20.00 → a supplier bill for the "
                    "outstanding 5.00 appears in Supplier Bills. "
                    "Stores {{purchase_id}}.",
        tests=status_tests(201, True) + capture("purchase_id")),
    req("Get purchase", "GET", "purchases/{{purchase_id}}",
        tests=status_tests(200, True)),
    req("Create purchase — no items (422)", "POST", "purchases",
        body={"supplier_id": "{{supplier_id}}", "items": []},
        tests=status_tests(422, False) + error_code_test("EMPTY_PURCHASE")),
])

sales = folder("Sales / POS", (
    "The POS flow. Totals are always recomputed server-side; a client uuid "
    "makes creation idempotent (offline-ready); credit sales require a "
    "customer and auto-create a customer invoice. "
    "Permissions: sales.view / sales.create."
), [
    req("List sales", "GET", "sales", query="page=1&per_page=10",
        tests=status_tests(200, True)),
    req("Filter sales by status", "GET", "sales", query="status=partial",
        tests=status_tests(200, True)),
    req("Create cash sale", "POST", "sales",
        body={"items": [{"product_id": "{{product_id}}", "quantity": 2}],
              "payments": [{"method": "cash", "amount": 2.20}]},
        description="2 × 1.10 = 2.20 fully paid → status completed. "
                    "Stock -2. Premium: auto journal entry. Stores {{sale_id}}.",
        tests=status_tests(201, True) + capture("sale_id") + [
            "pm.test('Sale completed', () => "
            "pm.expect(pm.response.json().data.status).to.eql('completed'));",
        ]),
    req("Create credit sale (partial → auto-invoice)", "POST", "sales",
        body={"customer_id": "{{customer_id}}",
              "items": [{"product_id": "{{product_id}}", "quantity": 10}],
              "payments": [{"method": "cash", "amount": 5}]},
        description="Pays 5.00 of 11.00 → status partial, customer owes 6.00, "
                    "a customer invoice is auto-created (see Customer Invoices).",
        tests=status_tests(201, True) + [
            "pm.test('Sale is partial', () => "
            "pm.expect(pm.response.json().data.status).to.eql('partial'));",
        ]),
    req("Get sale (receipt shape)", "GET", "sales/{{sale_id}}",
        description="Header + line items + payments.",
        tests=status_tests(200, True) + [
            "pm.test('Has items and payments', () => {",
            "    const d = pm.response.json().data;",
            "    pm.expect(d.items).to.be.an('array');",
            "    pm.expect(d.payments).to.be.an('array');",
            "});",
        ]),
    req("Oversell (422)", "POST", "sales",
        body={"items": [{"product_id": "{{product_id}}", "quantity": 99999}],
              "payments": [{"method": "cash", "amount": 1}]},
        tests=status_tests(422, False) + error_code_test("INSUFFICIENT_STOCK")),
    req("Empty sale (422)", "POST", "sales",
        body={"items": [], "payments": []},
        tests=status_tests(422, False) + error_code_test("EMPTY_SALE")),
    req("Credit without customer (422)", "POST", "sales",
        body={"items": [{"product_id": "{{product_id}}", "quantity": 1}],
              "payments": []},
        tests=status_tests(422, False) + error_code_test("CREDIT_REQUIRES_CUSTOMER")),
])

customer_invoices = folder("Customer Invoices", (
    "Accounts receivable documents. Standalone invoices move stock and "
    "(Premium) post AR / Revenue / COGS; sale-linked ones are created "
    "automatically by partial POS sales and never double-post. "
    "Permissions: invoices.view / invoices.manage."
), [
    req("List invoices", "GET", "customer-invoices", query="page=1&per_page=10",
        tests=status_tests(200, True)),
    req("Filter unpaid invoices", "GET", "customer-invoices", query="status=unpaid",
        tests=status_tests(200, True)),
    req("Create standalone invoice", "POST", "customer-invoices",
        body={"customer_id": "{{customer_id}}", "due_date": "2026-08-31",
              "items": [{"product_id": "{{product_id}}", "quantity": 3},
                        {"description": "Delivery fee", "quantity": 1,
                         "unit_price": 2.00}]},
        description="Product line checks + moves stock; the free-text line "
                    "is a service. Stores {{invoice_id}}.",
        tests=status_tests(201, True) + capture("invoice_id")),
    req("Get invoice (with items + receipts)", "GET",
        "customer-invoices/{{invoice_id}}",
        tests=status_tests(200, True)),
    req("Create invoice — no customer (422)", "POST", "customer-invoices",
        body={"items": [{"description": "x", "quantity": 1, "unit_price": 1}]},
        tests=status_tests(422, False) + error_code_test("CUSTOMER_REQUIRED")),
])

customer_receipts = folder("Customer Receipts", (
    "Money in from customers. Settles an invoice (or goes on account), "
    "raises the customer balance, Premium posts Cash-Bank / AR. "
    "Permissions: invoices.view / invoices.manage."
), [
    req("List receipts", "GET", "customer-receipts",
        tests=status_tests(200, True)),
    req("Receipt against invoice", "POST", "customer-receipts",
        body={"invoice_id": "{{invoice_id}}", "amount": 2.00, "method": "cash",
              "reference": "PM-RCV-1"},
        description="Invoice paid_total rises; status → partial/paid.",
        tests=status_tests(201, True)),
    req("Overpayment (422)", "POST", "customer-receipts",
        body={"invoice_id": "{{invoice_id}}", "amount": 99999},
        tests=status_tests(422, False) + error_code_test("OVERPAYMENT")),
])

customer_deposits = folder("Customer Deposits", (
    "Advances / store credit. Premium posts Cash-Bank / Customer Deposits "
    "(liability 2200). Refund returns the unused amount. "
    "Permissions: invoices.view / invoices.manage."
), [
    req("List deposits", "GET", "customer-deposits",
        tests=status_tests(200, True)),
    req("Create deposit", "POST", "customer-deposits",
        body={"customer_id": "{{customer_id}}", "amount": 25, "method": "cash"},
        description="Customer balance +25 (store credit). Stores {{deposit_id}}.",
        tests=status_tests(201, True) + capture("deposit_id")),
    req("Refund deposit", "POST", "customer-deposits/{{deposit_id}}/refund",
        description="Refunds the unused amount, reverses balance + posting.",
        tests=status_tests(200, True) + [
            "pm.test('Refunded', () => "
            "pm.expect(pm.response.json().data.status).to.eql('refunded'));",
        ]),
    req("Refund again (422)", "POST", "customer-deposits/{{deposit_id}}/refund",
        tests=status_tests(422, False) + error_code_test("DEPOSIT_NOT_ACTIVE")),
])

supplier_bills = folder("Supplier Bills", (
    "Accounts payable documents. Standalone bills (services) post General "
    "Expense / AP on Premium; purchase-linked bills are auto-created by "
    "partially-paid purchases and never double-post. "
    "Permissions: bills.view / bills.manage."
), [
    req("List bills", "GET", "supplier-bills", query="page=1&per_page=10",
        tests=status_tests(200, True)),
    req("Filter unpaid bills", "GET", "supplier-bills", query="status=unpaid",
        tests=status_tests(200, True)),
    req("Create standalone bill (service)", "POST", "supplier-bills",
        body={"supplier_id": "{{supplier_id}}", "due_date": "2026-08-20",
              "items": [{"description": "Warehouse rent August", "quantity": 1,
                         "unit_cost": 150.00}]},
        description="Stores {{bill_id}}.",
        tests=status_tests(201, True) + capture("bill_id")),
    req("Get bill (with items + payments)", "GET", "supplier-bills/{{bill_id}}",
        tests=status_tests(200, True)),
    req("Create bill — no supplier (422)", "POST", "supplier-bills",
        body={"items": [{"description": "x", "quantity": 1, "unit_cost": 1}]},
        tests=status_tests(422, False) + error_code_test("SUPPLIER_REQUIRED")),
])

supplier_payments = folder("Supplier Payments", (
    "Money out to suppliers. Settles a bill (or on account); Premium posts "
    "AP / Cash-or-Bank. Permissions: bills.view / bills.manage."
), [
    req("List payments", "GET", "supplier-payments",
        tests=status_tests(200, True)),
    req("Pay part of the bill", "POST", "supplier-payments",
        body={"bill_id": "{{bill_id}}", "amount": 100, "method": "cash",
              "reference": "PM-PAY-1"},
        description="Bill paid_total 100 of 150 → status partial.",
        tests=status_tests(201, True)),
    req("Overpayment (422)", "POST", "supplier-payments",
        body={"bill_id": "{{bill_id}}", "amount": 99999},
        tests=status_tests(422, False) + error_code_test("OVERPAYMENT")),
])

reports = folder("Reports (Premium)", (
    "Advanced reports — behind plan:premium + reports.advanced. Basic-plan "
    "tenants get 403 PLAN_UPGRADE_REQUIRED here."
), [
    req("Sales report (this month)", "GET", "reports/sales",
        description="Totals, gross profit, daily curve, top products.",
        tests=status_tests(200, True)),
    req("Sales report (date range)", "GET", "reports/sales",
        query="date_from=2026-07-01&date_to=2026-07-31",
        tests=status_tests(200, True)),
    req("Inventory report", "GET", "reports/inventory",
        description="Stock value by category + low-stock items.",
        tests=status_tests(200, True)),
])

chart_of_accounts = folder("Accounting — Chart of Accounts", (
    "Premium. Permission: accounting.view. Balances are computed live "
    "from journal_entry_lines on the account's normal-balance side."
), [
    req("List accounts with balances", "GET", "accounts",
        tests=status_tests(200, True) + [
            "pm.test('Accounts have balances', () => {",
            "    const first = pm.response.json().data[0];",
            "    pm.expect(first).to.have.property('balance');",
            "    pm.expect(first).to.have.property('normal_balance');",
            "});",
            "const acc = pm.response.json().data.find(a => a.code === '6100');",
            "if (acc) pm.collectionVariables.set('rent_account_id', acc.id);",
            "const cash = pm.response.json().data.find(a => a.code === '1000');",
            "if (cash) pm.collectionVariables.set('cash_account_id', cash.id);",
        ]),
])

journal = folder("Accounting — Journal Entries", (
    "Premium. accounting.view to read, accounting.manage to create manual "
    "entries. Every operational document (sale, purchase, bill, receipt, "
    "deposit…) traces here via source_type/source_id."
), [
    req("List journal entries", "GET", "journal-entries", query="page=1&per_page=10",
        tests=status_tests(200, True) + [
            "const first = pm.response.json().data[0];",
            "if (first) pm.collectionVariables.set('journal_id', first.id);",
        ]),
    req("Filter by source type", "GET", "journal-entries", query="source_type=sale",
        tests=status_tests(200, True)),
    req("Get entry with lines", "GET", "journal-entries/{{journal_id}}",
        tests=status_tests(200, True) + [
            "pm.test('Debits equal credits', () => {",
            "    const d = pm.response.json().data;",
            "    pm.expect(d.total_debit).to.eql(d.total_credit);",
            "});",
        ]),
    req("Create manual entry", "POST", "journal-entries",
        body={"description": "Postman manual entry — office supplies",
              "lines": [{"account_id": "{{rent_account_id}}", "debit": 40},
                        {"account_id": "{{cash_account_id}}", "credit": 40}]},
        tests=status_tests(201, True)),
    req("Unbalanced entry (422)", "POST", "journal-entries",
        body={"lines": [{"account_id": "{{rent_account_id}}", "debit": 100},
                        {"account_id": "{{cash_account_id}}", "credit": 60}]},
        tests=status_tests(422, False) + error_code_test("UNBALANCED_ENTRY")),
])

periods = folder("Accounting — Financial Periods", (
    "Premium. accounting.view to list; periods.manage (Owner only) to "
    "create/close/reopen. A closed period rejects ALL postings dated "
    "inside it. NOTE: period ranges are unique — a second collection run "
    "will get PERIOD_OVERLAP on create, which is correct behavior."
), [
    req("List periods", "GET", "financial-periods",
        tests=status_tests(200, True)),
    req("Create period (April 2026)", "POST", "financial-periods",
        body={"name": "April 2026", "start_date": "2026-04-01",
              "end_date": "2026-04-30"},
        description="Stores {{period_id}}.",
        tests=status_tests(201, True) + capture("period_id")),
    req("Close period", "POST", "financial-periods/{{period_id}}/close",
        tests=status_tests(200, True) + [
            "pm.test('Closed', () => "
            "pm.expect(pm.response.json().data.status).to.eql('closed'));",
        ]),
    req("Posting into closed period (422)", "POST", "journal-entries",
        body={"entry_date": "2026-04-15", "description": "backdated",
              "lines": [{"account_id": "{{rent_account_id}}", "debit": 10},
                        {"account_id": "{{cash_account_id}}", "credit": 10}]},
        tests=status_tests(422, False) + error_code_test("PERIOD_CLOSED")),
    req("Reopen period", "POST", "financial-periods/{{period_id}}/reopen",
        tests=status_tests(200, True)),
    req("Overlapping period (422)", "POST", "financial-periods",
        body={"name": "Overlap test", "start_date": "2026-04-15",
              "end_date": "2026-05-15"},
        tests=status_tests(422, False) + error_code_test("PERIOD_OVERLAP")),
])

banks = folder("Accounting — Bank Accounts", (
    "Premium. banking.view / banking.manage. Each bank account auto-creates "
    "its own GL account (1011, 1012, …); its balance is always derived "
    "from the journal. Opening balance posts Bank / Owner's Capital."
), [
    req("List bank accounts (with live balances)", "GET", "bank-accounts",
        tests=status_tests(200, True)),
    req("Create bank account", "POST", "bank-accounts",
        body={"name": "Postman Bank USD", "bank_name": "Postman Bank",
              "account_number": "99887766", "currency": "USD",
              "opening_balance": 1000},
        description="Stores {{bank_account_id}}. Balance starts at 1000 "
                    "via an opening journal entry.",
        tests=status_tests(201, True) + capture("bank_account_id") + [
            "pm.test('Opening balance on the books', () => "
            "pm.expect(pm.response.json().data.balance).to.eql(1000));",
        ]),
    req("Update bank account", "PUT", "bank-accounts/{{bank_account_id}}",
        body={"bank_name": "Postman Bank International"},
        tests=status_tests(200, True)),
    req("Receipt into this bank", "POST", "customer-receipts",
        body={"customer_id": "{{customer_id}}", "amount": 5, "method": "bank",
              "bank_account_id": "{{bank_account_id}}"},
        description="On-account receipt hitting the bank's GL — balance "
                    "becomes 1005 on the next list call.",
        tests=status_tests(201, True)),
])

budgets = folder("Accounting — Budget vs Actual", (
    "Premium. budgets.view / budgets.manage (Owner). Budgets plan amounts "
    "per GL account for a date range; the show endpoint IS the budget-vs-"
    "actual report (budgeted, actual from the journal, variance, used %)."
), [
    req("List budgets", "GET", "budgets",
        tests=status_tests(200, True)),
    req("Create budget", "POST", "budgets",
        body={"name": "Postman Opex Budget", "start_date": "2026-07-01",
              "end_date": "2026-07-31",
              "lines": [{"account_id": "{{rent_account_id}}", "amount": 500}]},
        description="Stores {{budget_id}}.",
        tests=status_tests(201, True) + capture("budget_id")),
    req("Budget vs Actual report", "GET", "budgets/{{budget_id}}",
        tests=status_tests(200, True) + [
            "pm.test('Report has totals + lines', () => {",
            "    const d = pm.response.json().data;",
            "    pm.expect(d.totals).to.have.property('variance');",
            "    pm.expect(d.lines[0]).to.have.property('used_pct');",
            "});",
        ]),
    req("Create budget — no lines (422)", "POST", "budgets",
        body={"name": "Empty", "start_date": "2026-07-01",
              "end_date": "2026-07-31", "lines": []},
        tests=status_tests(422, False) + error_code_test("EMPTY_BUDGET")),
])

access_control = folder("Access Control (401 / 403)", (
    "Proves the three protection layers: missing token (401), missing "
    "permission (403 FORBIDDEN, e.g. Cashier writing finance documents), "
    "and plan gating (403 PLAN_UPGRADE_REQUIRED for Basic-plan tenants on "
    "premium routes — the demo tenant is premium, so that case needs a "
    "basic tenant to reproduce). NOTE: staff user management endpoints "
    "are not part of the API yet; roles/permissions are seeded per tenant."
), [
    req("No token → 401", "GET", "products", no_auth=True,
        tests=status_tests(401, False)),
    req("Garbage token → 401", "GET", "products",
        auth_token="not-a-real-token",
        tests=status_tests(401, False) + error_code_test("TOKEN_INVALID")),
    req("Cashier lists products → 200", "GET", "products",
        auth_token="{{cashier_token}}",
        description="Cashier HAS products.view.",
        tests=status_tests(200, True)),
    req("Cashier creates product → 403", "POST", "products",
        auth_token="{{cashier_token}}",
        body={"name": "Should fail"},
        tests=status_tests(403, False) + error_code_test("FORBIDDEN")),
    req("Cashier creates supplier bill → 403", "POST", "supplier-bills",
        auth_token="{{cashier_token}}", body={},
        tests=status_tests(403, False) + error_code_test("FORBIDDEN")),
    req("Cashier lists bank accounts → 403", "GET", "bank-accounts",
        auth_token="{{cashier_token}}",
        tests=status_tests(403, False) + error_code_test("FORBIDDEN")),
    req("Cashier reads customer invoices → 200", "GET", "customer-invoices",
        auth_token="{{cashier_token}}",
        description="Cashier HAS invoices.view (counter lookups).",
        tests=status_tests(200, True)),
])

cleanup = folder("Cleanup", (
    "Removes the throw-away product so repeat runs stay tidy. (Documents "
    "like sales/invoices are intentionally kept — they are history.)"
), [
    req("Delete test product", "DELETE", "products/{{product_id}}",
        description="Soft delete. Requires products.delete.",
        tests=status_tests(200, True)),
    req("Deleted product is gone (404)", "GET", "products/{{product_id}}",
        tests=status_tests(404, False)),
])

system_health = folder("System Health", (
    "Sanity check that the app responds at all — no auth involved."
), [
    {
        "name": "Backend root responds",
        "request": {
            "method": "GET",
            "header": [],
            "auth": {"type": "noauth"},
            "url": {"raw": "{{root_url}}/", "host": ["{{root_url}}"], "path": [""]},
            "description": "The CI4 welcome route. 200 = app + rewrite OK.",
        },
        "event": [test_script(["pm.test('Root responds 200', () => "
                               "pm.response.to.have.status(200));"])],
    },
])

collection = {
    "info": {
        "_postman_id": str(uuid.uuid4()),
        "name": "Mudnaan ERP API",
        "description": (
            "Complete QA collection for the Mudnaan ERP backend "
            "(CodeIgniter 4, JWT, tenant-per-database).\n\n"
            "HOW TO RUN\n"
            "1. Import this collection AND the environment file, select the "
            "'Mudnaan ERP — Local' environment.\n"
            "2. Run 'Authentication → Login (Owner)' once — every other "
            "request then authenticates automatically via {{token}}.\n"
            "3. Or run the WHOLE collection with the Collection Runner: "
            "folders are ordered so created resources feed later requests "
            "(category → product → customer/supplier → purchase/sale → "
            "documents → accounting).\n\n"
            "NOTES\n"
            "- Demo tenant is PREMIUM, so premium routes return 200. On a "
            "basic tenant they return 403 PLAN_UPGRADE_REQUIRED.\n"
            "- Financial periods have unique date ranges: the second full "
            "run gets PERIOD_OVERLAP on 'Create period' (correct behavior) "
            "— delete the April 2026 period or change its dates to re-run.\n"
            "- Staff user management endpoints are not in the API yet."
        ),
        "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
    },
    "auth": {
        "type": "bearer",
        "bearer": [{"key": "token", "value": "{{token}}", "type": "string"}],
    },
    "variable": [
        var("token"), var("cashier_token"),
        var("category_id"), var("product_id"), var("customer_id"),
        var("supplier_id"), var("purchase_id"), var("sale_id"),
        var("invoice_id"), var("deposit_id"), var("bill_id"),
        var("period_id"), var("bank_account_id"), var("budget_id"),
        var("journal_id"), var("rent_account_id"), var("cash_account_id"),
        var("stock_value_before"),
    ],
    "item": [
        authentication, dashboard, categories, products, inventory,
        customers, suppliers, purchases, sales,
        customer_invoices, customer_receipts, customer_deposits,
        supplier_bills, supplier_payments,
        reports, chart_of_accounts, journal, periods, banks, budgets,
        access_control, cleanup, system_health,
    ],
}

environment = {
    "id": str(uuid.uuid4()),
    "name": "Mudnaan ERP — Local",
    "values": [
        {"key": "base_url", "value": "http://localhost/erp-backend/api/v1",
         "enabled": True},
        {"key": "root_url", "value": "http://localhost/erp-backend",
         "enabled": True},
        {"key": "identity", "value": "yasir@demo.com", "enabled": True},
        {"key": "password", "value": "Secret123!", "enabled": True},
    ],
    "_postman_variable_scope": "environment",
}

out_dir = Path(__file__).parent
(out_dir / "MudnaanERP.postman_collection.json").write_text(
    json.dumps(collection, indent=2), encoding="utf-8")
(out_dir / "MudnaanERP-Local.postman_environment.json").write_text(
    json.dumps(environment, indent=2), encoding="utf-8")

total = sum(len(f["item"]) for f in collection["item"])
print(f"OK: {len(collection['item'])} folders, {total} requests")
