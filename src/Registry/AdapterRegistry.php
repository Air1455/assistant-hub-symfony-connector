<?php

namespace AssistantHub\SymfonyConnector\Registry;

use AssistantHub\SymfonyConnector\Contract\SiteCapabilityAdapterInterface;

final class AdapterRegistry
{
    /** @var list<SiteCapabilityAdapterInterface> */
    private array $adapters;

    /** @param iterable<SiteCapabilityAdapterInterface> $adapters */
    public function __construct(iterable $adapters)
    {
        $this->adapters = [...$adapters];
    }

    public function for(string $capabilityId): ?SiteCapabilityAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($capabilityId)) {
                return $adapter;
            }
        }

        return null;
    }
}
