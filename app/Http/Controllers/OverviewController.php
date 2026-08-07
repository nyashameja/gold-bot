<?php

declare(strict_types=1);

namespace GoldBot\Http\Controllers;

use GoldBot\Services\Auth\AuthService;
use GoldBot\Services\Dashboard\OverviewService;
use Paragon\Core\JsonResponse;
use Paragon\Core\Request;
use Paragon\Core\Response;
use Paragon\Core\View;

/**
 * The Overview dashboard.
 *
 * Every tile reads from MySQL (docs/01 §8). No provider is contacted here, so
 * this page renders identically whether the network is up or not — only the
 * data ages, and each tile shows how old its own data is.
 */
final class OverviewController extends Controller
{
    public function __construct(
        View $view,
        AuthService $auth,
        private readonly OverviewService $overview
    ) {
        parent::__construct($view, $auth);
    }

    public function index(Request $request): Response
    {
        return $this->render('overview.index', [
            'title' => 'Overview',
            'board' => $this->overview->board(),
        ]);
    }

    /**
     * The polling endpoint behind the Overview's live tiles.
     *
     * Returns only the handful of values that actually move, so a refresh
     * every thirty seconds does not re-run the whole page's queries.
     */
    public function live(Request $request): JsonResponse
    {
        return $this->json($this->overview->live());
    }
}
