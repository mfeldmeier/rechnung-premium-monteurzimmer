-- Der AGB-Hinweis stand bisher nur im Zahlungstext und zeigte auf die
-- Startseite. Er bekommt eine eigene Einstellung und damit eine feste Zeile in
-- der Fussleiste - unabhaengig davon, ob jemand den Zahlungstext an einer
-- einzelnen Rechnung kuerzt.

INSERT IGNORE INTO `{{prefix}}settings` (`setting_key`, `setting_value`) VALUES
  ('agb_url', 'https://www.premium-monteurzimmer.com/agbs');

-- Nur der unveraenderte Seed-Text wird nachgezogen. Hat jemand den
-- Zahlungstext schon angepasst, bleibt seine Fassung stehen.
UPDATE `{{prefix}}settings`
   SET `setting_value` = 'Die Zahlung ist sofort und vor Bezug ohne Abzüge per Echtzeitüberweisung oder in Bar zu leisten. Mit der Überweisung willigen Sie in die AGB''s, einsehbar unter https://www.premium-monteurzimmer.com/agbs, ein.'
 WHERE `setting_key` = 'payment_terms_text'
   AND `setting_value` = 'Die Zahlung ist sofort und vor Bezug ohne Abzüge per Echtzeitüberweisung oder in Bar zu leisten. Mit der Überweisung willigen Sie in die AGB''s, einsehbar unter www.premium-monteurzimmer.com, ein.';
