<?php

namespace AssistantHub\SymfonyConnector\Store;

use AssistantHub\SymfonyConnector\Contract\ProposalStoreInterface;
use AssistantHub\SymfonyConnector\Protocol\ExecutionResult;
use AssistantHub\SymfonyConnector\Protocol\Proposal;
use AssistantHub\SymfonyConnector\Protocol\ProposalExecutionReservation;
use AssistantHub\SymfonyConnector\Storage\ConnectorDatabase;

final readonly class SqliteProposalStore implements ProposalStoreInterface
{
    public function __construct(private ConnectorDatabase $database)
    {
    }

    public function save(Proposal $proposal): void
    {
        $statement = $this->database->connection()->prepare('INSERT INTO connector_proposal (id, payload, expires_at, state) VALUES (?, ?, ?, ?)');
        $statement->execute([$proposal->id, json_encode($proposal->toArray(), JSON_THROW_ON_ERROR), $proposal->expiresAt->format(DATE_ATOM), 'pending']);
    }

    public function find(string $proposalId): ?Proposal
    {
        $statement = $this->database->connection()->prepare('SELECT payload FROM connector_proposal WHERE id = ?');
        $statement->execute([$proposalId]);
        $payload = $statement->fetchColumn();

        return is_string($payload) ? Proposal::fromArray(json_decode($payload, true, 32, JSON_THROW_ON_ERROR)) : null;
    }

    public function reserve(string $proposalId): ProposalExecutionReservation
    {
        return $this->database->transaction(function (\PDO $pdo) use ($proposalId): ProposalExecutionReservation {
            $statement = $pdo->prepare('SELECT payload, state, execution_key, result, failure_code FROM connector_proposal WHERE id = ?');
            $statement->execute([$proposalId]);
            $row = $statement->fetch();
            if (!is_array($row)) {
                throw new \DomainException('The proposal is unknown.');
            }
            $proposal = Proposal::fromArray(json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR));
            $key = is_string($row['execution_key']) && '' !== $row['execution_key']
                ? $row['execution_key']
                : 'write_'.hash('sha256', $proposalId);

            if ('completed' === $row['state']) {
                $result = json_decode((string) $row['result'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($result)) {
                    throw new \RuntimeException('The completed proposal result is corrupted.');
                }

                return new ProposalExecutionReservation($proposal, ProposalExecutionReservation::COMPLETED, $key, $result);
            }
            if ('executing' === $row['state']) {
                return new ProposalExecutionReservation($proposal, ProposalExecutionReservation::EXECUTING, $key);
            }
            if ('failed' === $row['state']) {
                return new ProposalExecutionReservation($proposal, ProposalExecutionReservation::FAILED, $key, failureCode: is_string($row['failure_code']) ? $row['failure_code'] : 'EXECUTION_FAILED');
            }

            $update = $pdo->prepare("UPDATE connector_proposal SET state = 'executing', execution_key = ?, reserved_at = ? WHERE id = ? AND state = 'pending'");
            $update->execute([$key, (new \DateTimeImmutable())->format(DATE_ATOM), $proposalId]);
            if (1 !== $update->rowCount()) {
                throw new \RuntimeException('The proposal reservation lost its atomic update.');
            }

            return new ProposalExecutionReservation($proposal, ProposalExecutionReservation::RESERVED, $key);
        });
    }

    public function complete(string $proposalId, ExecutionResult $result): void
    {
        $this->database->transaction(function (\PDO $pdo) use ($proposalId, $result): void {
            $statement = $pdo->prepare("UPDATE connector_proposal SET state = 'completed', result = ?, completed_at = ? WHERE id = ? AND state = 'executing'");
            $statement->execute([json_encode($result->toArray(), JSON_THROW_ON_ERROR), (new \DateTimeImmutable())->format(DATE_ATOM), $proposalId]);
            if (1 !== $statement->rowCount()) {
                throw new \DomainException('The proposal is not reserved for completion.');
            }
        });
    }

    public function fail(string $proposalId, string $failureCode): void
    {
        $statement = $this->database->connection()->prepare("UPDATE connector_proposal SET state = 'failed', failure_code = ?, completed_at = ? WHERE id = ? AND state IN ('pending', 'executing')");
        $statement->execute([$failureCode, (new \DateTimeImmutable())->format(DATE_ATOM), $proposalId]);
    }
}
