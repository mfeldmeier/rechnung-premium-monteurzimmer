# Premium Monteurzimmer – Rechnungssoftware

Erstellt Ausgangsrechnungen für die vier Monteurzimmer (Kiwi, Traube, Banane, Orange) als ZUGFeRD-Hybrid-PDF (PDF/A-3b mit eingebetteter XML-Rechnung) mit fortlaufender Rechnungsnummer.

**Technik-Stack:** PHP 8.3, kein Framework, mpdf für PDF-Erzeugung, horstoeko/zugferd für ZUGFeRD-Konformität. Datenhaltung in MySQL (selbes netcup-Paket wie WordPress), Deployment via GitHub Actions auf netcup Webhosting 1000.

---

## Lokales Setup

```bash
./scripts/setup.sh
cp .env.example .env
# .env ausfüllen (DB_PASS, ggf. DB_HOST/DB_PORT für SSH-Tunnel – siehe unten)
php db/migrate.php
php bin/user.php add marvin@immocation.de "Marvin"
php -S localhost:8080 -t public
```

### Lokaler DB-Zugriff via SSH-Tunnel

Die MySQL-IP `10.35.232.161` ist paketintern – von außen nicht erreichbar. Lokal Tunnel aufbauen:

```bash
ssh -L 3307:10.35.232.161:3306 <ssh-user>@<netcup-host>
```

Dann in `.env`:

```
DB_HOST=127.0.0.1
DB_PORT=3307
```

---

## Deployment

Der Workflow `.github/workflows/deploy.yml` baut `vendor/` im CI (Composer auf dem Server nicht verfügbar) und überträgt alles via FTPS. Folgende GitHub-Secrets und -Variables müssen im Repository gesetzt sein:

| Typ | Name | Inhalt |
|-----|------|--------|
| Secret | `FTP_SERVER` | Hostname des netcup-FTP-Servers |
| Secret | `FTP_USER` | FTP-Benutzername |
| Secret | `FTP_PASSWORD` | FTP-Passwort |
| Secret | `FTP_REMOTE_DIR` | `/rechnung.premium-monteurzimmer.com/httpdocs/` (mit Slash am Ende!) |
| Secret | `DB_PASS` | Passwort der WordPress-Datenbank |
| Secret | `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PREFIX` / `APP_URL` | optional, überschreiben die Defaults im Workflow |
| Variable | `FTP_PORT` | FTP-Port, Standard 21 |

Die `.env` wird im Workflow aus diesen Secrets erzeugt und mitgeladen. Sie ist
deshalb **nicht** von der Übertragung ausgenommen und wird bei jedem Deploy
überschrieben — Änderungen direkt auf dem Server gehen verloren, Konfiguration
gehört in die Secrets. Ausgenommen bleibt nur `storage/`, damit erzeugte
Rechnungs-PDFs einen Deploy überleben.

### Verzeichnislayout auf dem Server

Das Repo liegt in `httpdocs/`, der **Dokumentstamm der Subdomain muss in Plesk
auf `httpdocs/public` zeigen**. Nur dann sind `.env`, `src/`, `vendor/` und
`storage/` physisch außerhalb des Webpfads und nicht bloß per `.htaccess`
abgeschirmt.

Zeigt der Dokumentstamm stattdessen auf `httpdocs`, liefert die Seite
durchgängig **403** — das `Require all denied` im Wurzel-`.htaccess` vererbt
sich dann auf `public/`, und einen Front-Controller-Rewrite gibt es dort nicht.
Das ist Absicht: lieber ein klarer Fehler als eine Installation, die läuft und
dabei die Zugangsdaten ausliefert.

**Migration nach Deployment:** Der GitHub-Runner hat keinen Zugriff auf die paketinterne DB. Nach dem ersten Deployment und bei Schema-Änderungen muss die Migration manuell per SSH ausgeführt werden:

```bash
ssh <ssh-user>@<netcup-host>
cd ~/rechnung.premium-monteurzimmer.com/httpdocs
php db/migrate.php
php bin/user.php add <mail> "<Name>"   # nur beim ersten Mal
```

