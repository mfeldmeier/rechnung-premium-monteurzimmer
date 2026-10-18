<?php

declare(strict_types=1);

namespace Pmz\Http\Controller;

use Pmz\Http\Request;
use Pmz\Http\Response;
use Pmz\Http\View;
use Pmz\Repo\InvoiceRepo;
use Pmz\Repo\StatsRepo;

final class DashboardController extends BaseController
{
    public function __construct(
        private readonly InvoiceRepo $invoices = new InvoiceRepo(),
        private readonly StatsRepo $stats = new StatsRepo(),
    ) {
    }

    public function index(Request $r): Response
    {
        $this->requireUser();

        $year = (int) date('Y');

        return View::page('dashboard', [
            'title'   => 'Übersicht',
            'active'  => 'dashboard',
            'year'    => $year,
            'summary' => $this->stats->yearSummary($year),
            'recent'  => $this->invoices->paginate([], 1, 10)['rows'],
        ]);
    }
}
