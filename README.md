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
| Secret | `FTP_USERNAME` | FTP-Benutzername |
| Secret | `FTP_PASSWORD` | FTP-Passwort |
| Secret | `FTP_REMOTE_DIR` | Zielpfad auf dem Server, z. B. `/rechnung.premium-monteurzimmer.com/` (mit Slash!) |
| Variable | `FTP_PORT` | FTP-Port, Standard 21 |

**Migration nach Deployment:** Der GitHub-Runner hat keinen Zugriff auf die paketinterne DB. Nach dem ersten Deployment und bei Schema-Änderungen muss die Migration manuell per SSH ausgeführt werden:

```bash
ssh <ssh-user>@<netcup-host>
cd /rechnung.premium-monteurzimmer.com
php db/migrate.php
```

**Docroot:** Die Subdomain `rechnung.premium-monteurzimmer.com` muss auf das `public/`-Verzeichnis zeigen. Falls das in der netcup-Oberfläche nicht einstellbar ist, fängt die `.htaccess` im Projektwurzel den Zugriff auf alle anderen Verzeichnisse ab.

---

## Steuerliche Annahmen

> **Hinweis:** Diese Annahmen sollten vom Steuerberater bestätigt werden.

| Position | Steuersatz | Grundlage |
|----------|-----------|-----------|
| Übernachtung | 7 % | § 12 Abs. 2 Nr. 11 UStG (kurzfristige Beherbergung) |
| Endreinigung | 19 % | Regelsteuersatz, eigenständige Leistung |
| Kaution | 0 % / steuerfrei | Durchlaufender Posten; in ZUGFeRD mit UNTDID-Kategorie `E` (Exempt) ausgewiesen, da keine steuerbare Leistung |

---

## Nicht erledigt / offen
