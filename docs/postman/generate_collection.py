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


def prerequest_script(lines):
    return {
        "listen": "prerequest",
        "script": {"type": "text/javascript", "exec": lines},
    }


def req(name, method, path, *, body=None, description="", tests=None,
        auth_token="{{token}}", no_auth=False, query=None, prerequest=None):
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
    events = []
    if prerequest:
        events.append(prerequest_script(prerequest))
    if tests:
        events.append(test_script(tests))
    if events:
        item["event"] = events
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
        description="Requires a valid token. Returns user + plan + permissions. "
                    "Stores {{own_user_id}} for the self-deactivate guard test.",
        tests=status_tests(200, True) + [
            "pm.collectionVariables.set('own_user_id', "
            "pm.response.json().data.user.id);",
        ]),
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
            "pm.collectionVariables.set('book_value_before', s.book_value);",
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
    req("Book value moves with stock value", "GET", "stock",
        query="per_page=1",
        description="The invariant: an adjustment moves stock_value and "
                    "book_value by the SAME amount. We compare the deltas "
                    "since the overview snapshot above (robust to any "
                    "historical rounding), proving inventory and the ledger "
                    "stay locked together.",
        tests=status_tests(200, True) + [
            "const s = pm.response.json().data.summary;",
            "const dStock = s.stock_value - "
            "Number(pm.collectionVariables.get('stock_value_before'));",
            "const dBook = s.book_value - "
            "Number(pm.collectionVariables.get('book_value_before'));",
            "pm.test('stock and book moved together', () => {",
            "    if (s.book_value !== null)",
            "        pm.expect(Math.abs(dStock - dBook)).to.be.below(0.01);",
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
    req("Customer statement (running balance)", "GET",
        "customers/{{customer_id}}/statement",
        description="Full transaction ledger (sales, invoices, receipts, "
                    "deposits) with a running balance + summary. Supports "
                    "?date_from=&date_to=&type=&q=.",
        tests=status_tests(200, True) + [
            "pm.test('Statement has entries + summary', () => {",
            "    const d = pm.response.json().data;",
            "    pm.expect(d).to.have.property('summary');",
            "    pm.expect(d).to.have.property('entries');",
            "    pm.expect(d.summary).to.have.property('closing_balance');",
            "});",
        ]),
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
    "Goods received — the inverse of a sale: ADDS stock (+qty ledger "
    "rows) and re-averages the product's cost (weighted average), so "
    "stock value and the Inventory account rise together. A purchase "
    "that isn't fully paid auto-creates a linked supplier bill and posts "
    "Inventory / Cash + Accounts Payable. Purchases are never edited or "
    "deleted — only VOIDED, and only when nothing depends on them. "
    "Permissions: purchases.view / purchases.create."
), [
    req("List purchases", "GET", "purchases", query="page=1&per_page=10",
        tests=status_tests(200, True)),
    # ---- inventory effect + weighted average ----
    req("Stock + cost BEFORE purchase", "GET", "products/{{product_id}}",
        description="Stores {{qty_before_pur}} and {{cost_before_pur}}.",
        tests=status_tests(200, True) + [
            "const d = pm.response.json().data;",
            "pm.collectionVariables.set('qty_before_pur', d.quantity);",
            "pm.collectionVariables.set('cost_before_pur', d.cost_price);",
        ]),
    req("Create purchase (partially paid → auto-bill)", "POST", "purchases",
        body={"supplier_id": "{{supplier_id}}", "reference_no": "PO-PM-001",
              "paid_amount": 20,
              "items": [{"product_id": "{{product_id}}", "quantity": 50,
                         "unit_cost": 0.50}]},
        description="50 units at 0.50 = 25.00, pays 20.00 → a supplier bill "
                    "for the outstanding 5.00 appears in Supplier Bills. "
                    "Stores {{purchase_id}}.",
        tests=status_tests(201, True) + capture("purchase_id")),
    req("Stock AFTER purchase = before + 50", "GET",
        "products/{{product_id}}",
        description="Proves the purchase added stock and re-averaged cost "
                    "(unit cost 0.50 pulls the average below the old cost).",
        tests=status_tests(200, True) + [
            "const d = pm.response.json().data;",
            "const before = "
            "Number(pm.collectionVariables.get('qty_before_pur'));",
            "pm.test('Stock increased by 50', () => "
            "pm.expect(d.quantity - before).to.eql(50));",
            "pm.test('Weighted-average cost moved toward unit cost', () => "
            "pm.expect(d.cost_price).to.be.at.most("
            "Number(pm.collectionVariables.get('cost_before_pur'))));",
        ]),
    req("Get purchase", "GET", "purchases/{{purchase_id}}",
        tests=status_tests(200, True)),
    req("Create purchase — no items (422)", "POST", "purchases",
        body={"supplier_id": "{{supplier_id}}", "items": []},
        tests=status_tests(422, False) + error_code_test("EMPTY_PURCHASE")),
    # ---- void (reversal) ----
    req("Create a voidable purchase", "POST", "purchases",
        body={"supplier_id": "{{supplier_id}}", "paid_amount": 5.5,
              "items": [{"product_id": "{{product_id}}", "quantity": 10,
                         "unit_cost": 0.55}]},
        description="Fully paid, 10 units — safe to void. "
                    "Stores {{void_purchase_id}}.",
        tests=status_tests(201, True) + capture("void_purchase_id")),
    req("Stock before void", "GET", "products/{{product_id}}",
        tests=status_tests(200, True) + [
            "pm.collectionVariables.set('qty_pre_void', "
            "pm.response.json().data.quantity);",
        ]),
    req("Void the purchase → stock reversed", "POST",
        "purchases/{{void_purchase_id}}/void",
        body={"reason": "wrong order"},
        description="Reverses the stock and the accounting; the purchase "
                    "is marked cancelled (never deleted).",
        tests=status_tests(200, True) + [
            "pm.test('Cancelled', () => "
            "pm.expect(pm.response.json().data.status).to.eql('cancelled'));",
        ]),
    req("Stock returned to pre-void − 10", "GET", "products/{{product_id}}",
        tests=status_tests(200, True) + [
            "pm.test('10 units removed by the void', () => "
            "pm.expect(Number(pm.collectionVariables.get('qty_pre_void')) - "
            "pm.response.json().data.quantity).to.eql(10));",
        ]),
    req("Double void → 422", "POST", "purchases/{{void_purchase_id}}/void",
        body={},
        tests=status_tests(422, False) + error_code_test("ALREADY_CANCELLED")),
])

