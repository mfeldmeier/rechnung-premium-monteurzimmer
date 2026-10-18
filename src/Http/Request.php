<?php

declare(strict_types=1);

namespace Pmz\Http;

final class Request
{
    /** @param array<string,mixed> $query @param array<string,mixed> $post @param array<string,mixed> $server */
    public function __construct(
        private array $query = [],
        private array $post = [],
        private array $server = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_GET, $_POST, $_SERVER);
    }

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /**
     * Pfad ohne Query und ohne Basis-Pfad. Der Basis-Pfad kommt aus SCRIPT_NAME,
     * damit die App auch in einem Unterverzeichnis laeuft, falls der Docroot
     * irgendwann nicht mehr direkt auf public/ zeigt.
     */
    public function path(): string
    {
        $uri  = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');

        $base = rtrim(str_replace('\\', '/', dirname((string) ($this->server['SCRIPT_NAME'] ?? '/'))), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $path = '/' . ltrim($path, '/');

        return $path !== '/' ? rtrim($path, '/') : '/';
    }

    public function query(string $k, ?string $d = null): ?string
    {
        $value = $this->query[$k] ?? null;

        return is_scalar($value) ? (string) $value : $d;
    }

    public function post(string $k, ?string $d = null): ?string
    {
        $value = $this->post[$k] ?? null;

        return is_scalar($value) ? (string) $value : $d;
    }

    /** @return array<array-key,mixed> */
    public function postArray(string $k): array
    {
        $value = $this->post[$k] ?? null;

        return is_array($value) ? $value : [];
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->post + $this->query;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        $value = $this->server[$key]
            ?? $this->server[strtoupper(str_replace('-', '_', $name))]
            ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Hinter dem netcup-Proxy steht die echte Adresse in X-Forwarded-For.
     * Der Wert ist faelschbar und dient nur der Protokollierung - niemals
     * fuer Zugriffsentscheidungen verwenden.
     */
    public function clientIp(): string
    {
        $forwarded = $this->header('X-Forwarded-For');
        if ($forwarded !== null && $forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if ($first !== '') {
                return $first;
            }
        }

        $remote = $this->server['REMOTE_ADDR'] ?? '';

        return is_scalar($remote) ? (string) $remote : '';
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        if (is_scalar($https) && $https !== '' && strtolower((string) $https) !== 'off') {
            return true;
        }

        return strtolower((string) ($this->header('X-Forwarded-Proto') ?? '')) === 'https';
    }
}
