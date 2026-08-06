<?php

declare(strict_types=1);

namespace GoldBot\Http\Controllers;

use GoldBot\Core\Controller;
use GoldBot\Core\HttpException;
use GoldBot\Core\Request;
use GoldBot\Core\Response;
use GoldBot\Core\View;
use GoldBot\Repositories\Contracts\AuditRepositoryInterface;
use GoldBot\Services\Auth\AuthService;

/**
 * The audit trail.
 *
 * Route middleware already required `audit.view`; the service-layer check
 * below is the second of the two enforcement points (docs/01 §10). It is not
 * redundant — it is what protects the action if this controller is ever
 * reached by another route, a CLI path, or a future API.
 */
final class AuditController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        View $view,
        AuthService $auth,
        private readonly AuditRepositoryInterface $audit
    ) {
        parent::__construct($view, $auth);
    }

    public function index(Request $request): Response
    {
        if (!($this->auth->user()?->can('audit.view') ?? false)) {
            throw HttpException::forbidden();
        }

        $page = max(1, $request->int('page', 1));

        return $this->render('audit.index', [
            'title'   => 'Audit Log',
            'entries' => $this->audit->recent(self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'page'    => $page,
        ]);
    }
}
