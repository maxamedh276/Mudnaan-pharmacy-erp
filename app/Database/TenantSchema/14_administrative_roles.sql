-- Preserve existing Owner accounts as Superadmin, retaining their role ID.
INSERT IGNORE INTO permissions (code,description)
VALUES ('settings.operational','Superadmin only: operational configuration');

UPDATE roles legacy LEFT JOIN roles existing ON existing.name='Superadmin'
SET legacy.name='Superadmin',legacy.is_system=1
WHERE legacy.name='Owner' AND existing.id IS NULL;

INSERT IGNORE INTO roles (name,is_system) VALUES ('Superadmin',1),('Admin',1);
UPDATE roles SET is_system=1 WHERE name IN ('Superadmin','Admin');
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.name='Superadmin' OR (r.name='Admin' AND p.code<>'settings.operational');
DELETE rp FROM role_permissions rp
JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id
WHERE r.name='Admin' AND p.code='settings.operational';
