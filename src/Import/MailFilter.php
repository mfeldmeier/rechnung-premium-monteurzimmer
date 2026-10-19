<?php

declare(strict_types=1);

namespace Pmz\Import;

/**
 * Entscheidet, welche Mails der Import ueberhaupt anfasst.
 *
 * Zu holen sind ausschliesslich die Benachrichtigungen des Channel Managers:
 * Absender channel-manager@secra.de, Betreff beginnt mit
 * "Buchungsbestätigung Nr", PDF im Anhang. Alles andere im Posteingang bleibt
 * unberuehrt - auch dann, wenn ein PDF daran haengt.
 *
 * Eigene Klasse statt ein paar Zeilen im Skript, damit sich die Regeln ohne
 * Postfach pruefen lassen.
 */
final class MailFilter
{
    public function __construct(
        private readonly string $sender = 'channel-manager@secra.de',
        private readonly string $subjectPrefix = 'Buchungsbestätigung Nr',
    ) {
    }

    /**
     * IMAP SEARCH FROM vergleicht als Teilzeichenkette - "secra.de" passt dort
     * auch auf "channel-manager@nicht-secra.de". Deshalb hier noch einmal genau.
     *
     * @param list<string> $addresses alle Absenderadressen der Nachricht
     */
    public function isFromSender(array $addresses): bool
    {
        $erwartet = $this->normalize($this->sender);

        foreach ($addresses as $address) {
            if ($this->normalize($address) === $erwartet) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gesucht wird der Anfang des Betreffs, aber tolerant: ein vorangestelltes
     * "WG:" aus einer Weiterleitung soll nicht dazu fuehren, dass die Buchung
     * liegen bleibt.
     */
    public function matchesSubject(string $subject): bool
    {
        return str_starts_with(
            $this->simplify($this->stripReplyMarkers($subject)),
            $this->simplify($this->subjectPrefix),
        );
    }

    private function stripReplyMarkers(string $subject): string
    {
        return preg_replace('/^(?:(?:re|aw|wg|fwd|fw)\s*:\s*)+/iu', '', trim($subject)) ?? $subject;
    }

    /**
     * Umlaute werden beidseitig umschrieben: schreibt secra den Betreff eines
     * Tages "Buchungsbestaetigung", greift die Regel weiterhin.
     */
    private function simplify(string $text): string
    {
        return str_replace(
            ['ä', 'ö', 'ü', 'ß'],
            ['ae', 'oe', 'ue', 'ss'],
            mb_strtolower(trim($text)),
        );
    }

    private function normalize(string $address): string
    {
        return strtolower(trim($address));
    }
}
