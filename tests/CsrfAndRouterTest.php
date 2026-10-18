<?php

declare(strict_types=1);

namespace Pmz\Tests;

use Pmz\Http\Csrf;
use Pmz\Http\RedirectException;
use Pmz\Http\Request;
use Pmz\Http\Response;
use Pmz\Http\Router;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CsrfAndRouterTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testStatischeRouteTrifft(): void
    {
        $router = (new Router())->get('/invoices', fn (): Response => Response::html('liste'));

        self::assertSame('liste', $router->dispatch($this->request('GET', '/invoices'))->body);
    }

    public function testPlatzhalterWirdAlsArgumentUebergeben(): void
    {
        $router = (new Router())->get('/invoices/{id}', fn (Request $r, string $id): Response => Response::html($id));

        self::assertSame('42', $router->dispatch($this->request('GET', '/invoices/42'))->body);
    }

    public function testMehrerePlatzhalterInReihenfolge(): void
    {
        $router = (new Router())->get(
            '/invoices/{id}/items/{item}',
            fn (Request $r, string $id, string $item): Response => Response::html($id . '-' . $item),
        );

        self::assertSame('7-3', $router->dispatch($this->request('GET', '/invoices/7/items/3'))->body);
    }

    public function testPlatzhalterGreiftNichtUeberSlashes(): void
    {
        self::assertNull((new Router())->match('/invoices/{id}', '/invoices/7/pdf'));
    }

    public function testUnbekannterPfadGibt404(): void
    {
        $router = (new Router())->get('/invoices', fn (): Response => Response::html('liste'));

        self::assertSame(404, $router->dispatch($this->request('GET', '/gibtsnicht'))->status);
    }

    public function testFalscheMethodeGibt404(): void
    {
        $router = (new Router())->post('/invoices', fn (): Response => Response::html('angelegt'));

        self::assertSame(404, $router->dispatch($this->request('GET', '/invoices'))->status);
    }

    public function testQueryUndTrailingSlashStoerenNicht(): void
    {
        $router  = (new Router())->get('/invoices/{id}', fn (Request $r, string $id): Response => Response::html($id));
        $request = $this->request('GET', '/invoices/42/?x=1');

        self::assertSame('42', $router->dispatch($request)->body);
    }

    public function testRedirectExceptionWirdZuWeiterleitung(): void
    {
        $router = (new Router())->get('/invoices', function (): Response {
            throw new RedirectException('/login');
        });

        $response = $router->dispatch($this->request('GET', '/invoices'));

        self::assertSame(302, $response->status);
        self::assertSame('/login', $response->headers['Location']);
    }

    public function testCsrfTokenBleibtProSitzungGleich(): void
    {
        self::assertSame(Csrf::token(), Csrf::token());
    }

    public function testCsrfCheckAkzeptiertGueltigesToken(): void
    {
        $token   = Csrf::token();
        $request = $this->request('POST', '/invoices', [Csrf::FIELD => $token]);

        Csrf::check($request);

        self::assertTrue(true, 'check() wirft bei gueltigem Token nicht');
    }

    public function testCsrfCheckLehntFalschesTokenAb(): void
    {
        Csrf::token();

        $this->expectException(RuntimeException::class);
        Csrf::check($this->request('POST', '/invoices', [Csrf::FIELD => 'falsch']));
    }

    public function testCsrfCheckLehntFehlendesTokenAb(): void
    {
        Csrf::token();

        $this->expectException(RuntimeException::class);
        Csrf::check($this->request('POST', '/invoices'));
    }

    public function testCsrfCheckLehntAbWennKeineSitzungExistiert(): void
    {
        // Ohne Token in der Sitzung darf ein mitgeschickter Wert niemals passen -
        // sonst koennte ein Angreifer die Pruefung durch Leeren der Sitzung umgehen.
        $this->expectException(RuntimeException::class);
        Csrf::check($this->request('POST', '/invoices', [Csrf::FIELD => '']));
    }

    public function testCsrfFieldEnthaeltDasToken(): void
    {
        self::assertStringContainsString(Csrf::token(), Csrf::field());
    }

    /** @param array<string,mixed> $post */
    private function request(string $method, string $path, array $post = []): Request
    {
        return new Request([], $post, [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI'    => $path,
            'SCRIPT_NAME'    => '/index.php',
        ]);
    }
}
