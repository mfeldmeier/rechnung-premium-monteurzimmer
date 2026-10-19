<?php

declare(strict_types=1);

namespace Pmz\Domain;

/**
 * Der Rechnungsempfaenger als Textblock, so wie er auf der Rechnung steht.
 *
 * Fuer Gelegenheitskunden ist das Pflegen einzelner Felder mehr Aufwand als
 * die Rechnung selbst. Auf dem PDF landet der Block unveraendert Zeile fuer
 * Zeile - gerade das ist der Zweck.
 *
 * ZUGFeRD braucht die Anschrift trotzdem strukturiert (BT-44 Name, BT-50 Ort,
 * BT-53 Land), deshalb liest diese Klasse sie aus dem Block heraus. Erkannt
 * wird von unten nach oben: eine Zeile, die mit einer Postleitzahl beginnt,
 * ist die Anschrift, die Zeile darueber die Strasse, alles davor der Name.
 *
 * Greift die Erkennung nicht, ist das kein Fehler, sondern ein offener Punkt:
 * Entwurf, Vorschau und PDF funktionieren weiter, nur das Finalisieren fragt
 * nach. Wer eine Rechnung tippt, soll nicht mitten im Satz ausgebremst werden.
 */
final readonly class RecipientBlock
{
    /**
     * Nur was im Alltag vorkommt. Alles andere kommt ueber das Praefix
     * "AT-5020 Salzburg" ins Land - oder bleibt DE.
     */
    private const COUNTRY_NAMES = [
        'deutschland' => 'DE',
        'germany'     => 'DE',
        'österreich'  => 'AT',
        'oesterreich' => 'AT',
        'austria'     => 'AT',
        'schweiz'     => 'CH',
        'switzerland' => 'CH',
        'luxemburg'   => 'LU',
        'niederlande' => 'NL',
        'belgien'     => 'BE',
        'frankreich'  => 'FR',
        'polen'       => 'PL',
        'italien'     => 'IT',
        'tschechien'  => 'CZ',
    ];

    /**
     * @param list<string>         $lines    die bereinigten Zeilen, wie sie aufs PDF gehen
     * @param array<string,string> $snapshot Kundenformat laut CustomerRepo::snapshot()
     * @param string|null          $problem  was fuer ZUGFeRD noch fehlt, im Klartext
     */
    private function __construct(
        public array $lines,
        public array $snapshot,
        public ?string $problem,
    ) {
    }

    public static function parse(string $text): self
    {
        $lines = self::cleanLines($text);

        if ($lines === []) {
            return new self([], self::emptySnapshot(), 'Der Rechnungsempfänger ist leer.');
        }

        // Das Land steht, wenn ueberhaupt, in der letzten Zeile. Es gehoert
        // nicht zur Anschriftzeile und wird deshalb vorab abgetrennt.
        $country = null;
        $rest    = $lines;
        $last    = mb_strtolower(trim((string) end($rest)));

        if (isset(self::COUNTRY_NAMES[$last]) && count($rest) > 1) {
            $country = self::COUNTRY_NAMES[$last];
            array_pop($rest);
        }

        $addressIndex = self::findAddressLine($rest);

        if ($addressIndex === null) {
            // Ohne erkannte Anschrift bleibt wenigstens der Name stehen, damit
            // Liste und Suche etwas anzuzeigen haben.
            $snapshot                 = self::emptySnapshot();
            $snapshot['company']      = $rest[0];
            $snapshot['country_code'] = $country ?? 'DE';

            return new self(
                $lines,
                $snapshot,
                'Es fehlt eine Zeile mit Postleitzahl und Ort, zum Beispiel „49084 Osnabrück“.',
            );
        }

        [$postcode, $city, $prefixCountry] = self::splitAddressLine($rest[$addressIndex]);

        // Eine Strasse gibt es nur, wenn ueber ihr noch ein Name uebrig bleibt.
        $hasStreet = $addressIndex >= 2;
        $nameLines = array_slice($rest, 0, $hasStreet ? $addressIndex - 1 : $addressIndex);

        $snapshot                 = self::emptySnapshot();
        $snapshot['company']      = $nameLines[0] ?? '';
        $snapshot['contact_name'] = implode(', ', array_slice($nameLines, 1));
        $snapshot['street']       = $hasStreet ? $rest[$addressIndex - 1] : '';
        $snapshot['postcode']     = $postcode;
        $snapshot['city']         = $city;
        $snapshot['country_code'] = $country ?? $prefixCountry ?? 'DE';

        return new self(
            $lines,
            $snapshot,
            $nameLines === [] ? 'Über der Anschrift fehlt der Name des Empfängers.' : null,
        );
    }

    /** Taugt der Block fuer eine E-Rechnung? */
    public function isComplete(): bool
    {
        return $this->problem === null;
    }

    public function text(): string
    {
        return implode("\n", $this->lines);
    }

    /**
     * Leerzeilen fliegen raus - auch mittendrin. Sonst reisst eine versehentlich
     * mitkopierte Leerzeile ein Loch in den Adressblock des PDFs.
     *
     * @return list<string>
     */
    private static function cleanLines(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $out   = [];

        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');

            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * Von unten nach oben: die letzte Zeile, die mit einer Postleitzahl
     * beginnt. Von unten, weil unter der Anschrift nichts Wichtiges mehr steht,
     * ueber ihr dagegen Hausnummern stehen, die aehnlich aussehen.
     *
     * @param list<string> $lines
     */
    private static function findAddressLine(array $lines): ?int
    {
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (self::looksLikeAddress($lines[$i])) {
                return $i;
            }
        }

        return null;
    }

    private static function looksLikeAddress(string $line): bool
    {
        return preg_match('/^(?:[A-Za-z]{1,3}\s*-\s*)?\d{4,5}\s+\S/u', $line) === 1;
    }

    /** @return array{0:string,1:string,2:?string} Postleitzahl, Ort, Land aus dem Praefix */
    private static function splitAddressLine(string $line): array
    {
        preg_match('/^(?:([A-Za-z]{1,3})\s*-\s*)?(\d{4,5})\s+(.+)$/u', $line, $m);

        $prefix = ($m[1] ?? '') !== '' ? strtoupper($m[1]) : null;

        return [
            $m[2] ?? '',
            trim($m[3] ?? ''),
            // Nur zweistellige Praefixe sind Laendercodes nach ISO 3166-1.
            $prefix !== null && strlen($prefix) === 2 ? $prefix : null,
        ];
    }

    /** @return array<string,string> */
    private static function emptySnapshot(): array
    {
        return [
            'company'      => '',
            'contact_name' => '',
            'street'       => '',
            'postcode'     => '',
            'city'         => '',
            'country_code' => 'DE',
            'email'        => '',
            'phone'        => '',
            'vat_id'       => '',
        ];
    }
}
