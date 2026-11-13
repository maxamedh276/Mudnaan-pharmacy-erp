-- Pharmacy foundation. Apply once, after 18_inventory_workflow.sql, to an isolated
-- development tenant first. Existing product IDs and financial history are preserved.
CREATE TABLE pharmacy_medicines (
 product_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 generic_name VARCHAR(180) NOT NULL,
 strength VARCHAR(80) NOT NULL,
 dosage_form VARCHAR(80) NOT NULL,
 manufacturer VARCHAR(180) NULL,
 prescription_required TINYINT(1) NOT NULL DEFAULT 0,
 storage_instructions VARCHAR(500) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY medicine_generic (generic_name),
 CONSTRAINT fk_medicine_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pharmacy_patients (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 branch_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(180) NOT NULL,
 phone VARCHAR(40) NULL,
 date_of_birth DATE NULL,
 allergies TEXT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY patient_branch (branch_id, name),
 CONSTRAINT fk_patient_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
 CONSTRAINT fk_patient_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pharmacy_prescriptions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 branch_id BIGINT UNSIGNED NOT NULL,
 patient_id BIGINT UNSIGNED NOT NULL,
 prescriber VARCHAR(180) NOT NULL,
 reference VARCHAR(100) NULL,
 issued_on DATE NOT NULL,
 expires_on DATE NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'recorded',
 created_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY prescription_branch (branch_id, patient_id),
 CONSTRAINT fk_prescription_patient FOREIGN KEY (patient_id) REFERENCES pharmacy_patients(id),
 CONSTRAINT fk_prescription_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
 CONSTRAINT fk_prescription_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pharmacy_prescription_items (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 prescription_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 medicine_name VARCHAR(180) NOT NULL,
 strength VARCHAR(80) NOT NULL,
 dosage_form VARCHAR(80) NOT NULL,
 unit VARCHAR(30) NULL,
 quantity DECIMAL(12,3) NOT NULL,
 directions VARCHAR(500) NOT NULL,
 CONSTRAINT fk_rx_item_prescription FOREIGN KEY (prescription_id) REFERENCES pharmacy_prescriptions(id),
 CONSTRAINT fk_rx_item_medicine FOREIGN KEY (product_id) REFERENCES pharmacy_medicines(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Explicit permissions: existing staff do not gain access to patient information.
INSERT IGNORE INTO permissions (code, description) VALUES
 ('pharmacy.view', 'View the pharmacy workspace and medicine profiles'),
 ('pharmacy.medicines.manage', 'Manage pharmacy medicine profiles'),
 ('pharmacy.patients.view', 'View pharmacy patient records'),
 ('pharmacy.patients.manage', 'Create pharmacy patient records'),
 ('pharmacy.prescriptions.view', 'View pharmacy prescription records'),
 ('pharmacy.prescriptions.manage', 'Record pharmacy prescriptions');

-- Bootstrap only roles which already have complete permission coverage.
-- Other roles must be granted pharmacy permissions explicitly by an administrator.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
 SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
 WHERE p.code LIKE 'pharmacy.%' AND r.name IN ('Owner', 'Superadmin');
