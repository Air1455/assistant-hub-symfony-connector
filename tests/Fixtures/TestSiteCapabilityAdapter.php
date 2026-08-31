<?php

namespace AssistantHub\SymfonyConnector\Tests\Fixtures;

use AssistantHub\SymfonyConnector\Contract\SiteCapabilityAdapterInterface;

final class TestSiteCapabilityAdapter implements SiteCapabilityAdapterInterface
{
    public function supports(string $capabilityId): bool
    {
        return 'test.autoconfigured' === $capabilityId;
    }

    public function buildRequest(array $config, array $input): array
    {
        return ['query' => $input];
    }

    public function normalizeResponse(array $config, mixed $payload): array
    {
        return is_array($payload) ? $payload : [];
    }
}
