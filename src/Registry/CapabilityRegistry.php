<?php

namespace AssistantHub\SymfonyConnector\Registry;

use AssistantHub\SymfonyConnector\Contract\CapabilityInterface;
use AssistantHub\SymfonyConnector\Protocol\ProtocolException;

final class CapabilityRegistry
{
    /** @var array<string, CapabilityInterface> */
    private array $capabilities = [];

    /** @param iterable<CapabilityInterface> $capabilities */
    public function __construct(iterable $capabilities)
    {
        foreach ($capabilities as $capability) {
            $id = $capability->definition()->id;
            if (isset($this->capabilities[$id])) {
                throw new \LogicException(sprintf('Duplicate Assistant Hub capability: %s.', $id));
            }
            $this->capabilities[$id] = $capability;
        }
    }

    public function get(string $capabilityId): CapabilityInterface
    {
        return $this->capabilities[$capabilityId] ?? throw new ProtocolException(
            'CAPABILITY_NOT_FOUND',
            sprintf('Capability "%s" is not declared by this connector.', $capabilityId),
            404,
        );
    }

    /** @return list<array<string, mixed>> */
    public function catalog(): array
    {
        return array_values(array_map(
            static fn (CapabilityInterface $capability): array => $capability->definition()->toArray(),
            $this->capabilities,
        ));
    }

    /** @return list<CapabilityInterface> */
    public function all(): array
    {
        return array_values($this->capabilities);
    }
}
