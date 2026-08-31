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
}
