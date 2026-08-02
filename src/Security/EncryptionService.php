<?php

declare(strict_types=1);

namespace MailAI\Security;

use RuntimeException;

/**
 * EncryptionService
 *
 * Encrypts secrets at rest (SMTP passwords, API keys, DKIM private keys)
 * using libsodium's XChaCha20-Poly1305 AEAD construction.
 *
 * Master key comes from the environment (never the database, never the
 * webroot). Rotate it by re-encrypting all secrets — see rotateKey().
 *
 * Usage:
 *   $enc = new EncryptionService($_ENV['APP_ENCRYPTION_KEY']);
 *   $blob = $enc->encrypt(json_encode(['host' => ..., 'pass' => ...]));
 *   $plain = $enc->decrypt($blob);
 */
final class EncryptionService
{
    private string $key;

    /**
     * @param string $base64Key 32-byte key, base64-encoded (generate with
     *                          EncryptionService::generateKey()).
     */
    public function __construct(string $base64Key)
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('The sodium extension is required for EncryptionService.');
        }

        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(
                'APP_ENCRYPTION_KEY must be a base64-encoded 32-byte key. ' .
                'Generate one with EncryptionService::generateKey().'
            );
        }

        $this->key = $key;
    }

    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    /**
     * Encrypts plaintext and returns a base64 string safe for TEXT columns.
     * Format: base64(nonce || ciphertext)
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Invalid ciphertext blob.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed — ciphertext may be corrupted or key is wrong.');
        }

        return $plaintext;
    }

    /** Convenience helper for structured secrets (credential arrays, etc). */
    public function encryptArray(array $data): string
    {
        return $this->encrypt(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function decryptArray(string $encoded): array
    {
        return json_decode($this->decrypt($encoded), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Re-encrypts a blob under a new EncryptionService instance (new key).
     * Used during key rotation — decrypt with old instance, call this with
     * the new instance's key material.
     */
    public function rotateFrom(EncryptionService $old, string $encoded): string
    {
        return $this->encrypt($old->decrypt($encoded));
    }
}
