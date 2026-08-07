<?php

declare(strict_types=1);

namespace GoldBot\Http\Controllers;

use GoldBot\Services\Auth\AuthService;
use Paragon\Core\RedirectResponse;
use Paragon\Core\Request;
use Paragon\Core\Response;
use Paragon\Core\Support\Csrf;
use Paragon\Core\View;

final class AuthController extends Controller
{
    public function __construct(
        View $view,
        AuthService $auth,
        private readonly Csrf $csrf
    ) {
        parent::__construct($view, $auth);
    }

    public function showLogin(Request $request): Response
    {
        if ($this->auth->check()) {
            return $this->redirect('/');
        }

        return $this->render('auth.login', [], 'layouts.auth');
    }

    public function login(Request $request): Response
    {
        $email = $request->string('email');
        $password = (string) $request->input('password', '');

        if ($email === '' || $password === '') {
            return $this->backToLogin('Enter both your email address and password.', $email);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->backToLogin('That does not look like a valid email address.', $email);
        }

        $result = $this->auth->attempt($email, $password, $request);

        if (!$result->succeeded) {
            return $this->backToLogin($result->message, $email);
        }

        // Rotate the CSRF token alongside the session id, so a token captured
        // before authentication cannot be replayed after it.
        $this->csrf->rotate();

        $intended = $_SESSION['_intended'] ?? '/';
        unset($_SESSION['_intended']);

        return $this->redirect(is_string($intended) ? $intended : '/');
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($request);

        return (new RedirectResponse('/login'))->with('success', 'You have been signed out.');
    }

    private function backToLogin(string $message, string $email): RedirectResponse
    {
        return (new RedirectResponse('/login'))
            ->with('error', $message)
            ->withInput(['email' => $email]);
    }
}