sales = folder("Sales / POS", (
    "The POS flow, covering the real-life scenarios. Totals are recomputed "
    "server-side; a proper uuid makes checkout idempotent (safe against "
    "double-taps / offline retries); every sale decrements stock through "
    "the ledger and posts COGS/Inventory; credit sales require a customer "
    "and auto-create an invoice. Permissions: sales.view / sales.create."
), [
    req("List sales", "GET", "sales", query="page=1&per_page=10",
        tests=status_tests(200, True)),
    req("Filter sales by status", "GET", "sales", query="status=partial",
        tests=status_tests(200, True)),
    # ---- inventory effect ----
    req("Stock BEFORE sale (snapshot)", "GET", "products/{{product_id}}",
        description="Stores {{qty_before}} so the next request can prove "
                    "the sale decremented stock by exactly the quantity sold.",
        tests=status_tests(200, True) + [
            "pm.collectionVariables.set('qty_before', "
            "pm.response.json().data.quantity);",
        ]),
    req("Create cash sale (decrements stock)", "POST", "sales",
        body={"items": [{"product_id": "{{product_id}}", "quantity": 2}],
              "payments": [{"method": "cash", "amount": 2.20}]},
        description="2 units fully paid → completed. Stores {{sale_id}}.",
        tests=status_tests(201, True) + capture("sale_id") + [
            "pm.test('Sale completed', () => "
            "pm.expect(pm.response.json().data.status).to.eql('completed'));",
        ]),
    req("Stock AFTER sale = before − 2", "GET", "products/{{product_id}}",
        description="Proves inventory moved: qty dropped by exactly the "
                    "quantity sold.",
        tests=status_tests(200, True) + [
            "const now = pm.response.json().data.quantity;",
            "const before = Number(pm.collectionVariables.get('qty_before'));",
            "pm.test('Stock decreased by 2', () => "
            "pm.expect(before - now).to.eql(2));",
        ]),
    req("Sale movement on the ledger", "GET", "stock/movements",
        query="product_id={{product_id}}",
        description="A -2 'sale' row now heads the movement ledger.",
        tests=status_tests(200, True) + [
            "const m = pm.response.json().data[0];",
            "pm.test('Latest movement is the sale (-2)', () => {",
            "    pm.expect(m.reason).to.eql('sale');",
            "    pm.expect(m.qty_change).to.eql(-2);",
            "});",
        ]),
    # ---- payment variety ----
    req("Sale with line discount", "POST", "sales",
        body={"items": [{"product_id": "{{product_id}}", "quantity": 2,
                         "discount": 0.30}],
              "payments": [{"method": "cash", "amount": 1.90}]},
        description="2 × 1.10 − 0.30 discount = 1.90 subtotal.",
        tests=status_tests(201, True) + [
            "pm.test('Discount applied', () => "
            "pm.expect(pm.response.json().data.subtotal).to.eql(1.9));",
        ]),
    req("Split payment (cash + card)", "POST", "sales",
        body={"items": [{"product_id": "{{product_id}}", "quantity": 1}],
              "payments": [{"method": "cash", "amount": 0.60},
                           {"method": "card", "amount": 0.50}]},
        description="Two tenders for one sale — both payment rows saved.",
        tests=status_tests(201, True) + [
            "pm.test('Two payments recorded', () => "
            "pm.expect(pm.response.json().data.payments).to.have.lengthOf(2));",
        ]),
    req("Create credit sale (partial → auto-invoice)", "POST", "sales",
        body={"customer_id": "{{customer_id}}",
              "items": [{"product_id": "{{product_id}}", "quantity": 10}],
              "payments": [{"method": "cash", "amount": 5}]},
        description="Pays 5.00 of 11.00 → status partial, customer balance "
                    "drops, a customer invoice is auto-created.",
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
    # ---- idempotency (double-tap / retry) ----
    req("Idempotent checkout — first", "POST", "sales",
        prerequest=["pm.collectionVariables.set('sale_uuid', "
                    "pm.variables.replaceIn('{{$guid}}'));"],
        body={"uuid": "{{sale_uuid}}",
              "items": [{"product_id": "{{product_id}}", "quantity": 1}],
              "payments": [{"method": "cash", "amount": 1.10}]},
        description="Fresh uuid each run. Stores {{idem_invoice}} + the "
                    "post-sale stock in {{qty_idem}}.",
        tests=status_tests(201, True) + [
            "pm.collectionVariables.set('idem_invoice', "
            "pm.response.json().data.invoice_no);",
        ]),
    req("Stock snapshot after first", "GET", "products/{{product_id}}",
        tests=status_tests(200, True) + [
            "pm.collectionVariables.set('qty_idem', "
            "pm.response.json().data.quantity);",
        ]),
    req("Idempotent checkout — retry (same uuid)", "POST", "sales",
        body={"uuid": "{{sale_uuid}}",
              "items": [{"product_id": "{{product_id}}", "quantity": 1}],
              "payments": [{"method": "cash", "amount": 1.10}]},
        description="Same uuid → returns the SAME sale, does NOT sell again.",
        tests=status_tests(201, True) + [
            "pm.test('Same invoice returned', () => "
            "pm.expect(pm.response.json().data.invoice_no).to.eql("
            "pm.collectionVariables.get('idem_invoice')));",
        ]),
    req("Stock unchanged after retry", "GET", "products/{{product_id}}",
        description="The idempotent retry must NOT have deducted stock again.",
        tests=status_tests(200, True) + [
            "pm.test('No double deduction', () => "
            "pm.expect(pm.response.json().data.quantity).to.eql("
            "Number(pm.collectionVariables.get('qty_idem'))));",
        ]),
    req("Malformed uuid is tolerated (201, not 500)", "POST", "sales",
        body={"uuid": "not-a-valid-uuid-way-too-long-1234567890-abcdef",
              "items": [{"product_id": "{{product_id}}", "quantity": 1}],
              "payments": [{"method": "cash", "amount": 1.10}]},
        description="A bad uuid is ignored (server generates one) — never a "
                    "500 DatabaseException.",
        tests=status_tests(201, True)),
    # ---- guards ----
    req("Oversell (422)", "POST", "sales",
        body={"items": [{"product_id": "{{product_id}}", "quantity": 99999}],
              "payments": [{"method": "cash", "amount": 1}]},
        description="Never sells more than on hand; no partial commit.",
        tests=status_tests(422, False) + error_code_test("INSUFFICIENT_STOCK")),
    req("Empty sale (422)", "POST", "sales",
        body={"items": [], "payments": []},
        tests=status_tests(422, False) + error_code_test("EMPTY_SALE")),
    req("Zero quantity (422)", "POST", "sales",
        body={"items": [{"product_id": "{{product_id}}", "quantity": 0}],
              "payments": []},
        tests=status_tests(422, False) + error_code_test("INVALID_SALE_ITEM")),
    req("Nonexistent product (422)", "POST", "sales",
        body={"items": [{"product_id": 999999, "quantity": 1}],
              "payments": [{"method": "cash", "amount": 1}]},
        tests=status_tests(422, False) + error_code_test("PRODUCT_NOT_FOUND")),
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
        description="Bill paid_total 100 of 150 → status partial. "
                    "Stores {{supplier_payment_id}}.",
        tests=status_tests(201, True) + capture("supplier_payment_id")),
    req("Overpayment (422)", "POST", "supplier-payments",
        body={"bill_id": "{{bill_id}}", "amount": 99999},
        tests=status_tests(422, False) + error_code_test("OVERPAYMENT")),
    req("Void the supplier payment → restores the bill", "POST",
        "supplier-payments/{{supplier_payment_id}}/void",
        body={"reason": "entered twice"},
        description="Reverses the payment's accounting and puts the bill's "
                    "outstanding balance back.",
        tests=status_tests(200, True) + [
            "pm.test('Payment cancelled', () => "
            "pm.expect(pm.response.json().data.status).to.eql('cancelled'));",
        ]),
    # ---- a purchase can't be voided once its bill is paid ----
    req("Purchase (partial) for the HAS_PAYMENTS guard", "POST", "purchases",
        body={"supplier_id": "{{supplier_id}}", "reference_no": "PO-HASPAY",
              "paid_amount": 0,
              "items": [{"product_id": "{{product_id}}", "quantity": 4,
                         "unit_cost": 0.50}]},
        description="Unpaid → auto-creates a bill. Stores {{haspay_purchase_id}}.",
        tests=status_tests(201, True) + capture("haspay_purchase_id")),
    req("Find that purchase's auto-bill", "GET", "supplier-bills",
        query="supplier_id={{supplier_id}}",
        description="Captures the bill whose purchase_id matches, into "
                    "{{haspay_bill_id}}.",
        tests=status_tests(200, True) + [
            "const target = "
            "Number(pm.collectionVariables.get('haspay_purchase_id'));",
            "const bill = pm.response.json().data.find("
            "b => b.purchase_id === target);",
            "pm.test('Auto-bill exists for the purchase', () => "
            "pm.expect(bill, 'linked bill').to.not.be.undefined);",
            "if (bill) pm.collectionVariables.set('haspay_bill_id', bill.id);",
        ]),
    req("Pay that bill", "POST", "supplier-payments",
        body={"bill_id": "{{haspay_bill_id}}", "amount": 1, "method": "cash"},
        tests=status_tests(201, True)),
    req("Void the purchase → 422 (payment linked)", "POST",
        "purchases/{{haspay_purchase_id}}/void", body={},
        description="A supplier payment now references this purchase's bill, "
                    "so the purchase can't be reversed until it's cancelled.",
        tests=status_tests(422, False) + error_code_test("HAS_PAYMENTS")),
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

roles = folder("Roles & Permissions", (
    "Role and permission management (Owner-level users.manage). A role is "
    "a named bundle of permission codes; editing a role's permissions "
    "takes effect on the NEXT request for every user who has it. System "
    "roles (Owner/Manager/Cashier) can be re-permissioned but not deleted."
), [
    req("List roles (+ permissions + user count)", "GET", "roles",
        description="Stores {{manager_role_id}} and {{cashier_role_id}} for "
                    "the Users folder.",
        tests=status_tests(200, True) + [
            "const roles = pm.response.json().data;",
            "pm.test('Roles carry permission codes', () => {",
            "    pm.expect(roles[0]).to.have.property('permissions');",
            "    pm.expect(roles[0]).to.have.property('user_count');",
            "});",
            "const mgr = roles.find(r => r.name === 'Manager');",
            "const csh = roles.find(r => r.name === 'Cashier');",
            "const own = roles.find(r => r.name === 'Owner');",
            "if (mgr) pm.collectionVariables.set('manager_role_id', mgr.id);",
            "if (csh) pm.collectionVariables.set('cashier_role_id', csh.id);",
            "if (own) pm.collectionVariables.set('owner_role_id', own.id);",
        ]),
    req("Permission catalog", "GET", "permissions",
        description="Every permission code + description, for role forms.",
        tests=status_tests(200, True)),
    req("Get one role", "GET", "roles/{{manager_role_id}}",
        tests=status_tests(200, True)),
    req("Create custom role (view-only)", "POST", "roles",
        prerequest=["pm.collectionVariables.set('role_name', "
                    "'Auditor ' + Date.now());"],
        body={"name": "{{role_name}}",
              "permissions": ["reports.basic", "reports.advanced",
                              "accounting.view", "stock.view"]},
        description="Unique name each run. Stores {{role_id}}.",
        tests=status_tests(201, True) + capture("role_id") + [
            "pm.test('Got 4 permissions', () => "
            "pm.expect(pm.response.json().data.permissions).to.have.lengthOf(4));",
        ]),
    req("Update role permissions", "PUT", "roles/{{role_id}}",
        body={"permissions": ["reports.basic", "accounting.view",
                              "sales.view", "sales.create"]},
        tests=status_tests(200, True) + [
            "pm.test('Now 4 permissions', () => "
            "pm.expect(pm.response.json().data.permissions).to.have.lengthOf(4));",
        ]),
    req("Duplicate role name (422)", "POST", "roles",
        body={"name": "{{role_name}}", "permissions": []},
        tests=status_tests(422, False) + error_code_test("ROLE_NAME_TAKEN")),
    req("Delete system role → 422", "DELETE", "roles/{{owner_role_id}}",
        description="Built-in roles are protected.",
        tests=status_tests(422, False) + error_code_test("SYSTEM_ROLE")),
    req("Delete custom (unused) role → 200", "DELETE", "roles/{{role_id}}",
        tests=status_tests(200, True)),
])

users = folder("Users", (
    "Staff account management (Owner-level users.manage). Creates a real "
    "manager account and logs in as it to drive the Access Control matrix. "
    "Run the Roles folder first (it stores the role ids)."
), [
    req("List users (Owner)", "GET", "users",
        tests=status_tests(200, True)),
    req("Create manager user", "POST", "users",
        prerequest=["pm.collectionVariables.set('mgr_email', "
                    "'pm_manager_' + Date.now() + '@demo.test');"],
        body={"name": "Postman Manager", "email": "{{mgr_email}}",
              "password": "Manager123!", "role_id": "{{manager_role_id}}"},
        description="Unique email each run. Stores {{staff_user_id}}.",
        tests=status_tests(201, True) + capture("staff_user_id") + [
            "pm.test('Created as Manager, active', () => {",
            "    const u = pm.response.json().data;",
            "    pm.expect(u.role).to.eql('Manager');",
            "    pm.expect(u.is_active).to.be.true;",
            "});",
        ]),
    req("New manager can log in → manager_token", "POST", "auth/login",
        no_auth=True,
        body={"identity": "{{mgr_email}}", "password": "Manager123!"},
        description="Stores {{manager_token}} used by the Access Control "
                    "matrix below.",
        tests=status_tests(200, True) + [
            "pm.collectionVariables.set('manager_token', "
            "pm.response.json().data.token);",
        ]),
    req("Get user", "GET", "users/{{staff_user_id}}",
        tests=status_tests(200, True)),
    req("Update user (rename)", "PUT", "users/{{staff_user_id}}",
        body={"name": "Postman Manager (edited)"},
        tests=status_tests(200, True)),
    req("Duplicate email (422)", "POST", "users",
        body={"name": "Dup", "email": "{{mgr_email}}",
              "password": "Manager123!", "role_id": "{{manager_role_id}}"},
        tests=status_tests(422, False) + error_code_test("EMAIL_TAKEN")),
    req("Weak password (422)", "POST", "users",
        body={"name": "Weak", "email": "weak_{{$timestamp}}@demo.test",
              "password": "12", "role_id": "{{manager_role_id}}"},
        tests=status_tests(422, False) + error_code_test("WEAK_PASSWORD")),
    req("No email or phone (422)", "POST", "users",
        body={"name": "NoId", "password": "Manager123!",
              "role_id": "{{manager_role_id}}"},
        tests=status_tests(422, False) + error_code_test("IDENTITY_REQUIRED")),
    req("Invalid role (422)", "POST", "users",
        body={"name": "BadRole", "email": "badrole_{{$timestamp}}@demo.test",
              "password": "Manager123!", "role_id": 999999},
        tests=status_tests(422, False) + error_code_test("ROLE_NOT_FOUND")),
    req("Cannot deactivate self (422)", "POST",
        "users/{{own_user_id}}/active",
        body={"is_active": False},
        description="The acting user can't lock themselves out.",
        tests=status_tests(422, False) +
              error_code_test("CANNOT_DEACTIVATE_SELF")),
    req("Cashier cannot list users → 403", "GET", "users",
        auth_token="{{cashier_token}}",
        tests=status_tests(403, False) + error_code_test("FORBIDDEN")),
    req("Cashier cannot create user → 403", "POST", "users",
        auth_token="{{cashier_token}}",
        body={"name": "X", "email": "x@demo.test", "password": "Manager123!",
              "role_id": "{{cashier_role_id}}"},
        tests=status_tests(403, False) + error_code_test("FORBIDDEN")),
])

def matrix(name, method, path, owner, manager, cashier, body=None):
    """Three requests (owner/manager/cashier) asserting the expected code."""
    def one(label, token, expected):
        ok = 200 <= expected < 300
        return req(f"{name} · {label} → {expected}", method, path,
                   auth_token=token, body=body,
                   tests=[f"pm.test('{label} gets {expected}', () => "
                          f"pm.response.to.have.status({expected}));"] +
                         ([] if ok else
                          ["pm.test('403 is FORBIDDEN', () => "
                           "pm.expect(pm.response.json().error.code)"
                           ".to.eql('FORBIDDEN'));"] if expected == 403 else []))
    return [one("Owner", "{{token}}", owner),
            one("Manager", "{{manager_token}}", manager),
            one("Cashier", "{{cashier_token}}", cashier)]

access_control = folder("Access Control (RBAC matrix)", (
    "The heart of RBAC: the SAME endpoint hit by Owner, Manager and "
    "Cashier, asserting exactly who reaches the handler (2xx / 422 = "
    "allowed) and who is blocked (403 FORBIDDEN). Reflects the seeded "
    "grants — Cashier = POS essentials, Manager = operational finance "
    "(no accounting.manage / users / settings / period close), Owner = "
    "everything. Run the Users folder first for {{manager_token}}. "
    "(POST bodies are intentionally empty: an allowed role reaches "
    "validation — 422 — while a blocked role is stopped at 403.)"
), [
    req("No token → 401", "GET", "products", no_auth=True,
        tests=status_tests(401, False)),
    req("Garbage token → 401", "GET", "products",
        auth_token="not-a-real-token",
        tests=status_tests(401, False) + error_code_test("TOKEN_INVALID")),
    *matrix("View products", "GET", "products", 200, 200, 200),
    *matrix("Create product", "POST", "products", 422, 422, 403, body={}),
    *matrix("View stock", "GET", "stock", 200, 200, 200),
    *matrix("Read customer invoices", "GET", "customer-invoices",
            200, 200, 200),
    *matrix("Create supplier bill", "POST", "supplier-bills",
            422, 422, 403, body={}),
    *matrix("View bank accounts", "GET", "bank-accounts", 200, 200, 403),
    *matrix("View chart of accounts", "GET", "accounts", 200, 200, 403),
    *matrix("Post manual journal (accounting.manage)", "POST",
            "journal-entries", 422, 403, 403, body={}),
    *matrix("Advanced sales report", "GET", "reports/sales", 200, 200, 403),
    *matrix("List users (users.manage)", "GET", "users", 200, 403, 403),
    *matrix("Audit log (settings.manage)", "GET", "audit-log",
            200, 403, 403),
    *matrix("Create period (periods.manage)", "POST", "financial-periods",
            422, 403, 403, body={}),
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
        var("stock_value_before"), var("book_value_before"),
        var("own_user_id"), var("manager_role_id"), var("cashier_role_id"),
        var("owner_role_id"), var("role_id"), var("role_name"),
        var("staff_user_id"), var("mgr_email"), var("manager_token"),
        var("qty_before"), var("sale_uuid"), var("idem_invoice"),
        var("qty_idem"), var("qty_before_pur"), var("cost_before_pur"),
        var("void_purchase_id"), var("qty_pre_void"),
        var("supplier_payment_id"), var("haspay_purchase_id"),
        var("haspay_bill_id"),
    ],
    "item": [
        authentication, dashboard, categories, products, inventory,
        customers, suppliers, purchases, sales,
        customer_invoices, customer_receipts, customer_deposits,
        supplier_bills, supplier_payments,
        reports, chart_of_accounts, journal, periods, banks, budgets,
        roles, users, access_control, cleanup, system_health,
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
