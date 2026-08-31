<?php

namespace AssistantHub\SymfonyConnector\Security;

final readonly class SecretCipher
{
    private string $key;

    public function __construct(string $masterKey)
    {
        if (strlen($masterKey) < 32) {
            throw new \InvalidArgumentException('The connector encryption key must contain at least 32 characters.');
        }
        $this->key = hash_hkdf('sha256', $masterKey, 32, 'assistant-hub-connector-v1');
    }

    public function encrypt(array $payload): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            json_encode($payload, JSON_THROW_ON_ERROR),
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'assistant-hub-connector:v1',
        );
        if (false === $ciphertext) {
            throw new \RuntimeException('Unable to encrypt connector secrets.');
        }

        return $this->base64UrlEncode($iv.$tag.$ciphertext);
    }

    public function decrypt(string $encoded): array
    {
        $padded = str_pad($encoded, (int) (ceil(strlen($encoded) / 4) * 4), '=', STR_PAD_RIGHT);
        $raw = base64_decode(strtr($padded, '-_', '+/'), true);
        if (false === $raw || strlen($raw) < 29) {
            throw new \RuntimeException('The encrypted connector payload is malformed.');
        }
        $plaintext = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'assistant-hub-connector:v1');
        if (false === $plaintext) {
            throw new \RuntimeException('Unable to decrypt connector secrets.');
        }
        $payload = json_decode($plaintext, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('The decrypted connector payload is invalid.');
        }

        return $payload;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
