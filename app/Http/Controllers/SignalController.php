<?php

declare(strict_types=1);

namespace GoldBot\Http\Controllers;

use GoldBot\Domain\Signal\SignalEventType;
use GoldBot\Repositories\Contracts\AuditRepositoryInterface;
use GoldBot\Repositories\Contracts\SignalRepositoryInterface;
use GoldBot\Services\Auth\AuthService;
use GoldBot\Services\Dashboard\SignalBoardService;
use Paragon\Core\Clock\ClockInterface;
use Paragon\Core\HttpException;
use Paragon\Core\JsonResponse;
use Paragon\Core\Request;
use Paragon\Core\Response;
use Paragon\Core\View;

final class SignalController extends Controller
{
    public function __construct(
        View $view,
        AuthService $auth,
        private readonly SignalBoardService $board,
        private readonly SignalRepositoryInterface $signals,
        private readonly AuditRepositoryInterface $audit,
        private readonly ClockInterface $clock
    ) {
        parent::__construct($view, $auth);
    }

    public function index(Request $request): Response
    {
        $this->guard('signals.view');

        $board = $this->board->page($request->query(), $request->int('page', 1));

        return $this->render('signals.index', [
            'title' => 'Signals',
            'board' => $board,
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $this->guard('signals.view');

        $signal = $this->board->detail($uuid);

        if ($signal === null) {
            throw HttpException::notFound('Signal not found.');
        }

        return $this->render('signals.show', [
            'title'  => 'Signal ' . substr($signal['uuid'], 0, 8),
            'signal' => $signal,
        ]);
    }

    /** Polled by the open-signals table so states update without a reload. */
    public function open(Request $request): JsonResponse
    {
        $this->guard('signals.view');

        return $this->json(['signals' => $this->board->openSignals(20)]);
    }

    /**
     * Cancel a pending or active signal by hand.
     *
     * Recorded as an event with the acting user attached, not as a state
     * overwrite — the log has to show that a person did this, and which one
     * (ADR-05). The repository refuses the transition if it is illegal, so a
     * double-submitted form cannot close an already-closed signal a second
     * time.
     */
    public function cancel(Request $request, string $uuid): Response
    {
        $this->guard('signals.cancel');

        $signal = $this->signals->findByUuid($uuid);

        if ($signal === null) {
            throw HttpException::notFound('Signal not found.');
        }

        $user = $this->auth->user();

        $applied = $this->signals->recordEvent(
            (int) $signal['id'],
            SignalEventType::Cancelled,
            $this->clock->now(),
            null,
            trim($request->string('reason')) ?: 'Cancelled from the dashboard.',
            'USER',
            $user?->id
        );

        if ($applied) {
            $this->audit->record(
                $user?->id,
                'signal.cancelled',
                'signal',
                $uuid,
                ['state' => $signal['state']],
                ['state' => 'CANCELLED'],
                $request->ipBinary(),
                $request->userAgent()
            );
        }

        return $this->redirect('/signals/' . $uuid)->with(
            $applied ? 'success' : 'error',
            $applied
                ? 'Signal cancelled.'
                : 'That signal can no longer be cancelled — it has already closed.'
        );
    }

    private function guard(string $permission): void
    {
        if (!($this->auth->user()?->can($permission) ?? false)) {
            throw HttpException::forbidden();
        }
    }
}
