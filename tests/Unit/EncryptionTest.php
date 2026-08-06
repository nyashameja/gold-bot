<?php

declare(strict_types=1);

namespace GoldBot\Tests\Unit;

use GoldBot\Support\Encryption;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EncryptionTest extends TestCase
{
    private Encryption $encryption;

    protected function setUp(): void
    {
        $this->encryption = new Encryption(Encryption::generateKey());
    }

    public function test_it_round_trips_a_value(): void
    {
        $secret = '7654321:AAH-lRWmYourTelegramBotTokenHere';

        self::assertSame($secret, $this->encryption->decrypt($this->encryption->encrypt($secret)));
    }

    public function test_it_round_trips_an_empty_string_and_unicode(): void
    {
        self::assertSame('', $this->encryption->decrypt($this->encryption->encrypt('')));
        self::assertSame('XAU/USD — £1,900 · 金', $this->encryption->decrypt(
            $this->encryption->encrypt('XAU/USD — £1,900 · 金')
        ));
    }

    /**
     * A fresh nonce per call means identical plaintexts must not produce
     * identical ciphertexts — otherwise an observer can tell that two stored
     * secrets are the same without decrypting either.
     */
    public function test_encrypting_the_same_value_twice_yields_different_ciphertexts(): void
    {
        $a = $this->encryption->encrypt('same');
        $b = $this->encryption->encrypt('same');

        self::assertNotSame($a, $b);
        self::assertSame('same', $this->encryption->decrypt($a));
        self::assertSame('same', $this->encryption->decrypt($b));
    }

    /**
     * The whole point of an AEAD construction: tampering must be detected
     * rather than yielding plausible-looking garbage.
     */
    public function test_a_tampered_ciphertext_is_rejected(): void
    {
        $payload = $this->encryption->encrypt('sensitive');

        // Flip a character in the base64 body, leaving the prefix intact.
        $body = substr($payload, 4);
        $tampered = 'gb1:' . ($body[10] === 'A' ? 'B' : 'A') . substr($body, 1);

        $this->expectException(RuntimeException::class);

        $this->encryption->decrypt($tampered);
    }

    public function test_a_value_encrypted_with_another_key_cannot_be_read(): void
    {
        $other = new Encryption(Encryption::generateKey());
        $payload = $other->encrypt('sensitive');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed');

        $this->encryption->decrypt($payload);
    }

    public function test_it_rejects_a_payload_without_the_version_prefix(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('version prefix');

        $this->encryption->decrypt(base64_encode('not encrypted'));
    }

    public function test_it_rejects_a_key_of_the_wrong_length(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('32 bytes');

        new Encryption(base64_encode('too short'));
    }

    public function test_it_rejects_an_empty_key_with_generation_guidance(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_KEY is not set');

        new Encryption('');
    }

    public function test_is_encrypted_recognises_its_own_output(): void
    {
        self::assertTrue(Encryption::isEncrypted($this->encryption->encrypt('x')));
        self::assertFalse(Encryption::isEncrypted('plain text'));
    }
}
