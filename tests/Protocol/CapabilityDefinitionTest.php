<?php

namespace AssistantHub\SymfonyConnector\Tests\Protocol;

use AssistantHub\SymfonyConnector\Protocol\CapabilityDefinition;
use PHPUnit\Framework\TestCase;

final class CapabilityDefinitionTest extends TestCase
{
    public function testFormAnnotationsAreTransportedWithoutDomainKnowledge(): void
    {
        $schema = ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'reference' => ['type' => 'integer', 'title' => 'Client', 'x-hub-choices' => ['capability' => 'example.customer.choices']],
            'email' => ['type' => 'string', 'format' => 'email'],
        ], 'required' => ['reference', 'email']];
        $definition = new CapabilityDefinition('example.customer.update', '1.0', 'write', 'Corriger un client', 'Email uniquement.', $schema, [], true);
        self::assertSame($schema, $definition->toArray()['inputSchema']);
        self::assertTrue($definition->requiresConfirmation);
    }

    public function testWriteCapabilityCannotDisableConfirmation(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CapabilityDefinition('example.write', '1.0.0', 'write', 'Write', 'Unsafe definition', [], [], false);
    }

    public function testReadCapabilityCannotUseWriteConfirmationChannel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CapabilityDefinition('example.read', '1.0.0', 'read', 'Read', 'Invalid definition', [], [], true);
    }
}
