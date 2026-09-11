<?php

namespace AssistantHub\SymfonyConnector\Tests\Protocol;

use AssistantHub\SymfonyConnector\Protocol\CanonicalJson;
use AssistantHub\SymfonyConnector\Protocol\CapabilityDefinition;
use AssistantHub\SymfonyConnector\Protocol\Proposal;
use PHPUnit\Framework\TestCase;

final class ProposalTest extends TestCase
{
    public function testFingerprintCoversEveryImmutableField(): void
    {
        $capability = new CapabilityDefinition('example.write', '1.0.0', 'write', 'Write', 'Example', [], [], true);
        $proposal = Proposal::create('pair_test', $capability, ['title' => 'Exact'], 'Create Exact.', 600);
        $data = $proposal->toArray();
        $fingerprint = $data['fingerprint'];
        unset($data['fingerprint']);

        self::assertSame(hash('sha256', CanonicalJson::encode($data)), $fingerprint);

        $data['input']['title'] = 'Modified';
        self::assertNotSame(hash('sha256', CanonicalJson::encode($data)), $fingerprint);
    }

    public function testPreparedPreviewIsCoveredByTheFingerprintAndSurvivesStorage(): void
    {
        $capability = new CapabilityDefinition('example.write', '1.0.0', 'write', 'Write', 'Example', [], [], true);
        $proposal = Proposal::create('pair_test', $capability, ['resolved' => 7], 'Update.', 600,
            [['label' => 'Record', 'before' => 'Before', 'after' => 'After']], ['Original preserved.']);
        self::assertSame($proposal->toArray(), Proposal::fromArray($proposal->toArray())->toArray());
        $data = $proposal->toArray();
        unset($data['fingerprint']);
        self::assertSame($proposal->fingerprint, hash('sha256', CanonicalJson::encode($data)));
        $data['changes'][0]['after'] = 'Different';
        self::assertNotSame($proposal->fingerprint, hash('sha256', CanonicalJson::encode($data)));
    }

    public function testPreviewRejectsUnboundedOrStructuredValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new \AssistantHub\SymfonyConnector\Protocol\PreparedAction(['id' => 1], 'Update',
            [['label' => 'Record', 'before' => null, 'after' => ['html' => '<script>']]] );
    }
}
