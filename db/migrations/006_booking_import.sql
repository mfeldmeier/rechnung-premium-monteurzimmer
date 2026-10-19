-- Buchungsbestaetigungen von secra werden automatisch zu Entwuerfen.
--
-- booking_ref ist die Buchungsnummer aus der Bestaetigung und ist EINDEUTIG:
-- dieselbe Mail kann mehrfach zugestellt werden (Weiterleitung, erneuter
-- Abruf, Wiedervorlage), und jedes Mal einen neuen Entwurf anzulegen waere
-- schlimmer als gar kein Import. Der Importeur prueft vorher, der Index ist
-- das Netz darunter.
--
-- NULL bleibt erlaubt und faellt nicht unter die Eindeutigkeit: von Hand
-- angelegte Rechnungen haben keine Buchungsnummer, und MySQL laesst beliebig
-- viele NULL-Werte in einem UNIQUE-Index zu.

ALTER TABLE `{{prefix}}invoices`
  ADD COLUMN `booking_ref` VARCHAR(40) NULL DEFAULT NULL AFTER `customer_freeform`;

ALTER TABLE `{{prefix}}invoices`
  ADD UNIQUE KEY `uq_booking_ref` (`booking_ref`);

-- Die Objektnummer von secra ("Objekt Nr. 310939") zeigt auf eine Wohnung.
-- Ohne diese Zuordnung kann der Import die Wohnung nicht setzen - er bricht
-- deshalb nicht ab, sondern legt den Entwurf ohne Wohnung an.
ALTER TABLE `{{prefix}}apartments`
  ADD COLUMN `external_ref` VARCHAR(40) NULL DEFAULT NULL AFTER `name`;

ALTER TABLE `{{prefix}}apartments`
  ADD KEY `idx_external_ref` (`external_ref`);
