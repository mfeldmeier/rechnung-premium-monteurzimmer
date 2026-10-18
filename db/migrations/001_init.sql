-- Tabellen liegen in der bestehenden WordPress-Datenbank, abgegrenzt ueber {{prefix}}.
-- {{prefix}} wird von db/migrate.php aus DB_PREFIX ersetzt.
-- JSON-Spalten bewusst als LONGTEXT: MariaDB haengt an den JSON-Typ eine
-- json_valid-CHECK-Constraint, MySQL nicht - LONGTEXT verhaelt sich auf beiden gleich.

CREATE TABLE IF NOT EXISTS `{{prefix}}users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(190) NOT NULL,
  `name` VARCHAR(190) NOT NULL DEFAULT '',
  `password_hash` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Drosselung bewusst pro E-Mail, nicht pro IP: hinter einem Proxy teilen sich
-- alle Nutzer dieselbe IP, eine IP-Sperre wuerde alle gleichzeitig aussperren.
CREATE TABLE IF NOT EXISTS `{{prefix}}login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(190) NOT NULL,
  `succeeded` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_email_time` (`email`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}settings` (
  `setting_key` VARCHAR(64) NOT NULL,
  `setting_value` TEXT NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}apartments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `beds_default` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_apartments_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}customers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company` VARCHAR(190) NOT NULL DEFAULT '',
  `contact_name` VARCHAR(190) NOT NULL DEFAULT '',
  `street` VARCHAR(190) NOT NULL DEFAULT '',
  `postcode` VARCHAR(20) NOT NULL DEFAULT '',
  `city` VARCHAR(120) NOT NULL DEFAULT '',
  `country_code` CHAR(2) NOT NULL DEFAULT 'DE',
  `email` VARCHAR(190) NOT NULL DEFAULT '',
  `phone` VARCHAR(60) NOT NULL DEFAULT '',
  `vat_id` VARCHAR(30) NOT NULL DEFAULT '',
  `notes` TEXT NULL DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customers_company` (`company`),
  KEY `idx_customers_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Klick-Bausteine fuer den Rechnungseditor.
CREATE TABLE IF NOT EXISTS `{{prefix}}item_presets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `label` VARCHAR(100) NOT NULL,
  `kind` ENUM('accommodation','cleaning','deposit','custom') NOT NULL DEFAULT 'custom',
  `description_template` VARCHAR(255) NOT NULL DEFAULT '',
  `default_unit_price_gross_cents` INT NULL DEFAULT NULL,
  `price_is_manual` TINYINT(1) NOT NULL DEFAULT 0,
  `tax_rate_bp` SMALLINT UNSIGNED NOT NULL DEFAULT 1900,
  `tax_category` CHAR(1) NOT NULL DEFAULT 'S',
  `tax_exemption_reason` VARCHAR(255) NULL DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ein Zaehler je Jahr. Die Nummer wird erst beim Finalisieren vergeben,
-- damit verworfene Entwuerfe keine Luecken reissen.
CREATE TABLE IF NOT EXISTS `{{prefix}}number_counters` (
  `number_year` SMALLINT UNSIGNED NOT NULL,
  `last_seq` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`number_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}invoices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status` ENUM('draft','final','cancelled') NOT NULL DEFAULT 'draft',
  `doc_type` ENUM('invoice','credit_note') NOT NULL DEFAULT 'invoice',
  `number` VARCHAR(30) NULL DEFAULT NULL,
  `number_seq` INT UNSIGNED NULL DEFAULT NULL,
  `number_year` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `invoice_date` DATE NOT NULL,
  `service_from` DATE NULL DEFAULT NULL,
  `service_to` DATE NULL DEFAULT NULL,
  `subject` VARCHAR(190) NOT NULL DEFAULT '',
  `customer_id` INT UNSIGNED NULL DEFAULT NULL,
  -- Eingefrorene Kopien, damit eine spaetere Stammdatenaenderung alte
  -- Rechnungen nicht rueckwirkend veraendert.
  `customer_snapshot` LONGTEXT NOT NULL,
  `seller_snapshot` LONGTEXT NOT NULL,
  `payment_terms_text` TEXT NOT NULL,
  `closing_text` TEXT NULL DEFAULT NULL,
  `net_total_cents` INT NOT NULL DEFAULT 0,
  `tax_total_cents` INT NOT NULL DEFAULT 0,
  `gross_total_cents` INT NOT NULL DEFAULT 0,
  `tax_breakdown` LONGTEXT NOT NULL,
  `cancels_invoice_id` INT UNSIGNED NULL DEFAULT NULL,
  `cancel_reason` VARCHAR(255) NULL DEFAULT NULL,
  `pdf_filename` VARCHAR(255) NULL DEFAULT NULL,
  `finalized_at` DATETIME NULL DEFAULT NULL,
  `created_by` INT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoices_number` (`number`),
  KEY `idx_invoices_status` (`status`),
  KEY `idx_invoices_customer` (`customer_id`),
  KEY `idx_invoices_date` (`invoice_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}invoice_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` INT UNSIGNED NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `kind` ENUM('accommodation','cleaning','deposit','custom') NOT NULL DEFAULT 'custom',
  `description` VARCHAR(255) NOT NULL,
  `apartment_id` INT UNSIGNED NULL DEFAULT NULL,
  `apartment_name` VARCHAR(100) NULL DEFAULT NULL,
  `date_from` DATE NULL DEFAULT NULL,
  `date_to` DATE NULL DEFAULT NULL,
  `beds` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `nights` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `unit_price_gross_cents` INT NOT NULL DEFAULT 0,
  `tax_rate_bp` SMALLINT UNSIGNED NOT NULL DEFAULT 1900,
  `tax_category` CHAR(1) NOT NULL DEFAULT 'S',
  `tax_exemption_reason` VARCHAR(255) NULL DEFAULT NULL,
  `line_gross_cents` INT NOT NULL DEFAULT 0,
  `line_net_cents` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_items_invoice` (`invoice_id`, `sort_order`),
  CONSTRAINT `fk_items_invoice` FOREIGN KEY (`invoice_id`)
    REFERENCES `{{prefix}}invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
