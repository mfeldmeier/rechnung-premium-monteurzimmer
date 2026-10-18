<?php

declare(strict_types=1);

namespace Pmz\Http\Controller;

use Pmz\Http\Flash;
use Pmz\Http\Request;
use Pmz\Http\Response;
use Pmz\Http\View;
use Pmz\Repo\CustomerRepo;

final class CustomerController extends BaseController
{
    private const FIELDS = [
        'company', 'contact_name', 'street', 'postcode', 'city',
        'country_code', 'email', 'phone', 'vat_id', 'notes',
    ];

    public function __construct(
        private readonly CustomerRepo $customers = new CustomerRepo(),
    ) {
    }

    public function index(Request $r): Response
    {
        $this->requireUser();

        $query = trim((string) $r->query('q', ''));

        return View::page('customers/list', [
            'title'  => 'Kunden',
            'active' => 'customers',
            'q'      => $query,
            'rows'   => $this->customers->search($query, 200),
        ]);
    }

    public function store(Request $r): Response
    {
        $this->requireUser();
        $this->guardPost($r);

        $data = $this->dataFromRequest($r);

        if ($data['company'] === '' && $data['contact_name'] === '') {
            Flash::error('Bitte mindestens Firma oder Ansprechpartner angeben.');

            return Response::redirect('/kunden');
        }

        $id = $this->customers->create($data);
        Flash::success('Kunde angelegt.');

        return Response::redirect('/kunden/' . $id);
    }

    public function edit(Request $r, string $id): Response
    {
        $this->requireUser();

        $customer = $this->customers->find((int) $id);
        if ($customer === null) {
            return $this->notFound('Diesen Kunden gibt es nicht.');
        }

        return View::page('customers/form', [
            'title'    => (string) ($customer['company'] !== '' ? $customer['company'] : $customer['contact_name']),
            'active'   => 'customers',
            'customer' => $customer,
        ]);
    }

    public function update(Request $r, string $id): Response
    {
        $this->requireUser();
        $this->guardPost($r);

        $customerId = (int) $id;
        if ($this->customers->find($customerId) === null) {
            return $this->notFound('Diesen Kunden gibt es nicht.');
        }

        $this->customers->update($customerId, $this->dataFromRequest($r));
        Flash::success('Kunde gespeichert.');

        return Response::redirect('/kunden/' . $customerId);
    }

    /** Liefert die Treffer fuer die Vorbefuellung im Rechnungseditor. */
    public function search(Request $r): Response
    {
        $this->requireUser();

        $rows = $this->customers->search(trim((string) $r->query('q', '')), 10);

        $out = array_map(static fn (array $row): array => [
            'id'           => (int) $row['id'],
            'company'      => (string) $row['company'],
            'contact_name' => (string) $row['contact_name'],
            'street'       => (string) $row['street'],
            'postcode'     => (string) $row['postcode'],
            'city'         => (string) $row['city'],
            'country_code' => (string) $row['country_code'],
            'email'        => (string) $row['email'],
            'phone'        => (string) $row['phone'],
            'vat_id'       => (string) $row['vat_id'],
        ], $rows);

        return Response::json(['results' => $out]);
    }

    /** @return array<string,string> */
    private function dataFromRequest(Request $r): array
    {
        $data = [];

        foreach (self::FIELDS as $field) {
            $data[$field] = trim((string) $r->post($field, ''));
        }

        return $data;
    }
}
