<?php

declare(strict_types=1);

namespace GoldBot\Http\Controllers;

use GoldBot\Core\Controller;
use GoldBot\Core\Request;
use GoldBot\Core\Response;

/**
 * The Overview dashboard.
 *
 * Phase 2 establishes the shell and the design system. The widgets listed in
 * the brief are populated in Phase 8, once the market, signal and health data
 * they read actually exists — every tile here reads from MySQL only
 * (docs/01 §8), so none of them can be built before their tables are.
 */
final class OverviewController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->render('overview.index', [
            'title' => 'Overview',
        ]);
    }
}
