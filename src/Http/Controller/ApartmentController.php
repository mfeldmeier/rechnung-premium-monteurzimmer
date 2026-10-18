<?php

declare(strict_types=1);

namespace Pmz\Http\Controller;

use Pmz\Http\Flash;
use Pmz\Http\Request;
use Pmz\Http\Response;
use Pmz\Http\View;
use Pmz\Repo\ApartmentRepo;

final class ApartmentController extends BaseController
{
    public function __construct(
        private readonly ApartmentRepo $apartments = new ApartmentRepo(),
    ) {
    }

    public function index(Request $r): Response
    {
        $this->requireUser();

        return View::page('apartments', [
            'title'  => 'Wohnungen',
            'active' => 'apartments',
            'rows'   => $this->apartments->all(),
        ]);
    }

    public function save(Request $r): Response
    {
        $this->requireUser();
        $this->guardPost($r);

        $action = (string) $r->post('action', '');
        $id     = (int) $r->post('id', '0');
        $name   = trim((string) $r->post('name', ''));

        $data = [
            'name'         => $name,
            'beds_default' => max(1, (int) $r->post('beds_default', '1')),
            'sort_order'   => (int) $r->post('sort_order', '0'),
            'is_active'    => $r->post('is_active') !== null ? 1 : 0,
        ];

        // Deaktiviert wird ueber den Haken "aktiv", nicht ueber eine eigene Aktion.
        match ($action) {
            'create' => $this->create($data),
            'update' => $this->update($id, $data),
            default  => Flash::error('Unbekannte Aktion.'),
        };

        return Response::redirect('/wohnungen');
    }

    /** @param array<string,mixed> $data */
    private function create(array $data): void
    {
        if ($data['name'] === '') {
            Flash::error('Bitte einen Namen angeben.');

            return;
        }

        $this->apartments->create($data);
        Flash::success('Wohnung angelegt.');
    }

    /** @param array<string,mixed> $data */
    private function update(int $id, array $data): void
    {
        if ($id <= 0 || $data['name'] === '') {
            Flash::error('Bitte einen Namen angeben.');

            return;
        }

        $this->apartments->update($id, $data);
        Flash::success('Wohnung gespeichert.');
    }
}
