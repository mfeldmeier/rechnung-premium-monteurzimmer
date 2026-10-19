-- Secra nennt einen Endpreis inklusive Gebuehren. Rueckwaerts auf den
-- Nachtpreis gerechnet geht das selten glatt auf: 1.250,00 EUR auf 44
-- Einheiten sind 28,409090... pro Nacht und Bett. Bisher war der Einzelpreis
-- massgeblich und die Summe daraus berechnet - damit liesse sich ein
-- vorgegebener Endpreis nur durch Herumprobieren treffen.
--
-- price_mode legt fest, welcher der beiden Werte die Quelle der Wahrheit ist.
-- Bei 'unit' bleibt es beim bisherigen Verhalten. Bei 'total' ist
-- line_gross_cents massgeblich und unit_price_gross_cents nur noch abgeleitet.

ALTER TABLE `{{prefix}}invoice_items`
  ADD COLUMN `price_mode` ENUM('unit','total') NOT NULL DEFAULT 'unit' AFTER `unit_price_gross_cents`;
