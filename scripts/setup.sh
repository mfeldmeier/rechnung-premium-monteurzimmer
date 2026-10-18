#!/usr/bin/env bash
# Einmaliges lokales Setup. Netzzugriff und Composer brauchen deine Freigabe,
# deshalb liegt das hier als Skript statt als Befehlsliste im Chat.
set -euo pipefail
cd "$(dirname "$0")/.."

command -v composer >/dev/null || { echo "composer fehlt" >&2; exit 1; }

[ -d .git ] || git init -q

composer install --no-interaction --no-progress

[ -f .env ] || { cp .env.example .env; echo "-> .env angelegt, DB_PASS eintragen"; }

mkdir -p storage/invoices storage/tmp
chmod 700 storage storage/invoices storage/tmp

echo
echo "Fertig. Naechste Schritte:"
echo "  1. DB_PASS in .env eintragen"
echo "  2. php db/migrate.php          # Tabellen anlegen + Seed"
echo "  3. composer test               # Rechenkern pruefen"
echo "  4. php -S localhost:8080 -t public   # lokal starten"
