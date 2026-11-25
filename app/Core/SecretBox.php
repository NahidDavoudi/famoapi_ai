<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Authenticated symmetric encryption at rest (AES-256-GCM) for sensitive
 * application secrets such as TOTP seeds.
 *
 * Key resolution order:
 *   1. TOTP_ENCRYPTION_KEY (optional dedicated secret)
 *   2. APP_ENCRYPTION_KEY  (optional app-wide secret)
 *   3. JWT_SECRET          (mandatory existing app secret)
 *
 * The raw key material is always domain-separated via HKDF-SHA256 before use,
 * and ciphertexts are stored with a version prefix ("v1:") plus random 96-bit
 * IV and 128-bit GCM authentication tag.
 */
final class SecretBox
{
    private const CIPHER = 'aes-256-gcm';
    private const VERSION_PREFIX = 'v1:';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;
    private const HKDF_INFO = 'famo:secretbox:aes-256-gcm:v1';

    public static function encrypt(string $plaintext, ?string $context = null): string
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('OpenSSL extension is required for secret encryption');
        }

        $key = self::deriveKey($context);
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $context ?? '',
            self::TAG_BYTES
        );

        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new \RuntimeException('Failed to encrypt sensitive secret');
        }

        return self::VERSION_PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded, ?string $context = null): string
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('OpenSSL extension is required for secret decryption');
        }

        if (!str_starts_with($encoded, self::VERSION_PREFIX)) {
            throw new \RuntimeException('Unsupported encrypted secret format');
        }

        $raw = base64_decode(substr($encoded, strlen(self::VERSION_PREFIX)), true);
        if ($raw === false || strlen($raw) < self::IV_BYTES + self::TAG_BYTES + 1) {
            throw new \RuntimeException('Corrupted encrypted secret payload');
        }

        $iv = substr($raw, 0, self::IV_BYTES);
        $tag = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::IV_BYTES + self::TAG_BYTES);

        $key = self::deriveKey($context);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $context ?? ''
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Failed to decrypt sensitive secret');
        }

        return $plaintext;
    }

    private static function deriveKey(?string $context): string
    {
        $rawMaster = self::resolveMasterKey();
        $info = self::HKDF_INFO . ($context !== null && $context !== '' ? ':' . $context : '');
        return hash_hkdf('sha256', $rawMaster, 32, $info);
    }

    private static function resolveMasterKey(): string
    {
        foreach (['TOTP_ENCRYPTION_KEY', 'APP_ENCRYPTION_KEY', 'JWT_SECRET'] as $envKey) {
            $val = $_ENV[$envKey] ?? $_SERVER[$envKey] ?? getenv($envKey);
            if (is_string($val) && trim($val) !== '') {
                return $val;
            }
        }

        throw new \RuntimeException('No encryption key configured (set TOTP_ENCRYPTION_KEY or JWT_SECRET)');
    }
}
