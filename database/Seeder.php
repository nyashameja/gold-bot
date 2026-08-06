<?php

declare(strict_types=1);

namespace GoldBot\Database;

use GoldBot\Core\Database;
use GoldBot\Infrastructure\Logging\LoggerInterface;
use RuntimeException;

/**
 * Seed runner for reference data.
 *
 * Unlike migrations, seeds carry no ledger and are re-runnable by design.
 * Every seed upserts rather than inserts, so running them after a deploy
 * repairs missing reference rows without duplicating or overwriting operator
 * edits made through the UI.
 */
final class Seeder
{
    public function __construct(
        private readonly Database $database,
        private readonly string $path,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param list<string> $only Seed names to run; empty runs all.
     * @return array<string,int> Seed name => rows affected.
     */
    public function run(array $only = []): array
    {
        $results = [];

        foreach ($this->availableSeeds() as $name) {
            if ($only !== [] && !in_array($name, $only, true)) {
                continue;
            }

            $file = $this->path . '/' . $name . '.php';
            $seed = require $file;

            if (!is_callable($seed)) {
                throw new RuntimeException(
                    "Seed [{$name}] must return a callable accepting a Database instance."
                );
            }

            $affected = (int) $seed($this->database);
            $results[$name] = $affected;

            $this->logger->info('Seed applied', [
                'event'    => 'seed.applied',
                'seed'     => $name,
                'affected' => $affected,
            ]);
        }

        return $results;
    }

    /** @return list<string> */
    private function availableSeeds(): array
    {
        $files = glob($this->path . '/*.php');

        if ($files === false) {
            throw new RuntimeException("Seed directory [{$this->path}] is not readable.");
        }

        $names = array_map(static fn (string $f): string => basename($f, '.php'), $files);
        sort($names, SORT_STRING);

        return array_values($names);
    }
}
