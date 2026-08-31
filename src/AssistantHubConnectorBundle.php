<?php

namespace AssistantHub\SymfonyConnector;

use AssistantHub\SymfonyConnector\Contract\CapabilityInterface;
use AssistantHub\SymfonyConnector\Contract\SiteCapabilityAdapterInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class AssistantHubConnectorBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerForAutoconfiguration(SiteCapabilityAdapterInterface::class)
            ->addTag('assistant_hub_connector.site_adapter');

        $container->registerForAutoconfiguration(CapabilityInterface::class)
            ->addTag('assistant_hub_connector.capability');
    }

    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}
