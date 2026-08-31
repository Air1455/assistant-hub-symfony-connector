<?php

namespace AssistantHub\SymfonyConnector\Tests\Fixtures;

use AssistantHub\SymfonyConnector\Contract\CapabilityInterface;
use AssistantHub\SymfonyConnector\Protocol\CapabilityDefinition;
use AssistantHub\SymfonyConnector\Protocol\LocalContext;

final class TestSiteCapability implements CapabilityInterface
{
    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            'test.site.custom',
            '1.0',
            'read',
            'Custom site capability',
            'Capability implemented by the host application.',
            ['type' => 'object', 'additionalProperties' => false],
            ['type' => 'object', 'properties' => []],
            false,
        );
    }

    public function normalizeInput(array $input): array
    {
        return $input;
    }

    public function preview(array $input, LocalContext $context): string
    {
        return 'Custom site preview.';
    }

    public function execute(array $input, LocalContext $context): array
    {
        return [];
    }
}