---

## Steuerliche Annahmen

> **Hinweis:** Diese Annahmen sollten vom Steuerberater bestätigt werden.

| Position | Steuersatz | Grundlage |
|----------|-----------|-----------|
| Übernachtung | 7 % | § 12 Abs. 2 Nr. 11 UStG (kurzfristige Beherbergung) |
| Endreinigung | 19 % | Regelsteuersatz, eigenständige Leistung |
| Kaution | 0 % / steuerfrei | Durchlaufender Posten; in ZUGFeRD mit UNTDID-Kategorie `E` (Exempt) ausgewiesen, da keine steuerbare Leistung |

### Preisbasis brutto oder netto

Über den Positionen steht ein Umschalter, der für die ganze Rechnung gilt.
Voreinstellung ist brutto – so werden die Nächte verhandelt. Bei „zzgl. MwSt"
gelten die eingetippten Beträge als netto und werden je Zeile einmal
hochgerechnet; gerundet wird danach wie immer je Steuergruppe.

Gespeichert bleibt beides: `unit_price_gross_cents` und `line_gross_cents`
sind weiterhin brutto (daran hängen PDF, ZUGFeRD BT-148 und das Storno), die
Eingabe steht unverändert in `input_unit_price_cents` und `input_line_cents`.
Ohne diese beiden Spalten müsste der Editor den Nettopreis zurückrechnen, und
der Betrag würde bei jedem zweiten Speichern um einen Cent wandern.

## Nummernkreis und Zurücksetzen

Die nächste Rechnungsnummer steht in der Oberfläche: in der Rechnungsliste neben
der Überschrift und im Editor neben dem Entwurfstitel. Sie ist **unverbindlich** —
wer zwischendurch eine andere Rechnung finalisiert, verschiebt sie. Reserviert
wird sie bewusst nicht, sonst risse jeder verworfene Entwurf eine Lücke.

Zum Ende der Testphase lassen sich alle Rechnungen löschen und der Zähler neu
setzen:

```bash
php bin/reset-invoices.php                            # zeigt nur den Bestand
php bin/reset-invoices.php --force                    # löscht, nächste wird 69/2026
php bin/reset-invoices.php --force --next=1 --year=2027
```

> **Das ist endgültig.** Ohne `--force` wird nur gezählt. Für bereits versandte
> Rechnungen ist es der falsche Weg: sie unterliegen der Aufbewahrungspflicht,
> und eine später erneut vergebene Nummer beschriebe dann zwei Vorgänge.

`last_seq` in `pmz_number_counters` ist die zuletzt **vergebene** Nummer — für
einen Start bei 69 steht dort also 68.

## Buchungen automatisch übernehmen

secra schickt zu jeder Buchung eine Bestätigung als PDF. `bin/import-bookings.php`
holt sie aus dem Postfach und legt daraus Rechnungsentwürfe an.

```bash
php bin/import-bookings.php                       # für den Cron, alle 10 Minuten
php bin/import-bookings.php --dry-run             # nur zeigen, nichts speichern
php bin/import-booking-file.php buchung.pdf       # einzelne Datei nachtragen
php bin/import-booking-file.php buchung.pdf --dry-run
```

**Angefasst werden nur** Mails von `channel-manager@secra.de`, deren Betreff mit
`Buchungsbestätigung Nr` beginnt und die ein PDF im Anhang haben. Alles andere
im Posteingang bleibt unberührt — auch dann, wenn ein PDF daran hängt. Beides
ist über `IMAP_SENDER` und `IMAP_SUBJECT_PREFIX` einstellbar.

Nach dem Absender wird serverseitig gesucht, nach dem Betreff nicht:
`Buchungsbestätigung` enthält einen Umlaut, und `IMAP SEARCH` mit Nicht-ASCII
ist je nach Server eine Wundertüte. Der Betreff wird deshalb in PHP geprüft —
tolerant gegenüber vorangestelltem `WG:` und gegenüber der Schreibweise
`Buchungsbestaetigung`. Der Absender wird zusätzlich exakt verglichen, weil
`IMAP SEARCH FROM` als Teilzeichenkette sucht und sonst auch
`channel-manager@nicht-secra.de` durchginge.

