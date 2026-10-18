<?php

declare(strict_types=1);

namespace Pmz\Http\Controller;

use Pmz\Auth\Authenticator;
use Pmz\Auth\Session;
use Pmz\Http\Request;
use Pmz\Http\Response;
use Pmz\Http\View;

final class AuthController extends BaseController
{
    public function showLogin(Request $r): Response
    {
        if (Session::isLoggedIn()) {
            return Response::redirect('/');
        }

        return View::page('login', ['title' => 'Anmelden', 'email' => '', 'error' => null]);
    }

    public function login(Request $r): Response
    {
        $this->guardPost($r);

        $email    = strtolower(trim((string) $r->post('email', '')));
        $password = (string) $r->post('password', '');

        $user = Authenticator::attempt($email, $password);

        if ($user !== null) {
            Session::login($user);

            return Response::redirect('/');
        }

        // Dieselbe Meldung fuer falsches Passwort und unbekanntes Konto. Die
        // Sperre wird pro E-Mail gezaehlt und greift auch fuer unbekannte
        // Adressen, verraet also nicht, welche Konten existieren.
        $error = Authenticator::isLocked($email)
            ? 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.'
            : 'E-Mail oder Passwort stimmt nicht.';

        return View::page('login', ['title' => 'Anmelden', 'email' => $email, 'error' => $error], 401);
    }

    public function logout(Request $r): Response
    {
        $this->guardPost($r);

        Session::logout();

        return Response::redirect(Authenticator::LOGIN_PATH);
    }
}
