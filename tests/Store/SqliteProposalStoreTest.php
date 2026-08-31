<?php

namespace AssistantHub\SymfonyConnector\Tests\Store;

use AssistantHub\SymfonyConnector\Protocol\CapabilityDefinition;
use AssistantHub\SymfonyConnector\Protocol\ExecutionResult;
use AssistantHub\SymfonyConnector\Protocol\Proposal;
use AssistantHub\SymfonyConnector\Protocol\ProposalExecutionReservation;
use AssistantHub\SymfonyConnector\Storage\ConnectorDatabase;
use AssistantHub\SymfonyConnector\Store\SqliteProposalStore;
use PHPUnit\Framework\TestCase;

final class SqliteProposalStoreTest extends TestCase
{
    public function testReservationIsAtomicAndCompletedResultIsIdempotent(): void
    {
        [$store, $path] = $this->store();
        try {
            $proposal = $this->proposal();
            $store->save($proposal);

            $first = $store->reserve($proposal->id);
            self::assertSame(ProposalExecutionReservation::RESERVED, $first->status);

            $concurrent = $store->reserve($proposal->id);
            self::assertSame(ProposalExecutionReservation::EXECUTING, $concurrent->status);
            self::assertSame($first->idempotencyKey, $concurrent->idempotencyKey);

            $result = new ExecutionResult('example.write', ['ok' => true], $first->idempotencyKey);
            $store->complete($proposal->id, $result);

            $replayed = $store->reserve($proposal->id);
            self::assertSame(ProposalExecutionReservation::COMPLETED, $replayed->status);
            self::assertSame($result->toArray(), $replayed->completedResult);
        } finally {
            $this->cleanup($path);
        }
    }

    public function testFailureIsDurableAndCannotReturnToPending(): void
    {
        [$store, $path] = $this->store();
        try {
            $proposal = $this->proposal();
            $store->save($proposal);
            $store->reserve($proposal->id);
            $store->fail($proposal->id, 'SITE_API_UNAVAILABLE');

            $failed = $store->reserve($proposal->id);
            self::assertSame(ProposalExecutionReservation::FAILED, $failed->status);
            self::assertSame('SITE_API_UNAVAILABLE', $failed->failureCode);
        } finally {
            $this->cleanup($path);
        }
    }

    /** @return array{SqliteProposalStore, string} */
    private function store(): array
    {
        $path = sys_get_temp_dir().'/proposal-store-'.bin2hex(random_bytes(8)).'.sqlite';

        return [new SqliteProposalStore(new ConnectorDatabase($path)), $path];
    }

    private function proposal(): Proposal
    {
        return Proposal::create('pair_test', new CapabilityDefinition('example.write', '1.0', 'write', 'Write', 'Test', [], [], true), ['value' => 'x'], 'Write x', 600);
    }

    private function cleanup(string $path): void
    {
        @unlink($path);
        @unlink($path.'-wal');
        @unlink($path.'-shm');
    }
}
