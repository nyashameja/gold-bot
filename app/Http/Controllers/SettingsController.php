<?php

declare(strict_types=1);

namespace GoldBot\Http\Controllers;

use GoldBot\Services\Auth\AuthService;
use GoldBot\Services\Dashboard\SettingsAdminService;
use Paragon\Core\HttpException;
use Paragon\Core\Request;
use Paragon\Core\Response;
use Paragon\Core\View;

/**
 * Runtime settings.
 *
 * These are the values an operator may change while the system runs. They are
 * not credentials — API keys and the bot token live in the environment and are
 * never rendered here (docs/01 §10).
 */
final class SettingsController extends Controller
{
    public function __construct(
        View $view,
        AuthService $auth,
        private readonly SettingsAdminService $settings
    ) {
        parent::__construct($view, $auth);
    }

    public function index(Request $request): Response
    {
        $this->guard();

        return $this->render('settings.index', [
            'title'  => 'Settings',
            'groups' => $this->settings->grouped(),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->guard();

        $actor = $this->auth->user();

        if ($actor === null) {
            throw HttpException::unauthorised();
        }

        // Fields are nested under `settings[...]` because PHP rewrites dots in
        // top-level POST field names to underscores — `signals.max_open` would
        // arrive as `signals_max_open`, match no known key, and save nothing
        // while the form cheerfully reported success. Keys inside array
        // brackets are left alone.
        $input = $request->input('settings', []);

        if (!is_array($input)) {
            $input = [];
        }

        $result = $this->settings->apply($input, $actor, $request->ipBinary());

        if ($result['errors'] !== []) {
            return $this->redirect('/settings')
                ->withErrors($result['errors'])
                ->with('error', 'Some settings were not saved.');
        }

        return $this->redirect('/settings')->with(
            'success',
            $result['updated'] === []
                ? 'No changes to save.'
                : sprintf('Saved %d setting(s).', count($result['updated']))
        );
    }

    private function guard(): void
    {
        if (!($this->auth->user()?->can('settings.edit') ?? false)) {
            throw HttpException::forbidden();
        }
    }
}
