<?php

namespace AssistantHub\SymfonyConnector\Tests\Protocol;

use AssistantHub\SymfonyConnector\Protocol\CapabilityDefinition;
use PHPUnit\Framework\TestCase;

final class CapabilityDefinitionTest extends TestCase
{
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
