<?php

declare(strict_types=1);

namespace Pmz\Http\Controller;

use Pmz\Auth\Authenticator;
use Pmz\Auth\Session;
use Pmz\Http\Csrf;
use Pmz\Http\Flash;
use Pmz\Http\RedirectException;
use Pmz\Http\Request;
use Pmz\Http\Response;
use Pmz\Http\View;
use RuntimeException;

abstract class BaseController
{
    /** @return array<string,mixed> */
    protected function requireUser(): array
    {
        return Authenticator::requireLogin();
    }

    /**
     * Ein fehlgeschlagener CSRF-Test heisst in der Praxis fast immer:
     * abgelaufene Sitzung oder doppelt geoeffneter Tab. Das als 500er
     * auszuwerfen waere unbrauchbar, deshalb Meldung plus Weiterleitung.
     */
    protected function guardPost(Request $r): void
    {
        try {
            Csrf::check($r);
        } catch (RuntimeException) {
            Flash::error('Die Sitzung ist abgelaufen oder das Formular war zu alt. Bitte erneut versuchen.');

            throw new RedirectException(Session::isLoggedIn() ? '/' : Authenticator::LOGIN_PATH);
        }
    }

    protected function notFound(string $message = 'Diese Seite gibt es nicht.'): Response
    {
        return View::page('error', [
            'title'   => 'Nicht gefunden',
            'heading' => 'Nicht gefunden',
            'message' => $message,
        ], 404);
    }
}
