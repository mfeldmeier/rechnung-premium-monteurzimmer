-- Stammdaten aus der bisherigen Word-/PDF-Vorlage. Alles hier ist spaeter
-- in der Oberflaeche aenderbar, insbesondere USt-IdNr und Kautionshoehe.

INSERT IGNORE INTO `{{prefix}}apartments` (`name`, `beds_default`, `sort_order`) VALUES
  ('Kiwi',   2, 10),
  ('Traube', 2, 20),
  ('Banane', 2, 30),
  ('Orange', 2, 40);

INSERT IGNORE INTO `{{prefix}}settings` (`setting_key`, `setting_value`) VALUES
  ('seller_name',         'Premium Monteurzimmer Reher'),
  ('seller_owner',        'Philipp R. Reher'),
  ('seller_street',       'Wolfsangerstraße 131'),
  ('seller_postcode',     '34125'),
  ('seller_city',         'Kassel'),
  ('seller_country_code', 'DE'),
  ('seller_phone',        '+49 15237395167'),
  ('seller_email',        'reher@premium-monteurzimmer.com'),
  ('seller_website',      'www.premium-monteurzimmer.com'),
  ('seller_tax_number',   '02646653810'),
  ('seller_vat_id',       ''),
  ('bank_name',           '1822direkt'),
  ('bank_iban',           'DE50 5005 0201 1235 2041 28'),
  ('bank_bic',            'HELADEF1822'),
  ('invoice_subject',     'Monteurzimmer'),
  ('closing_text',        'Mit freundlichen Grüßen'),
  ('payment_terms_text',  'Die Zahlung ist sofort und vor Bezug ohne Abzüge per Echtzeitüberweisung oder in Bar zu leisten. Mit der Überweisung willigen Sie in die AGB''s, einsehbar unter www.premium-monteurzimmer.com, ein.'),
  ('number_format',       '{seq}/{year}'),
  ('number_reset_yearly', '1');

-- Klick-Bausteine. "Wohnung" hat bewusst keinen Standardpreis:
-- der Nachtpreis wird je Buchung einzeln verhandelt.
INSERT IGNORE INTO `{{prefix}}item_presets`
  (`label`, `kind`, `description_template`, `default_unit_price_gross_cents`, `price_is_manual`, `tax_rate_bp`, `tax_category`, `tax_exemption_reason`, `sort_order`) VALUES
  ('Wohnung',      'accommodation', 'Wohnung {apartment}', NULL,  1,  700, 'S', NULL, 10),
  ('Endreinigung', 'cleaning',      'Endreinigung',        6000,  0, 1900, 'S', NULL, 20),
  ('Kaution',      'deposit',       'Kaution',            10000,  0,    0, 'E', 'Kaution – durchlaufender Posten, kein Entgelt i.S.d. § 10 UStG', 30),
  ('Sonstiges',    'custom',        '',                    NULL,  1, 1900, 'S', NULL, 40);

-- Letzte von Hand vergebene Nummer laut Vorlage: 68/2026. Naechste wird 69/2026.
INSERT INTO `{{prefix}}number_counters` (`number_year`, `last_seq`) VALUES (2026, 68)
  ON DUPLICATE KEY UPDATE `last_seq` = GREATEST(`last_seq`, VALUES(`last_seq`));
