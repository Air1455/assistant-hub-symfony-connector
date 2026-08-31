<?php

namespace AssistantHub\SymfonyConnector\Contract;

interface SiteCapabilityAdapterInterface
{
    public function supports(string $capabilityId): bool;

    /** @param array<string, mixed> $config @param array<string, mixed> $input @return array<string, mixed> */
    public function buildRequest(array $config, array $input): array;

    /** @param array<string, mixed> $config @return array<string, mixed> */
    public function normalizeResponse(array $config, mixed $payload): array;
}
