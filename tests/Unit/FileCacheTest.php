<?php

declare(strict_types=1);

namespace GoldBot\Tests\Unit;

use GoldBot\Infrastructure\Cache\FileCache;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    private string $directory;

    private FileCache $cache;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/goldbot-cache-' . bin2hex(random_bytes(6));
        $this->cache = new FileCache($this->directory);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->directory);
    }

    public function test_it_stores_and_retrieves_values(): void
    {
        $this->cache->set('key', 'value');

        self::assertSame('value', $this->cache->get('key'));
        self::assertTrue($this->cache->has('key'));
    }

    public function test_it_returns_the_default_on_a_miss(): void
    {
        self::assertNull($this->cache->get('absent'));
        self::assertSame('fallback', $this->cache->get('absent', 'fallback'));
        self::assertFalse($this->cache->has('absent'));
    }

    public function test_it_round_trips_structured_values(): void
    {
        $value = ['ema_50' => 3312.45, 'nested' => ['a', 'b'], 'flag' => true, 'nothing' => null];
        $this->cache->set('structured', $value);

        self::assertSame($value, $this->cache->get('structured'));
    }

    /**
     * Distinguishes a stored null from a miss. Without this, caching a
     * legitimately null result re-computes it on every read.
     */
    public function test_a_stored_null_is_not_treated_as_a_miss(): void
    {
        $this->cache->set('null', null);

        self::assertTrue($this->cache->has('null'));
        self::assertNull($this->cache->get('null', 'fallback'));
    }

    public function test_an_expired_entry_is_a_miss(): void
    {
        $this->cache->set('short', 'value', 1);
        self::assertSame('value', $this->cache->get('short'));

        sleep(2);

        self::assertNull($this->cache->get('short'));
    }

    public function test_a_zero_ttl_does_not_expire(): void
    {
        $this->cache->set('forever', 'value', 0);

        self::assertSame('value', $this->cache->get('forever'));
    }

    public function test_delete_removes_an_entry_and_is_idempotent(): void
    {
        $this->cache->set('key', 'value');

        self::assertTrue($this->cache->delete('key'));
        self::assertFalse($this->cache->has('key'));
        self::assertTrue($this->cache->delete('key'), 'Deleting an absent key is not a failure.');
    }

    public function test_remember_computes_once_and_caches_the_result(): void
    {
        $calls = 0;

        $compute = function () use (&$calls): string {
            $calls++;

            return 'computed';
        };

        self::assertSame('computed', $this->cache->remember('k', 60, $compute));
        self::assertSame('computed', $this->cache->remember('k', 60, $compute));
        self::assertSame(1, $calls, 'The callback must not run on a hit.');
    }

    /**
     * Keys are hashed so a hostile or careless key cannot escape the cache
     * directory via path separators or traversal sequences.
     */
    public function test_a_traversal_style_key_stays_inside_the_cache_directory(): void
    {
        $this->cache->set('../../etc/passwd', 'value');

        self::assertSame('value', $this->cache->get('../../etc/passwd'));

        // The property that matters is where the file landed. Every entry must
        // resolve to a real path inside the cache directory — asserting on the
        // unresolved traversal path instead would test the filesystem, not us.
        $files = glob($this->directory . '/*/*.cache') ?: [];
        self::assertCount(1, $files);

        $realDirectory = realpath($this->directory);
        self::assertIsString($realDirectory);
        self::assertStringStartsWith($realDirectory . DIRECTORY_SEPARATOR, (string) realpath($files[0]));
    }

    public function test_keys_differing_only_by_separator_do_not_collide(): void
    {
        $this->cache->set('a/b', 'first');
        $this->cache->set('a:b', 'second');

        self::assertSame('first', $this->cache->get('a/b'));
        self::assertSame('second', $this->cache->get('a:b'));
    }

    public function test_a_corrupt_entry_is_treated_as_a_miss_and_removed(): void
    {
        $this->cache->set('key', 'value');

        $files = glob($this->directory . '/*/*.cache') ?: [];
        self::assertNotEmpty($files);
        file_put_contents($files[0], 'this is not serialised data');

        self::assertNull($this->cache->get('key'), 'A corrupt entry must not throw.');
        self::assertFileDoesNotExist($files[0]);
    }

    public function test_clear_removes_every_entry(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);

        $this->cache->clear();

        self::assertFalse($this->cache->has('a'));
        self::assertFalse($this->cache->has('b'));
    }
}
