<?php

declare(strict_types=1);

namespace Pmz\Http;

use Pmz\Auth\Session;
use RuntimeException;
use Throwable;

/**
 * Minimaler Template-Renderer: reines PHP, kein Framework.
 *
 * render() liefert nur den Inhalt eines Templates, page() setzt ihn in
 * templates/layout.php ein und macht daraus eine Response.
 */
final class View
{
    private static string $base = '';

    public static function setBase(string $dir): void
    {
        self::$base = rtrim($dir, '/');
    }

    public static function base(): string
    {
        return self::$base !== '' ? self::$base : dirname(__DIR__, 2) . '/templates';
    }

    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = []): string
    {
        $__file = self::base() . '/' . $template . '.php';

        if (!is_file($__file)) {
            throw new RuntimeException("Template fehlt: {$template}");
        }

        require_once self::base() . '/_helpers.php';

        $__level = ob_get_level();
        ob_start();

        try {
            // Eigener Scope: das Template sieht nur $data, keine Interna des Renderers.
            (static function (string $__tpl, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__tpl;
            })($__file, $data);
        } catch (Throwable $e) {
            while (ob_get_level() > $__level) {
                ob_end_clean();
            }

            throw $e;
        }

        return (string) ob_get_clean();
    }

    /** @param array<string,mixed> $data */
    public static function page(string $template, array $data = [], int $status = 200): Response
    {
        $data['user']   ??= Session::user();
        $data['title']  ??= 'Rechnungen';
        $data['active'] ??= '';

        // Meldungen erst hier abholen: sie gehoeren genau einmal auf genau eine Seite.
        $data['flashes'] = Flash::pull();
        $data['content'] = self::render($template, $data);

        return Response::html(self::render('layout', $data), $status);
    }
}
