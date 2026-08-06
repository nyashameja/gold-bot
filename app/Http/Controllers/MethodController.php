<?php

declare(strict_types=1);

namespace GoldBot\Http\Controllers;

use GoldBot\Core\Controller;
use GoldBot\Core\HttpException;
use GoldBot\Core\Request;
use GoldBot\Core\Response;
use GoldBot\Core\View;
use GoldBot\Domain\Strategy\Strategies\SevenFourteenStrategy;
use GoldBot\Services\Auth\AuthService;
use GoldBot\Services\Dashboard\MethodService;

/**
 * The 714 Method page.
 *
 * Renders the active config version, not a prose description of the strategy.
 * The rules are data (ADR-06), so a hand-written page would go out of date the
 * first time anyone retuned them; what is shown here is exactly what the
 * engine will apply on its next run.
 */
final class MethodController extends Controller
{
    public function __construct(
        View $view,
        AuthService $auth,
        private readonly MethodService $method
    ) {
        parent::__construct($view, $auth);
    }

    public function index(Request $request): Response
    {
        if (!($this->auth->user()?->can('strategies.view') ?? false)) {
            throw HttpException::forbidden();
        }

        $code = $request->string('strategy') ?: SevenFourteenStrategy::CODE;
        $board = $this->method->board($code, max(1, $request->int('days', 30)));

        if ($board === null) {
            throw HttpException::notFound('No such strategy.');
        }

        return $this->render('method.index', [
            'title'      => '714 Method',
            'board'      => $board,
            'strategies' => $this->method->strategies(),
        ]);
    }
}
