<?php

declare(strict_types=1);

namespace GoldBot\Http\Controllers;

use GoldBot\Core\Controller;
use GoldBot\Core\HttpException;
use GoldBot\Core\JsonResponse;
use GoldBot\Core\Request;
use GoldBot\Core\Response;
use GoldBot\Core\View;
use GoldBot\Console\TaskDispatcher;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use GoldBot\Repositories\Contracts\AuditRepositoryInterface;
use GoldBot\Services\Auth\AuthService;
use GoldBot\Services\Dashboard\HealthService;
use Throwable;

final class HealthController extends Controller
{
    public function __construct(
        View $view,
        AuthService $auth,
        private readonly HealthService $health,
        private readonly TaskDispatcher $dispatcher,
        private readonly AuditRepositoryInterface $audit,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($view, $auth);
    }

    public function index(Request $request): Response
    {
        $this->guard('health.view');

        return $this->render('health.index', [
            'title' => 'System Health',
            'board' => $this->health->board(),
        ]);
    }

    /** Polled by the status pill so a failure appears without a reload. */
    public function status(Request $request): JsonResponse
    {
        $this->guard('health.view');

        return $this->json($this->health->summary());
    }

    /**
     * Run one scheduled task now.
     *
     * The dispatcher takes the same named lock the cron does, so a manual run
     * during a scheduled one is refused rather than executed twice. That
     * matters most for exactly the task an operator is most tempted to poke:
     * a market import that has fallen behind.
     */
    public function runTask(Request $request, string $code): Response
    {
        $this->guard('tasks.run');

        $user = $this->auth->user();

        $this->audit->record(
            $user?->id,
            'task.run_manually',
            'scheduled_task',
            $code,
            null,
            null,
            $request->ipBinary(),
            $request->userAgent()
        );

        try {
            $result = $this->dispatcher->runOne($code);
        } catch (Throwable $e) {
            // A task failure is the task's problem, not the page's — report it
            // and stay on the dashboard rather than showing an error screen.
            $this->logger->error('Manual task run failed', [
                'event' => 'task.manual_failed',
                'task'  => $code,
                'error' => $e->getMessage(),
            ]);

            return $this->redirect('/health')->with('error', sprintf('%s failed: %s', $code, $e->getMessage()));
        }

        return $this->redirect('/health')->with(
            'success',
            sprintf(
                '%s: %s — %s',
                $code,
                $result->status,
                $result->output !== '' ? $result->output : ($result->errorMessage ?? 'no output')
            )
        );
    }

    private function guard(string $permission): void
    {
        if (!($this->auth->user()?->can($permission) ?? false)) {
            throw HttpException::forbidden();
        }
    }
}
