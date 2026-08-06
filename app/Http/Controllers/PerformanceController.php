<?php

declare(strict_types=1);

namespace GoldBot\Http\Controllers;

use GoldBot\Core\Controller;
use GoldBot\Core\HttpException;
use GoldBot\Core\Request;
use GoldBot\Core\Response;
use GoldBot\Core\View;
use GoldBot\Services\Auth\AuthService;
use GoldBot\Services\Dashboard\PerformanceService;

/**
 * Performance.
 *
 * Everything on this page is denominated in R, the risk multiple, because two
 * signals with different stop distances are not comparable in any other unit.
 */
final class PerformanceController extends Controller
{
    public function __construct(
        View $view,
        AuthService $auth,
        private readonly PerformanceService $performance
    ) {
        parent::__construct($view, $auth);
    }

    public function index(Request $request): Response
    {
        if (!($this->auth->user()?->can('performance.view') ?? false)) {
            throw HttpException::forbidden();
        }

        return $this->render('performance.index', [
            'title'  => 'Performance',
            'charts' => true,
            'report' => $this->performance->report(
                $request->int('days', 90),
                $request->string('strategy') ?: null
            ),
        ]);
    }
}
