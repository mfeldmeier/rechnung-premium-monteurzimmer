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
