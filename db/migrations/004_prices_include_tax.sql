-- Bisher war jeder eingegebene Preis brutto. Fuer Firmenkunden wird aber
-- haeufig netto verhandelt ("280 EUR zzgl. MwSt"), und wer das von Hand
-- hochrechnet, vertippt sich.
--
-- prices_include_tax gilt fuer die ganze Rechnung, nicht je Zeile: der
-- Steuersatz steht ohnehin an der Position, und eine 13. Spalte im Editor
-- waere der Uebersicht nicht zutraeglich.
--
-- Kanonisch bleibt brutto: unit_price_gross_cents und line_gross_cents sind
-- weiterhin Bruttowerte, daran haengen PDF, ZUGFeRD (BT-148) und das Storno.
-- Die Eingabe wird daneben unveraendert mitgefuehrt. Sonst muesste der Editor
-- den Nettopreis beim Oeffnen zurueckrechnen, und dieser Rundungsweg laesst
-- den Betrag bei jedem zweiten Speichern um einen Cent wandern.

ALTER TABLE `{{prefix}}invoices`
  ADD COLUMN `prices_include_tax` TINYINT(1) NOT NULL DEFAULT 1 AFTER `closing_text`;

ALTER TABLE `{{prefix}}invoice_items`
  ADD COLUMN `input_unit_price_cents` INT UNSIGNED NULL DEFAULT NULL AFTER `price_mode`;

ALTER TABLE `{{prefix}}invoice_items`
  ADD COLUMN `input_line_cents` INT UNSIGNED NULL DEFAULT NULL AFTER `input_unit_price_cents`;

-- Bestand: alles war brutto, Eingabe und Bruttowert sind dort identisch.
UPDATE `{{prefix}}invoice_items`
   SET `input_unit_price_cents` = `unit_price_gross_cents`,
       `input_line_cents` = `line_gross_cents`
 WHERE `input_unit_price_cents` IS NULL;
