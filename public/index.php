<?php

declare(strict_types=1);

use Pmz\Auth\Session;
use Pmz\Http\Controller\ApartmentController;
use Pmz\Http\Controller\AuthController;
use Pmz\Http\Controller\CustomerController;
use Pmz\Http\Controller\DashboardController;
use Pmz\Http\Controller\InvoiceController;
use Pmz\Http\Controller\PresetController;
use Pmz\Http\Controller\SettingsController;
use Pmz\Http\Request;
use Pmz\Http\Response;
use Pmz\Http\Router;
use Pmz\Http\View;
use Pmz\Support\Env;

$root = dirname(__DIR__);

// Vor dem Autoloader gibt es weder Logging noch Fehlerseite - ein Fehler hier
// endet als nackter 500. Deshalb die beiden haeufigsten Ursachen ausdruecklich
// abfangen und benennen.
if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit(
        'Diese Anwendung braucht PHP 8.3, laeuft hier aber auf ' . PHP_VERSION . ".\n"
        . "In Plesk wird die PHP-Version je Domain gesetzt und kann von der in der Shell abweichen.\n"
    );
}

if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit(
        "vendor/autoload.php fehlt. Die Abhaengigkeiten werden im CI gebaut und\n"
        . "mit ausgeliefert - fehlen sie, ist der Deploy nicht vollstaendig durchgelaufen.\n"
    );
}

require $root . '/vendor/autoload.php';

Env::load($root . '/.env');
View::setBase($root . '/templates');

$isProd = Env::get('APP_ENV', 'prod') === 'prod';

// Stacktraces verraten Pfade, Versionen und im Zweifel Teile der Konfiguration -
// im Betrieb gehen sie ausschliesslich ins Server-Log.
error_reporting(E_ALL);
ini_set('display_errors', $isProd ? '0' : '1');

try {
    Session::start();

    $auth       = new AuthController();
    $dashboard  = new DashboardController();
    $invoices   = new InvoiceController();
    $customers  = new CustomerController();
    $apartments = new ApartmentController();
    $presets    = new PresetController();
    $settings   = new SettingsController();

    $router = new Router();

    $router->get('/login', $auth->showLogin(...));
    $router->post('/login', $auth->login(...));
    $router->post('/logout', $auth->logout(...));

    $router->get('/', $dashboard->index(...));

    // Reihenfolge zaehlt: der Router nimmt den ersten Treffer, und {id} wuerde
    // sonst auch "neu" schlucken.
    $router->get('/rechnungen', $invoices->index(...));
    $router->get('/rechnungen/neu', $invoices->create(...));
    $router->get('/rechnungen/{id}', $invoices->show(...));
    $router->get('/rechnungen/{id}/pdf', $invoices->pdf(...));
    $router->post('/rechnungen/{id}/speichern', $invoices->save(...));
    $router->post('/rechnungen/{id}/finalisieren', $invoices->finalize(...));
    $router->post('/rechnungen/{id}/stornieren', $invoices->cancel(...));

    $router->get('/api/kunden/suche', $customers->search(...));
    $router->get('/kunden', $customers->index(...));
    $router->post('/kunden', $customers->store(...));
    $router->get('/kunden/{id}', $customers->edit(...));
    $router->post('/kunden/{id}', $customers->update(...));

    $router->get('/wohnungen', $apartments->index(...));
    $router->post('/wohnungen', $apartments->save(...));

    $router->get('/bausteine', $presets->index(...));
    $router->post('/bausteine', $presets->save(...));

    $router->get('/einstellungen', $settings->index(...));
    $router->post('/einstellungen', $settings->save(...));

    $response = $router->dispatch(Request::fromGlobals());
} catch (Throwable $e) {
    error_log(sprintf('[pmz] %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    $response = pmzErrorResponse($e, $isProd);
}

$response->send();

function pmzErrorResponse(Throwable $e, bool $isProd): Response
{
    $detail = $isProd ? null : sprintf(
        "%s: %s\n%s:%d\n\n%s",
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString(),
    );

    try {
        return View::page('error', [
            'title'   => 'Fehler',
            'heading' => 'Es ist ein Fehler aufgetreten',
            'message' => 'Die Aktion konnte nicht ausgeführt werden. Bitte erneut versuchen.',
            'detail'  => $detail,
        ], 500);
    } catch (Throwable) {
        // Wenn nicht einmal das Fehler-Template rendert, bleibt nur reiner Text.
        return Response::html('<!doctype html><meta charset="utf-8"><h1>Es ist ein Fehler aufgetreten.</h1>', 500);
    }
}
