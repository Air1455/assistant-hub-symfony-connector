<?php

namespace AssistantHub\SymfonyConnector\Storage;

final class ConnectorDatabase
{
    private ?\PDO $pdo = null;

    public function __construct(private readonly string $path)
    {
    }

    public function connection(): \PDO
    {
        if (null !== $this->pdo) {
            return $this->pdo;
        }
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the connector storage directory.');
        }
        $this->pdo = new \PDO('sqlite:'.$this->path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->initialize($this->pdo);

        return $this->pdo;
    }

    public function transaction(callable $operation): mixed
    {
        $pdo = $this->connection();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $operation($pdo);
            $pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $exception) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable) {}
            throw $exception;
        }
    }

    private function initialize(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS connector_vault (
    id TEXT PRIMARY KEY,
    encrypted_payload TEXT NOT NULL,
    actor_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    revoked_at TEXT NULL
);
CREATE TABLE IF NOT EXISTS connector_authorization_code (
    code_hash TEXT PRIMARY KEY,
    client_id TEXT NOT NULL,
    redirect_uri TEXT NOT NULL,
    challenge TEXT NOT NULL,
    vault_id TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    consumed_at TEXT NULL,
    FOREIGN KEY(vault_id) REFERENCES connector_vault(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS connector_pair (
    id TEXT PRIMARY KEY,
    client_id TEXT NOT NULL,
    vault_id TEXT NOT NULL,
    encrypted_secret TEXT NOT NULL,
    created_at TEXT NOT NULL,
    expires_at TEXT NULL,
    revoked_at TEXT NULL,
    FOREIGN KEY(vault_id) REFERENCES connector_vault(id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS connector_pair_client_vault ON connector_pair(client_id, vault_id);
CREATE TABLE IF NOT EXISTS connector_nonce (
    pair_id TEXT NOT NULL,
    nonce TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    PRIMARY KEY(pair_id, nonce),
    FOREIGN KEY(pair_id) REFERENCES connector_pair(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS connector_proposal (
    id TEXT PRIMARY KEY,
    payload TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    consumed_at TEXT NULL,
    state TEXT NOT NULL DEFAULT 'pending',
    execution_key TEXT NULL,
    result TEXT NULL,
    failure_code TEXT NULL,
    reserved_at TEXT NULL,
    completed_at TEXT NULL
);
CREATE TABLE IF NOT EXISTS connector_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type TEXT NOT NULL,
    correlation_id TEXT NULL,
    subject_id TEXT NULL,
    created_at TEXT NOT NULL,
    details TEXT NOT NULL
);
SQL);

        // Mise à niveau incrémentale des SQLite créés par les premières fondations.
        $this->ensureColumn($pdo, 'connector_proposal', 'state', "TEXT NOT NULL DEFAULT 'pending'");
        $this->ensureColumn($pdo, 'connector_proposal', 'execution_key', 'TEXT NULL');
        $this->ensureColumn($pdo, 'connector_proposal', 'result', 'TEXT NULL');
        $this->ensureColumn($pdo, 'connector_proposal', 'failure_code', 'TEXT NULL');
        $this->ensureColumn($pdo, 'connector_proposal', 'reserved_at', 'TEXT NULL');
        $this->ensureColumn($pdo, 'connector_proposal', 'completed_at', 'TEXT NULL');
    }

    private function ensureColumn(\PDO $pdo, string $table, string $column, string $definition): void
    {
        $columns = $pdo->query('PRAGMA table_info('.$table.')')->fetchAll();
        foreach ($columns as $existing) {
            if (($existing['name'] ?? null) === $column) {
                return;
            }
        }
        $pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }
}
