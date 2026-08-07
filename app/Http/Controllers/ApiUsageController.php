<?php

declare(strict_types=1);

namespace GoldBot\Http\Controllers;

use GoldBot\Services\Auth\AuthService;
use GoldBot\Services\Dashboard\ApiUsageService;
use Paragon\Core\HttpException;
use Paragon\Core\Request;
use Paragon\Core\Response;
use Paragon\Core\View;

/**
 * API Usage.
 *
 * Both data sources are on free tiers with hard daily quotas, so exhausting
 * one means no market data until midnight. The projection column is the point
 * of the page: it says where today's consumption lands if the current rate
 * holds, which is the only version of this number that arrives in time to act
 * on.
 */
final class ApiUsageController extends Controller
{
    public function __construct(
        View $view,
        AuthService $auth,
        private readonly ApiUsageService $apiUsage
    ) {
        parent::__construct($view, $auth);
    }

    public function index(Request $request): Response
    {
        if (!($this->auth->user()?->can('api.view') ?? false)) {
            throw HttpException::forbidden();
        }

        return $this->render('api.index', [
            'title' => 'API Usage',
            'charts' => true,
            'board' => $this->apiUsage->board($request->int('hours', 48)),
        ]);
    }
}
