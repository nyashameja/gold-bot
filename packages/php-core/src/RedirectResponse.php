<?php

declare(strict_types=1);

namespace Paragon\Core;

final class RedirectResponse extends Response
{
    public function __construct(string $location, int $status = 302)
    {
        parent::__construct('', $status, ['Location' => $location]);
    }

    /**
     * Flash a message into the session for the next request to display.
     *
     * Used for the post-redirect-get pattern after a form submission, so a
     * browser refresh does not resubmit.
     */
    public function with(string $key, mixed $value): self
    {
        $_SESSION['_flash'][$key] = $value;

        return $this;
    }

    /** @param array<string,string> $errors */
    public function withErrors(array $errors): self
    {
        return $this->with('errors', $errors);
    }

    /** @param array<string,mixed> $input Repopulates a form after a failure. */
    public function withInput(array $input): self
    {
        unset($input['password'], $input['password_confirmation'], $input['_token']);

        return $this->with('old', $input);
    }
}
