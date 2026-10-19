<?php

declare(strict_types=1);

namespace Pmz\Http;

final class Response
{
    /** @param array<string,string> $headers */
    private function __construct(
        public readonly string $body,
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $to): self
    {
        return new self('', 302, ['Location' => $to]);
    }

    /** @param array<array-key,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new self($json === false ? '{}' : $json, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function download(string $bytes, string $filename, string $mime): self
    {
        return self::attachment($bytes, $filename, $mime, 'attachment');
    }

    /** Wie download(), aber der Browser zeigt die Datei an, statt sie zu speichern. */
    public static function inline(string $bytes, string $filename, string $mime): self
    {
        return self::attachment($bytes, $filename, $mime, 'inline');
    }

    private static function attachment(string $bytes, string $filename, string $mime, string $disposition): self
    {
        // Dateiname nach RFC 6266 doppelt angeben: ASCII-Fallback plus UTF-8-Variante,
        // sonst verstuemmeln alte Clients Umlaute in Rechnungsnamen.
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'download';

        return new self($bytes, 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => sprintf(
                '%s; filename="%s"; filename*=UTF-8\'\'%s',
                $disposition,
                $ascii,
                rawurlencode($filename),
            ),
            'Content-Length'      => (string) strlen($bytes),
        ]);
    }

    public static function notFound(string $body = 'Nicht gefunden'): self
    {
        return self::html($body, 404);
    }

    public static function forbidden(string $body = 'Kein Zugriff'): self
    {
        return self::html($body, 403);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        echo $this->body;
    }
}
