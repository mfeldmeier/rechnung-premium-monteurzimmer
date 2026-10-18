<?php

declare(strict_types=1);

namespace Pmz\Http\Controller;

use Pmz\Http\Flash;
use Pmz\Http\Request;
use Pmz\Http\Response;
use Pmz\Http\View;
use Pmz\Repo\SettingsRepo;

final class SettingsController extends BaseController
{
    /** Freitextfelder. number_reset_yearly ist ein Haken und wird separat behandelt. */
    private const KEYS = [
        'seller_name', 'seller_owner', 'seller_street', 'seller_postcode', 'seller_city',
        'seller_country_code', 'seller_phone', 'seller_email', 'seller_website',
        'seller_tax_number', 'seller_vat_id',
        'bank_name', 'bank_iban', 'bank_bic',
        'invoice_subject', 'closing_text', 'payment_terms_text',
        'number_format',
    ];

    public function __construct(
        private readonly SettingsRepo $settings = new SettingsRepo(),
    ) {
    }

    public function index(Request $r): Response
    {
        $this->requireUser();

        return View::page('settings', [
            'title'    => 'Einstellungen',
            'active'   => 'settings',
            'settings' => $this->settings->all(),
            'keys'     => self::KEYS,
        ]);
    }

    public function save(Request $r): Response
    {
        $this->requireUser();
        $this->guardPost($r);

        $pairs = [];
        foreach (self::KEYS as $key) {
            $pairs[$key] = trim((string) $r->post($key, ''));
        }

        if (!str_contains($pairs['number_format'], '{seq}')) {
            // Ohne laufende Nummer waere der Nummernkreis nicht mehr eindeutig
            // und das UNIQUE auf invoices.number wuerde beim zweiten Beleg greifen.
            Flash::error('Das Nummernformat muss {seq} enthalten.');

            return Response::redirect('/einstellungen');
        }

        $pairs['number_reset_yearly'] = $r->post('number_reset_yearly') !== null ? '1' : '0';

        $this->settings->setMany($pairs);
        Flash::success('Einstellungen gespeichert.');

        return Response::redirect('/einstellungen');
    }
}