Gelesen werden Buchungsnummer, Objektnummer, Zeitraum, Nächte, Reisende,
Gastanschrift, Mietpreis, Endreinigung und Kaution. Daraus entsteht ein Entwurf
mit drei Positionen: Übernachtung (7 %, Endpreis als `price_mode = total`),
Endreinigung (19 %) und Kaution (steuerfrei).

> **Der Import rechnet nichts selbst.** Er geht durch denselben
> `InvoiceService::saveDraft()` wie eine von Hand getippte Rechnung. Rundung
> und Steuergruppen leben an genau einer Stelle.

**Was den Import stoppt:** Ergeben Mietpreis und Endreinigung nicht den
ausgewiesenen Reisepreis, oder passen die angegebenen Nächte nicht zum Zeitraum,
entsteht kein Entwurf, sondern eine Fehlermeldung. Eine Formatänderung bei secra
fällt damit sofort auf, statt eine falsche Rechnung zu erzeugen.

**Objektnummer zuordnen:** Unter *Wohnungen* trägt jede Wohnung ihre
Objekt-Nr. aus der Bestätigung ein (z. B. `310939`). Fehlt sie, entsteht der
Entwurf trotzdem — nur ohne Wohnung, mit Hinweis.

**Doppelte Zustellung:** Die Buchungsnummer steht in `pmz_invoices.booking_ref`
und ist eindeutig. Dieselbe Mail zweimal verarbeitet ergibt einen Entwurf.

**Nicht verarbeitet werden** Stornierungen und Buchungsänderungen. Die bleiben
bewusst Handarbeit — eine automatisch geänderte Rechnung ist riskanter als ein
Handgriff.

### Postfach einrichten

Die Zugangsdaten stehen in der `.env` (siehe `.env.example`). Auf dem Server
liegt das Postfach auf derselben Maschine, deshalb `IMAP_HOST=127.0.0.1`: so
verlassen weder Passwort noch Anhang den Rechner. Das Zertifikat trägt den
öffentlichen Hostnamen und passt nie zur IP — daher `IMAP_VALIDATE_CERT=0`.
Zeigt `IMAP_HOST` nach außen, gehört die Prüfung wieder auf `1`.

Die Ordner aus `IMAP_DONE_FOLDER` und `IMAP_FAIL_FOLDER` müssen im Postfach
existieren. Verarbeitete Mails wandern nach *Erledigt*, nicht verwertbare nach
*Fehler* — so läuft der Posteingang nicht zu und ein Fehlschlag bleibt sichtbar.

Cron in Plesk:

```
*/10 * * * * php /var/www/vhosts/.../httpdocs/bin/import-bookings.php
```

## PDF-Vorschau und Layoutprüfung

Im Editor zeigt `PDF-Vorschau` den aktuellen Formularstand als PDF an, ohne zu
speichern – es wird kein Entwurf angelegt und kein Neukunde erzeugt. Die
Vorschau enthält bewusst **kein** ZUGFeRD-XML: dafür wäre eine Rechnungsnummer
(BT-1) nötig, die es erst beim Finalisieren gibt.

Das Layout lässt sich ohne Datenbank und ohne Browser prüfen:

```bash
php bin/sample-pdf.php /tmp/muster      # 18 Positionen, bricht auf zwei Seiten um
php bin/sample-pdf.php /tmp/muster 0    # alles auf einer Seite
```

Die Muster enthalten absichtlich unangenehme Werte (sehr langer Firmenname,
Beschreibung ohne Leerzeichen, lange Steuerbefreiungs-Begründung).

> PDF/A und Wasserzeichen schließen sich in mPDF aus: PDF/A verbietet
> Transparenz. Deshalb rendert die Vorschau mit `archival: false`, das hybride
> Rechnungs-PDF dagegen weiterhin als PDF/A-3.

---

## Nicht erledigt / offen
