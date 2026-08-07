<?php

declare(strict_types=1);

namespace GoldBot\Http\Controllers;

use GoldBot\Core\Controller;
use GoldBot\Core\HttpException;
use GoldBot\Core\Request;
use GoldBot\Core\Response;
use GoldBot\Core\View;
use GoldBot\Repositories\Contracts\BacktestRepositoryInterface;
use GoldBot\Services\Auth\AuthService;

/**
 * Backtest results (ADR-04).
 *
 * Read-only. Running a backtest is a CLI operation, deliberately: a sweep is
 * minutes of CPU over hundreds of thousands of bars, and a web request that
 * takes minutes is a web request that times out halfway through and leaves an
 * operator unsure whether it ran. The page shows what the CLI produced.
 */
final class BacktestController extends Controller
{
    public function __construct(
        View $view,
        AuthService $auth,
        private readonly BacktestRepositoryInterface $backtests
    ) {
        parent::__construct($view, $auth);
    }

    public function index(Request $request): Response
    {
        $this->guard();

        return $this->render('backtest.index', [
            'title' => 'Backtests',
            'runs'  => $this->backtests->recent(50),
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $this->guard();

        $run = $this->backtests->findByUuid($uuid);

        if ($run === null) {
            throw HttpException::notFound('No such backtest.');
        }

        $backtestId = (int) $run['id'];

        return $this->render('backtest.show', [
            'title'  => 'Backtest ' . substr($uuid, 0, 8),
            'charts' => true,
            'run'    => $run,
            'trades' => $this->backtests->trades($backtestId),
            'bands'  => $this->backtests->scoreBands($backtestId),
        ]);
    }

    /**
     * Backtests are strategy research, so they sit behind `strategies.view` —
     * the same permission that governs the 714 Method page. The second of the
     * two authorisation checks (docs/01 §10).
     */
    private function guard(): void
    {
        if (!($this->auth->user()?->can('strategies.view') ?? false)) {
            throw HttpException::forbidden();
        }
    }
}
