<?php

declare(strict_types=1);

namespace Pmz\Http\Controller;

use Pmz\Domain\LineItem;
use Pmz\Domain\TaxCategory;
use Pmz\Http\Flash;
use Pmz\Http\Request;
use Pmz\Http\Response;
use Pmz\Http\View;
use Pmz\Repo\PresetRepo;

final class PresetController extends BaseController
{
    public function __construct(
        private readonly PresetRepo $presets = new PresetRepo(),
    ) {
    }

    public function index(Request $r): Response
    {
        $this->requireUser();

        return View::page('presets', [
            'title'  => 'Bausteine',
            'active' => 'presets',
            'rows'   => $this->presets->all(),
            'kinds'  => [
                LineItem::KIND_ACCOMMODATION => 'Übernachtung',
                LineItem::KIND_CLEANING      => 'Reinigung',
                LineItem::KIND_DEPOSIT       => 'Kaution',
                LineItem::KIND_CUSTOM        => 'Sonstiges',
            ],
            'taxes'  => [
                'S|700'  => '7 %',
                'S|1900' => '19 %',
                'E|0'    => 'steuerfrei',
            ],
        ]);
    }

    public function save(Request $r): Response
    {
        $this->requireUser();
        $this->guardPost($r);

        // Deaktiviert wird ueber den Haken "aktiv", nicht ueber eine eigene Aktion.
        $action = (string) $r->post('action', '');
        $id     = (int) $r->post('id', '0');
        $data   = $this->dataFromRequest($r);

        if ($data['label'] === '') {
            Flash::error('Bitte eine Beschriftung angeben.');

            return Response::redirect('/bausteine');
        }

        if ($data['tax_category'] === TaxCategory::EXEMPT && $data['tax_exemption_reason'] === '') {
            // EN16931 BR-E-10: ohne Begruendung waere die Rechnung spaeter ungueltig.
            Flash::error('Steuerfreie Bausteine brauchen eine Begründung.');

            return Response::redirect('/bausteine');
        }

        if ($action === 'create') {
            $this->presets->create($data);
            Flash::success('Baustein angelegt.');
        } elseif ($action === 'update' && $id > 0) {
            $this->presets->update($id, $data);
            Flash::success('Baustein gespeichert.');
        } else {
            Flash::error('Unbekannte Aktion.');
        }

        return Response::redirect('/bausteine');
    }

    /** @return array<string,mixed> */
    private function dataFromRequest(Request $r): array
    {
        $parts    = explode('|', (string) $r->post('tax', 'S|1900'), 2);
        $category = TaxCategory::isValid($parts[0] ?? '') ? $parts[0] : TaxCategory::STANDARD;
        $rateBp   = $category === TaxCategory::STANDARD ? (int) ($parts[1] ?? 1900) : 0;

        $priceManual = $r->post('price_is_manual') !== null;
        $priceInput  = trim((string) $r->post('default_price', ''));

        return [
            'label'                          => trim((string) $r->post('label', '')),
            'kind'                           => (string) $r->post('kind', LineItem::KIND_CUSTOM),
            'description_template'           => trim((string) $r->post('description_template', '')),
            // Bei manuellem Preis wird bewusst kein Vorgabewert gespeichert.
            'default_unit_price_gross_cents' => ($priceManual || $priceInput === '')
                ? null
                : InvoiceController::parsePriceCents($priceInput),
            'price_is_manual'                => $priceManual ? 1 : 0,
            'tax_rate_bp'                    => $rateBp,
            'tax_category'                   => $category,
            'tax_exemption_reason'           => trim((string) $r->post('tax_exemption_reason', '')),
            'sort_order'                     => (int) $r->post('sort_order', '0'),
            'is_active'                      => $r->post('is_active') !== null ? 1 : 0,
        ];
    }
}
