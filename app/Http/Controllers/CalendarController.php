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
use GoldBot\Services\Dashboard\CalendarBoardService;

final class CalendarController extends Controller
{
    public function __construct(
        View $view,
        AuthService $auth,
        private readonly CalendarBoardService $calendar
    ) {
        parent::__construct($view, $auth);
    }

    public function index(Request $request): Response
    {
        $this->guard();

        return $this->render('calendar.index', [
            'title' => 'Economic Calendar',
            'board' => $this->calendar->board(
                $request->int('back', 2),
                $request->int('forward', 7),
                $request->string('impact') ?: null
            ),
        ]);
    }

    /**
     * The countdown widget polls this. Countdowns are recomputed server-side
     * rather than ticked in the browser, so a tab left open overnight cannot
     * drift away from the actual release time.
     */
    public function next(Request $request): JsonResponse
    {
        $this->guard();

        return $this->json(['event' => $this->calendar->nextHighImpact()]);
    }

    private function guard(): void
    {
        if (!($this->auth->user()?->can('calendar.view') ?? false)) {
            throw HttpException::forbidden();
        }
    }
}
