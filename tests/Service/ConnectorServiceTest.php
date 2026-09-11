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

    public function testPreparedActionFreezesTheSiteInputAndExactPreviewUntilConfirmation(): void
    {
        $capability = new PreparedTestCapability();
        [$service, $path] = $this->service($capability);
        try {
            $proposal = $service->prepareProposal('example.prepared', ['reference' => 7], new Request());
            self::assertSame(0, $capability->executions);
            self::assertSame(1, $capability->preparations);
            self::assertSame(['resolved' => 7, 'version' => 12], $proposal['input']);
            self::assertSame('Before', $proposal['changes'][0]['before']);
            self::assertSame('After', $proposal['changes'][0]['after']);
            $confirmation = new Confirmation($proposal['id'], $proposal['fingerprint'], 'hub-user', new \DateTimeImmutable());
            $first = $service->executeConfirmed('example.prepared', $confirmation, new Request());
            self::assertSame($first, $service->executeConfirmed('example.prepared', $confirmation, new Request()));
            self::assertSame(1, $capability->executions);
            self::assertSame(1, $capability->preparations, 'Confirmation must not regenerate the prepared inputs.');
            self::assertSame($proposal['input'], $capability->executedInput);
        } finally {
            $this->cleanup($path);
        }
    }

    public function testLegacyProposalKeepsItsExactEnvelopeWithoutOptionalPreviewFields(): void
    {
        [$service, $path] = $this->service(new CountingWriteCapability());
        try {
            $proposal = $service->prepareProposal('example.item.create', ['name' => 'Legacy'], new Request());
            self::assertArrayNotHasKey('changes', $proposal);
            self::assertArrayNotHasKey('notices', $proposal);
            self::assertSame(['name' => 'Legacy'], $proposal['input']);
            self::assertSame('Create Legacy', $proposal['summary']);
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

    public function testAPhpCapabilityCannotReturnDataOutsideItsDeclaredSchema(): void
    {
        [$service, $path] = $this->service(new InvalidReadCapability());
        try {
            $this->expectException(ProtocolException::class);
            $this->expectExceptionMessage('sortie non conforme');

            $service->executeRead('example.invalid.read', [], new Request());
        } finally {
            $this->cleanup($path);
        }
    }

    /** @return array{ConnectorService, string} */
    private function service(CapabilityInterface $capability): array
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
        return new CapabilityDefinition(
            'example.item.create',
            '1.0',
            'write',
            'Create item',
            'Test write',
            [],
            [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'required' => ['name'],
            ],
            true,
        );
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

final class InvalidReadCapability implements CapabilityInterface
{
    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            'example.invalid.read',
            '1.0',
            'read',
            'Invalid read',
            'Test invalid output',
            ['type' => 'object', 'additionalProperties' => false],
            [
                'type' => 'object',
                'properties' => ['expected' => ['type' => 'string']],
                'required' => ['expected'],
            ],
            false,
        );
    }

    public function normalizeInput(array $input): array
    {
        return [];
    }

    public function preview(array $input, LocalContext $context): string
    {
        return 'Invalid read.';
    }

    public function execute(array $input, LocalContext $context): array
    {
        return ['unexpected' => true];
    }
}

final class PreparedTestCapability implements \AssistantHub\SymfonyConnector\Contract\PreparedCapabilityInterface
{
    public int $executions = 0;
    public int $preparations = 0;
    public array $executedInput = [];
    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition('example.prepared', '1.0', 'write', 'Prepared', 'Test',
            ['type' => 'object', 'properties' => ['reference' => ['type' => 'integer']]],
            ['type' => 'object', 'properties' => ['done' => ['type' => 'boolean']], 'required' => ['done']], true);
    }
    public function normalizeInput(array $input): array { return ['reference' => $input['reference']]; }
    public function preview(array $input, LocalContext $context): string { throw new \LogicException('Legacy preview must not run.'); }
    public function prepare(array $input, LocalContext $context): \AssistantHub\SymfonyConnector\Protocol\PreparedAction
    {
        ++$this->preparations;
        return new \AssistantHub\SymfonyConnector\Protocol\PreparedAction(
            ['resolved' => $input['reference'], 'version' => 12], 'Update one item',
            [['label' => 'Item', 'before' => 'Before', 'after' => 'After']], ['Original retained.']);
    }
    public function execute(array $input, LocalContext $context): array
    {
        ++$this->executions;
        $this->executedInput = $input;
        return ['done' => true];
    }
}
