<?php

declare(strict_types=1);

namespace Paragon\Core\Support;

use RuntimeException;
use SensitiveParameter;

/**
 * Authenticated symmetric encryption for secrets at rest.
 *
 * For credentials an application stores on behalf of a third party — bot
 * tokens, provider API keys — so that a leaked database dump does not yield a
 * working credential.
 *
 * XChaCha20-Poly1305 via libsodium. Authenticated, so tampering is detected
 * rather than producing silent garbage, and the 24-byte nonce is large enough
 * to generate randomly without birthday-collision concerns.
 */
final class Encryption
{
    private readonly string $key;

    /**
     * @param string $prefix Version tag written into every ciphertext.
     *
     * It defaults to 'gb1:' — not because the kernel knows anything about Gold
     * Bot, but because Gold Bot already has rows encrypted under that tag and a
     * prefix is not a cosmetic choice: renaming it orphans every secret already
     * at rest. A new application should pass its own tag from the start.
     */
    public function __construct(
        #[SensitiveParameter] string $base64Key,
        private readonly string $prefix = 'gb1:'
    ) {
        if ($base64Key === '') {
            throw new RuntimeException(
                'APP_KEY is not set. Generate one with: '
                . 'php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"'
            );
        }

        $key = base64_decode($base64Key, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(
                'APP_KEY must be exactly 32 bytes, base64 encoded. '
                . 'Generate one with: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"'
            );
        }

        $this->key = $key;
    }

    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $nonce,
            $nonce,
            $this->key
        );

        // The version prefix means a future key-rotation or algorithm change
        // can identify and migrate old ciphertexts rather than failing on them.
        return $this->prefix . base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        if (!str_starts_with($payload, $this->prefix)) {
            throw new RuntimeException('Ciphertext is missing its version prefix; it may not be encrypted.');
        }

        $decoded = base64_decode(substr($payload, strlen($this->prefix)), true);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if ($decoded === false || strlen($decoded) <= $nonceLength) {
            throw new RuntimeException('Ciphertext is malformed or truncated.');
        }

        $nonce = substr($decoded, 0, $nonceLength);
        $ciphertext = substr($decoded, $nonceLength);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $nonce,
            $nonce,
            $this->key
        );

        if ($plaintext === false) {
            // Either the key changed or the ciphertext was tampered with. Both
            // are the same failure from here, and neither should say which.
            throw new RuntimeException('Decryption failed: the payload is invalid or the key has changed.');
        }

        return $plaintext;
    }

    /**
     * True when the value looks like something this instance produced.
     *
     * An instance method rather than a static one, because the prefix it tests
     * for is now per-instance: two applications sharing this kernel do not
     * share a ciphertext namespace.
     */
    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, $this->prefix);
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }
}
