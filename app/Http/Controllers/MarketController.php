<?php

declare(strict_types=1);

namespace GoldBot\Http\Controllers;

use GoldBot\Core\Controller;
use GoldBot\Core\HttpException;
use GoldBot\Core\JsonResponse;
use GoldBot\Core\Request;
use GoldBot\Core\Response;
use GoldBot\Core\View;
use GoldBot\Services\Auth\AuthService;
use GoldBot\Services\Dashboard\MarketBoardService;

/**
 * Live Market.
 *
 * The page ships the first chart payload inline so it draws on first paint
 * rather than after a round trip, then polls the JSON endpoints below for
 * updates. Both paths call the same service, so the rendered page and its
 * refreshes cannot drift apart.
 */
final class MarketController extends Controller
{
    public function __construct(
        View $view,
        AuthService $auth,
        private readonly MarketBoardService $market
    ) {
        parent::__construct($view, $auth);
    }

    public function index(Request $request): Response
    {
        $this->guard();

        $instrument = $this->market->instrumentBySymbol($request->string('symbol') ?: null);
        $timeframe = $this->market->resolveTimeframe($request->string('tf') ?: null);
        $instrumentId = (int) $instrument['id'];

        return $this->render('market.index', [
            'title'      => 'Live Market',
            'charts'     => true,
            'instrument' => $instrument,
            'timeframe'  => $timeframe,
            'quote'      => $this->market->quote($instrumentId),
            'chart'      => $this->market->chart($instrumentId, $timeframe),
            'overlays'   => $this->market->overlays($instrumentId, $timeframe),
            'timeframes' => $this->market->timeframeSummary($instrumentId),
            'sessions'   => $this->market->sessions(),
        ]);
    }

    /** The quote tile alone — the most frequently polled endpoint. */
    public function quote(Request $request): JsonResponse
    {
        $this->guard();

        $instrument = $this->market->instrumentBySymbol($request->string('symbol') ?: null);

        return $this->json($this->market->quote((int) $instrument['id']));
    }

    /** Candles, indicators and overlays for one timeframe. */
    public function series(Request $request): JsonResponse
    {
        $this->guard();

        $instrument = $this->market->instrumentBySymbol($request->string('symbol') ?: null);
        $timeframe = $this->market->resolveTimeframe($request->string('tf') ?: null);
        $instrumentId = (int) $instrument['id'];

        return $this->json([
            ...$this->market->chart($instrumentId, $timeframe),
            'overlays' => $this->market->overlays($instrumentId, $timeframe),
        ]);
    }

    /**
     * The second of the two authorisation checks (docs/01 §10). The route
     * middleware is the first; this one protects the action if the controller
     * is ever reached by a different route.
     */
    private function guard(): void
    {
        if (!($this->auth->user()?->can('market.view') ?? false)) {
            throw HttpException::forbidden();
        }
    }
}
