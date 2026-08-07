<?php

declare(strict_types=1);

namespace GoldBot\Http\Controllers;

use GoldBot\Repositories\Contracts\AuditRepositoryInterface;
use GoldBot\Services\Auth\AuthService;
use GoldBot\Services\Dashboard\UserAdminService;
use Paragon\Core\HttpException;
use Paragon\Core\Request;
use Paragon\Core\Response;
use Paragon\Core\View;

/**
 * Users and roles.
 *
 * Viewing needs `users.view`; every mutation needs `users.manage`. The split
 * matters — an analyst should be able to see who has access without being able
 * to grant it.
 */
final class UserController extends Controller
{
    public function __construct(
        View $view,
        AuthService $auth,
        private readonly UserAdminService $users,
        private readonly AuditRepositoryInterface $audit
    ) {
        parent::__construct($view, $auth);
    }

    public function index(Request $request): Response
    {
        $this->guard('users.view');

        return $this->render('users.index', [
            'title' => 'Users',
            'board' => $this->users->board(),
            'canManage' => $this->auth->user()?->can('users.manage') ?? false,
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->guard('users.manage');

        $actor = $this->auth->user();

        if ($actor === null) {
            throw HttpException::unauthorised();
        }

        $input = $request->all();
        $result = $this->users->create($input, $actor, $request->ipBinary());

        if (!$result['ok']) {
            return $this->redirect('/users')
                ->withErrors($result['errors'])
                // withInput strips the password, so a validation failure
                // cannot round-trip a credential through the session.
                ->withInput($input);
        }

        return $this->redirect('/users')->with('success', 'User created.');
    }

    public function setActive(Request $request, string $id): Response
    {
        $this->guard('users.manage');

        $actor = $this->auth->user();

        if ($actor === null) {
            throw HttpException::unauthorised();
        }

        $result = $this->users->setActive(
            (int) $id,
            $request->bool('active'),
            $actor,
            $request->ipBinary()
        );

        return $this->redirect('/users')->with(
            $result['ok'] ? 'success' : 'error',
            $result['ok'] ? 'User updated.' : (string) $result['error']
        );
    }

    public function setRoles(Request $request, string $id): Response
    {
        $this->guard('users.manage');

        $actor = $this->auth->user();

        if ($actor === null) {
            throw HttpException::unauthorised();
        }

        $roles = array_values(array_filter((array) $request->input('roles', []), 'is_string'));
        $result = $this->users->setRoles((int) $id, $roles, $actor, $request->ipBinary());

        return $this->redirect('/users')->with(
            $result['ok'] ? 'success' : 'error',
            $result['ok'] ? 'Roles updated.' : (string) $result['error']
        );
    }

    private function guard(string $permission): void
    {
        if (!($this->auth->user()?->can($permission) ?? false)) {
            throw HttpException::forbidden();
        }
    }
}
