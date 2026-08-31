<?php

namespace AssistantHub\SymfonyConnector\Store;

use AssistantHub\SymfonyConnector\Security\SecretCipher;
use AssistantHub\SymfonyConnector\Storage\ConnectorDatabase;

final readonly class ConnectorStore
{
    public function __construct(private ConnectorDatabase $database, private SecretCipher $cipher)
    {
    }

    public function createVault(array $tokens, array $identity): string
    {
        $id = $this->id('vault');
        $now = $this->now();
        $actorId = (string) ($identity['id'] ?? $identity['email'] ?? 'unknown');
        $statement = $this->database->connection()->prepare('INSERT INTO connector_vault (id, encrypted_payload, actor_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$id, $this->cipher->encrypt(['tokens' => $tokens, 'identity' => $identity]), $actorId, $now, $now]);
        $this->audit('vault.created', $id, ['actor' => $actorId]);

        return $id;
    }

    public function vault(string $id): array
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM connector_vault WHERE id = ? AND revoked_at IS NULL');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new \RuntimeException('The site connection is no longer available.');
        }

        return ['id' => $id, 'actorId' => $row['actor_id']] + $this->cipher->decrypt($row['encrypted_payload']);
    }

    public function updateVault(string $id, array $tokens, array $identity): void
    {
        $statement = $this->database->connection()->prepare('UPDATE connector_vault SET encrypted_payload = ?, actor_id = ?, updated_at = ? WHERE id = ? AND revoked_at IS NULL');
        $statement->execute([$this->cipher->encrypt(['tokens' => $tokens, 'identity' => $identity]), (string) ($identity['id'] ?? $identity['email'] ?? 'unknown'), $this->now(), $id]);
        if (1 !== $statement->rowCount()) {
            throw new \RuntimeException('The site connection could not be updated.');
        }
    }

    public function createAuthorizationCode(string $clientId, string $redirectUri, string $challenge, string $vaultId): string
    {
        $code = $this->randomToken(32);
        $statement = $this->database->connection()->prepare('INSERT INTO connector_authorization_code (code_hash, client_id, redirect_uri, challenge, vault_id, expires_at) VALUES (?, ?, ?, ?, ?, ?)');
        $statement->execute([hash('sha256', $code), $clientId, $redirectUri, $challenge, $vaultId, (new \DateTimeImmutable('+5 minutes'))->format(DATE_ATOM)]);

        return $code;
    }

    public function exchangeAuthorizationCode(string $code, string $clientId, string $redirectUri, string $verifier): array
    {
        return $this->database->transaction(function (\PDO $pdo) use ($code, $clientId, $redirectUri, $verifier): array {
            $statement = $pdo->prepare('SELECT * FROM connector_authorization_code WHERE code_hash = ?');
            $statement->execute([hash('sha256', $code)]);
            $row = $statement->fetch();
            if (!is_array($row) || null !== $row['consumed_at'] || $row['expires_at'] <= $this->now()
                || !hash_equals($row['client_id'], $clientId) || !hash_equals($row['redirect_uri'], $redirectUri)
                || !hash_equals($row['challenge'], $this->pkceChallenge($verifier))) {
                throw new \DomainException('The authorization code is invalid or expired.');
            }
            $pdo->prepare('UPDATE connector_authorization_code SET consumed_at = ? WHERE code_hash = ? AND consumed_at IS NULL')->execute([$this->now(), hash('sha256', $code)]);
            $existing = $pdo->prepare('SELECT id, encrypted_secret, created_at FROM connector_pair WHERE client_id = ? AND vault_id = ? AND revoked_at IS NULL');
            $existing->execute([$clientId, $row['vault_id']]);
            $pair = $existing->fetch();
            if (is_array($pair)) {
                $secret = $this->randomToken(32);
                $createdAt = $this->now();
                $pdo->prepare('UPDATE connector_pair SET encrypted_secret = ?, created_at = ? WHERE id = ?')->execute([$this->cipher->encrypt(['secret' => $secret]), $createdAt, $pair['id']]);
                return ['pairId' => $pair['id'], 'secret' => $secret, 'vaultId' => $row['vault_id'], 'createdAt' => $createdAt];
            }
            $pairId = $this->id('pair');
            $secret = $this->randomToken(32);
            $createdAt = $this->now();
            $pdo->prepare('INSERT INTO connector_pair (id, client_id, vault_id, encrypted_secret, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$pairId, $clientId, $row['vault_id'], $this->cipher->encrypt(['secret' => $secret]), $createdAt]);

            return ['pairId' => $pairId, 'secret' => $secret, 'vaultId' => $row['vault_id'], 'createdAt' => $createdAt];
        });
    }

    public function pair(string $pairId): array
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM connector_pair WHERE id = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > ?)');
        $statement->execute([$pairId, $this->now()]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new \DomainException('The connector pair is invalid or revoked.');
        }

        return ['id' => $row['id'], 'clientId' => $row['client_id'], 'vaultId' => $row['vault_id'], 'secret' => $this->cipher->decrypt($row['encrypted_secret'])['secret']];
    }

    public function consumeNonce(string $pairId, string $nonce, int $ttlSeconds): void
    {
        $this->database->transaction(function (\PDO $pdo) use ($pairId, $nonce, $ttlSeconds): void {
            $pdo->prepare('DELETE FROM connector_nonce WHERE expires_at <= ?')->execute([$this->now()]);
            try {
                $pdo->prepare('INSERT INTO connector_nonce (pair_id, nonce, expires_at) VALUES (?, ?, ?)')->execute([$pairId, $nonce, (new \DateTimeImmutable('+'.$ttlSeconds.' seconds'))->format(DATE_ATOM)]);
            } catch (\PDOException $exception) {
                if ('23000' === $exception->getCode()) {
                    throw new \DomainException('This signed request has already been used.');
                }
                throw $exception;
            }
        });
    }

    public function revokePair(string $pairId): void
    {
        $this->database->connection()->prepare('UPDATE connector_pair SET revoked_at = ? WHERE id = ?')->execute([$this->now(), $pairId]);
        $this->audit('pair.revoked', $pairId);
    }

    public function audit(string $event, ?string $subject = null, array $details = [], ?string $correlationId = null): void
    {
        $statement = $this->database->connection()->prepare('INSERT INTO connector_audit (event_type, correlation_id, subject_id, created_at, details) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$event, $correlationId, $subject, $this->now(), json_encode($details, JSON_THROW_ON_ERROR)]);
    }

    private function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function id(string $prefix): string
    {
        return $prefix.'_'.bin2hex(random_bytes(16));
    }

    private function randomToken(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(DATE_ATOM);
    }
}
