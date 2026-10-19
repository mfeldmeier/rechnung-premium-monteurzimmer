-- Den Empfaenger Feld fuer Feld zu pflegen kostet bei Gelegenheitskunden mehr
-- Zeit als die Rechnung selbst. Alternativ darf die Anschrift als Block
-- eingegeben werden - so, wie sie spaeter auf der Rechnung steht.
--
-- Der Block haengt an der Rechnung, nicht am Kunden: Stammdaten entstehen nur,
-- wenn das Haekchen "als Kunde merken" gesetzt ist.
--
-- Ist customer_freeform gefuellt, gilt der Freitextmodus. Ein zusaetzliches
-- Kennzeichen braucht es dafuer nicht.
--
-- Fuer ZUGFeRD bleibt die Anschrift Pflicht (BT-44 Name, BT-50 Ort, BT-53
-- Land). Sie wird beim Finalisieren aus dem Block gelesen und wie bisher als
-- customer_snapshot eingefroren; der Block selbst bleibt daneben erhalten,
-- weil nur er die Zeilenaufteilung des PDFs kennt.

ALTER TABLE `{{prefix}}invoices`
  ADD COLUMN `customer_freeform` TEXT NULL DEFAULT NULL AFTER `customer_id`;
