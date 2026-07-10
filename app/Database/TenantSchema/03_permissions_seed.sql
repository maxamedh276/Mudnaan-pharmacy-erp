-- ##################################################################
-- #  PERMISSIONS SEED — run on every new tenant AFTER 01 + 02.     #
-- #  Fills the permissions catalog and attaches them to the three  #
-- #  built-in roles (Owner, Manager, Cashier).                     #
-- ##################################################################

INSERT INTO permissions (code, description) VALUES
  ('sales.view',        'View sales and receipts'),
  ('sales.create',      'Create sales at the POS'),
  ('sales.refund',      'Refund a sale'),
  ('products.view',     'View products and prices'),
  ('products.create',   'Add new products'),
  ('products.edit',     'Edit existing products'),
  ('products.delete',   'Delete products'),
  ('stock.view',        'View stock levels and movements'),
  ('stock.adjust',      'Make manual stock adjustments'),
  ('purchases.view',    'View purchases'),
  ('purchases.create',  'Record purchases / goods received'),
  ('customers.view',    'View customers'),
  ('customers.manage',  'Add and edit customers'),
  ('suppliers.view',    'View suppliers'),
  ('suppliers.manage',  'Add and edit suppliers'),
  ('reports.basic',     'View basic dashboard and reports'),
  ('reports.advanced',  'View advanced financial reports (Premium)'),
  ('accounting.view',   'View chart of accounts and journals (Premium)'),
  ('accounting.manage', 'Create manual journal entries (Premium)'),
  ('users.manage',      'Manage staff users and roles'),
  ('settings.manage',   'Change business settings');

-- Owner: everything.
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p
  WHERE r.name = 'Owner';

-- Manager: everything except user and settings management.
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p
  WHERE r.name = 'Manager'
    AND p.code NOT IN ('users.manage', 'settings.manage', 'accounting.manage');

-- Cashier: the POS essentials only.
INSERT INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p
  WHERE r.name = 'Cashier'
    AND p.code IN ('sales.view', 'sales.create', 'products.view',
                   'stock.view', 'customers.view', 'reports.basic');
