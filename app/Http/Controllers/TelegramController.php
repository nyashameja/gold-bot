<?php

declare(strict_types=1);

namespace GoldBot\Http\Controllers;

use GoldBot\Core\Controller;
use GoldBot\Core\HttpException;
use GoldBot\Core\Request;
use GoldBot\Core\Response;
use GoldBot\Core\View;
use GoldBot\Infrastructure\Clock\ClockInterface;
use GoldBot\Repositories\Contracts\AuditRepositoryInterface;
use GoldBot\Repositories\Contracts\TelegramRepositoryInterface;
use GoldBot\Services\Auth\AuthService;
use GoldBot\Services\Dashboard\TelegramBoardService;

final class TelegramController extends Controller
{
    public function __construct(
        View $view,
        AuthService $auth,
        private readonly TelegramBoardService $board,
        private readonly TelegramRepositoryInterface $telegram,
        private readonly AuditRepositoryInterface $audit,
        private readonly ClockInterface $clock
    ) {
        parent::__construct($view, $auth);
    }

    public function index(Request $request): Response
    {
        $this->guard('telegram.view');

        return $this->render('telegram.index', [
            'title' => 'Telegram',
            'board' => $this->board->board(),
        ]);
    }

    /**
     * Put a failed message back in the queue.
     *
     * The retry does not send anything here — it makes the message available
     * again and lets the drain cron pick it up. Sending inside a web request
     * would put a third-party API call on the critical path of a page load,
     * which is the thing the outbox exists to avoid (ADR-07).
     */
    public function retry(Request $request, string $id): Response
    {
        $this->guard('telegram.send');

        $messageId = (int) $id;
        $requeued = $this->telegram->requeue($messageId, $this->clock->now());

        if ($requeued) {
            $this->audit->record(
                $this->auth->user()?->id,
                'telegram.message_requeued',
                'telegram_message',
                (string) $messageId,
                null,
                null,
                $request->ipBinary(),
                $request->userAgent()
            );
        }

        return $this->redirect('/telegram')->with(
            $requeued ? 'success' : 'error',
            $requeued
                ? 'Message requeued — the next drain run will send it.'
                : 'That message cannot be requeued.'
        );
    }

    private function guard(string $permission): void
    {
        if (!($this->auth->user()?->can($permission) ?? false)) {
            throw HttpException::forbidden();
        }
    }
}
