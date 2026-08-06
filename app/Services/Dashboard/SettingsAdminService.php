<?php

declare(strict_types=1);

namespace GoldBot\Services\Dashboard;

use GoldBot\Domain\Identity\User;
use GoldBot\Repositories\Contracts\AuditRepositoryInterface;
use GoldBot\Repositories\Contracts\SettingsRepositoryInterface;

/**
 * Reading and writing runtime settings.
 *
 * Settings are the values an operator may change while the system runs —
 * thresholds, cadences, filter toggles. They are NOT credentials: those live
 * in the environment and never appear here (docs/01 §10). A setting marked
 * `is_secret` is masked on read and only written when a replacement is
 * actually supplied, so re-saving the form cannot overwrite a stored secret
 * with the row of dots the browser was showing.
 *
 * Every write is audited with its before and after value, because "who
 * widened the risk multiplier and when?" is a question that gets asked after
 * something expensive, and only a log written at the time can answer it.
 */
final class SettingsAdminService
{
    private const MASK = '••••••••';

    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly AuditRepositoryInterface $audit
    ) {
    }

    /**
     * Settings grouped for display, with secrets masked.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->settings->allWithMetadata() as $row) {
            $isSecret = (int) $row['is_secret'] === 1;
            $group = (string) $row['group'];

            $groups[$group][] = [
                'key'         => (string) $row['key'],
                'value'       => $isSecret ? self::MASK : $row['value'],
                'type'        => (string) $row['type'],
                'group'       => $group,
                'label'       => (string) $row['label'],
                'description' => $row['description'] === null ? null : (string) $row['description'],
                'is_secret'   => $isSecret,
                'updated_at'  => (string) $row['updated_at'],
                'updated_by'  => $row['updated_by_name'] === null ? 'system' : (string) $row['updated_by_name'],
            ];
        }

        return $groups;
    }

    /**
     * Apply a submitted form.
     *
     * Only keys that already exist are written. A settings form that creates
     * rows on submit turns a typo in a field name into a permanent orphan row
     * that nothing reads and nobody notices.
     *
     * @param array<string,mixed> $input
     * @return array{updated:list<string>,errors:array<string,string>}
     */
    public function apply(array $input, User $actor, ?string $ipBinary = null): array
    {
        $known = [];

        foreach ($this->settings->allWithMetadata() as $row) {
            $known[(string) $row['key']] = $row;
        }

        $updated = [];
        $errors = [];

        foreach ($input as $key => $raw) {
            if (!isset($known[$key])) {
                continue;
            }

            $meta = $known[$key];
            $isSecret = (int) $meta['is_secret'] === 1;

            // The masked placeholder means "unchanged", not "set it to dots".
            if ($isSecret && (!is_string($raw) || $raw === '' || $raw === self::MASK)) {
                continue;
            }

            $cast = $this->cast($raw, (string) $meta['type']);

            if ($cast === null) {
                $errors[$key] = sprintf('Expected a %s value.', $meta['type']);
                continue;
            }

            $before = $meta['value'];

            if ((string) $before === (string) $cast) {
                continue;
            }

            $this->settings->set($key, $cast, $actor->id);
            $updated[] = $key;

            $this->audit->record(
                $actor->id,
                'settings.updated',
                'setting',
                $key,
                // A secret's old and new values are never written to the audit
                // log — the log is readable by more people than the secret is.
                ['value' => $isSecret ? self::MASK : $before],
                ['value' => $isSecret ? self::MASK : $cast],
                $ipBinary
            );
        }

        return ['updated' => $updated, 'errors' => $errors];
    }

    /**
     * Coerce a submitted string into the setting's declared type.
     *
     * Returns null on a value that does not fit, so the caller reports an
     * error rather than storing a silently mangled one — a threshold that
     * quietly becomes 0 because "seventy" was typed is a live-fire hazard.
     */
    private function cast(mixed $raw, string $type): string|int|float|bool|null
    {
        if (is_array($raw)) {
            return null;
        }

        $value = is_string($raw) ? trim($raw) : $raw;

        return match ($type) {
            'int' => is_numeric($value) && (float) $value === floor((float) $value) ? (int) $value : null,
            'float' => is_numeric($value) ? (float) $value : null,
            // Checkbox inputs submit nothing when unchecked, so the controller
            // supplies '0'; anything truthy here is an explicit tick.
            'bool' => in_array((string) $value, ['1', '0', 'true', 'false', 'on', ''], true)
                ? in_array((string) $value, ['1', 'true', 'on'], true)
                : null,
            'json' => json_validate((string) $value) ? (string) $value : null,
            default => (string) $value,
        };
    }
}
