-- ##################################################################
-- #  DEMO DATA — development/demo tenants ONLY. Never run on a     #
-- #  real client database.                                         #
-- #  Creates the demo owner login plus a small catalog with        #
-- #  opening stock so the app has something to show on day one.    #
-- ##################################################################

-- Demo owner login: yasir@demo.com / Secret123!
INSERT INTO users (branch_id, role_id, name, email, phone, password_hash)
  SELECT 1, r.id, 'Yasir Ahmed', 'yasir@demo.com', '+252612345678',
         '$2y$10$d8wriQMYCs/5NPe0CYbeGu69Hq9qGgPEfpeMGBRMbk5Ob.nMA6FfC'
  FROM roles r WHERE r.name = 'Owner';

-- Demo cashier login: cashier@demo.com / Secret123!
INSERT INTO users (branch_id, role_id, name, email, phone, password_hash)
  SELECT 1, r.id, 'Ahmed Hassan', 'cashier@demo.com', '+252612345679',
         '$2y$10$d8wriQMYCs/5NPe0CYbeGu69Hq9qGgPEfpeMGBRMbk5Ob.nMA6FfC'
  FROM roles r WHERE r.name = 'Cashier';

INSERT INTO categories (name) VALUES
  ('Drinks'), ('Electronics'), ('Personal Care');

INSERT INTO suppliers (name, phone, email) VALUES
  ('Global Beverages', '+252 612 345678', 'globalbeverages@example.com'),
  ('Global Electronics', '+252 613 456789', 'globalelectronics@example.com');

INSERT INTO products (category_id, supplier_id, sku, barcode, name, brand, unit, cost_price, sell_price, reorder_level) VALUES
  ((SELECT id FROM categories WHERE name='Drinks'),        (SELECT id FROM suppliers WHERE name='Global Beverages'),   'CC-330',  '5449000012345', 'Coca Cola 330ml',        'Coca Cola', 'pcs', 0.40,   0.70,  20),
  ((SELECT id FROM categories WHERE name='Drinks'),        (SELECT id FROM suppliers WHERE name='Global Beverages'),   'PP-330',  '5449000019765', 'Pepsi 330ml',            'Pepsi',     'pcs', 0.35,   0.60,  20),
  ((SELECT id FROM categories WHERE name='Electronics'),   (SELECT id FROM suppliers WHERE name='Global Electronics'), 'IP15-128','3548901101475', 'iPhone 15 128GB',        'Apple',     'pcs', 620.00, 750.00, 5),
  ((SELECT id FROM categories WHERE name='Electronics'),   (SELECT id FROM suppliers WHERE name='Global Electronics'), 'SG-S24',  '8806095302811', 'Samsung Galaxy S24',     'Samsung',   'pcs', 480.00, 600.00, 5),
  ((SELECT id FROM categories WHERE name='Electronics'),   (SELECT id FROM suppliers WHERE name='Global Electronics'), 'USBC-1M', '6941812701234', 'USB-C Cable',            'Generic',   'pcs', 2.00,   5.00,  10),
  ((SELECT id FROM categories WHERE name='Electronics'),   (SELECT id FROM suppliers WHERE name='Global Electronics'), 'APP-2',   '1902530028679', 'AirPods Pro',            'Apple',     'pcs', 140.00, 180.00, 5),
  ((SELECT id FROM categories WHERE name='Personal Care'), (SELECT id FROM suppliers WHERE name='Global Beverages'),   'NSC-200', '4005808179805', 'Nivea Soft Cream 200ml', 'Nivea',     'pcs', 1.40,   2.20,  10);

-- Opening stock: one +qty ledger row per product, then cache the total.
INSERT INTO stock_movements (branch_id, product_id, qty_change, reason, ref_type, user_id)
  SELECT 1, p.id,
         CASE p.sku WHEN 'CC-330' THEN 150 WHEN 'PP-330' THEN 87
                    WHEN 'IP15-128' THEN 18 WHEN 'SG-S24' THEN 30
                    WHEN 'USBC-1M' THEN 65 WHEN 'APP-2' THEN 28
                    WHEN 'NSC-200' THEN 34 END,
         'opening', 'opening', (SELECT id FROM users WHERE email='yasir@demo.com')
  FROM products p;

INSERT INTO stock_levels (branch_id, product_id, quantity)
  SELECT branch_id, product_id, SUM(qty_change)
  FROM stock_movements GROUP BY branch_id, product_id;
