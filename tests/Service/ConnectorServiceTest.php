<?php

namespace AssistantHub\SymfonyConnector\Tests\Service;

use AssistantHub\SymfonyConnector\Contract\CapabilityInterface;
use AssistantHub\SymfonyConnector\Contract\LocalAuthorizationInterface;
use AssistantHub\SymfonyConnector\Contract\PairAuthenticatorInterface;
use AssistantHub\SymfonyConnector\Protocol\CapabilityDefinition;
use AssistantHub\SymfonyConnector\Protocol\Confirmation;
use AssistantHub\SymfonyConnector\Protocol\LocalContext;
use AssistantHub\SymfonyConnector\Protocol\PairIdentity;
use AssistantHub\SymfonyConnector\Protocol\ProtocolException;
use AssistantHub\SymfonyConnector\Registry\CapabilityRegistry;
use AssistantHub\SymfonyConnector\Service\ConnectorService;
use AssistantHub\SymfonyConnector\Storage\ConnectorDatabase;
use AssistantHub\SymfonyConnector\Store\SqliteProposalStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ConnectorServiceTest extends TestCase
{
    public function testConfirmedExecutionRunsOnlyOnceAndReturnsStoredResultOnReplay(): void
    {
        $capability = new CountingWriteCapability();
        [$service, $path] = $this->service($capability);
        try {
            $proposal = $service->prepareProposal('example.item.create', ['name' => 'Exact'], new Request());
            $confirmation = new Confirmation($proposal['id'], $proposal['fingerprint'], 'hub-user', new \DateTimeImmutable());

            $first = $service->executeConfirmed('example.item.create', $confirmation, new Request());
            $second = $service->executeConfirmed('example.item.create', $confirmation, new Request());

            self::assertSame(1, $capability->executions);
            self::assertSame($first, $second);
            self::assertStringStartsWith('write_', $first['idempotencyKey']);
            self::assertSame($first['idempotencyKey'], $capability->lastIdempotencyKey);
        } finally {
            $this->cleanup($path);
        }
    }

    public function testFailedExecutionIsNotAutomaticallyReplayed(): void
    {
        $capability = new CountingWriteCapability(true);
        [$service, $path] = $this->service($capability);
        try {
            $proposal = $service->prepareProposal('example.item.create', ['name' => 'Exact'], new Request());
            $confirmation = new Confirmation($proposal['id'], $proposal['fingerprint'], 'hub-user', new \DateTimeImmutable());
            try {
                $service->executeConfirmed('example.item.create', $confirmation, new Request());
                self::fail('The first execution must fail.');
            } catch (ProtocolException $exception) {
                self::assertSame('EXECUTION_FAILED', $exception->protocolCode);
            }

            try {
                $service->executeConfirmed('example.item.create', $confirmation, new Request());
                self::fail('A failed execution must not be replayed.');
            } catch (ProtocolException $exception) {
                self::assertSame('EXECUTION_FAILED', $exception->protocolCode);
            }
            self::assertSame(1, $capability->executions);
        } finally {
            $this->cleanup($path);
        }
    }

    /** @return array{ConnectorService, string} */
    private function service(CountingWriteCapability $capability): array
    {
        $path = sys_get_temp_dir().'/connector-service-'.bin2hex(random_bytes(8)).'.sqlite';
        $store = new SqliteProposalStore(new ConnectorDatabase($path));
        $pairAuthenticator = new class implements PairAuthenticatorInterface {
            public function authenticate(Request $request): PairIdentity
            {
                return new PairIdentity('pair_test', 'actor_test', 'hub_test');
            }
        };
        $authorization = new class implements LocalAuthorizationInterface {
            public function authorize(PairIdentity $pair, CapabilityDefinition $capability, array $input): LocalContext
            {
                return new LocalContext('actor_test', ['ROLE_USER'], 'decision_test', $pair->pairId);
            }
        };

        return [new ConnectorService(new CapabilityRegistry([$capability]), $pairAuthenticator, $authorization, $store, 600), $path];
    }

    private function cleanup(string $path): void
    {
        @unlink($path);
        @unlink($path.'-wal');
        @unlink($path.'-shm');
    }
}

final class CountingWriteCapability implements CapabilityInterface
{
    public int $executions = 0;
    public ?string $lastIdempotencyKey = null;

    public function __construct(private readonly bool $fail = false)
    {
    }

    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition('example.item.create', '1.0', 'write', 'Create item', 'Test write', [], [], true);
    }

    public function normalizeInput(array $input): array
    {
        return ['name' => (string) ($input['name'] ?? '')];
    }

    public function preview(array $input, LocalContext $context): string
    {
        return 'Create '.$input['name'];
    }

    public function execute(array $input, LocalContext $context): array
    {
        ++$this->executions;
        $this->lastIdempotencyKey = $context->idempotencyKey;
        if ($this->fail) {
            throw new \RuntimeException('Simulated failure.');
        }

        return ['name' => $input['name']];
    }
}
