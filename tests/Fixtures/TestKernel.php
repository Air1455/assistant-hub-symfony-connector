<?php

namespace AssistantHub\SymfonyConnector\Tests\Fixtures;

use AssistantHub\SymfonyConnector\AssistantHubConnectorBundle;
use AssistantHub\SymfonyConnector\Registry\AdapterRegistry;
use AssistantHub\SymfonyConnector\Registry\CapabilityRegistry;
use AssistantHub\SymfonyConnector\Service\ConnectorService;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new AssistantHubConnectorBundle()];
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $framework = [
            'secret' => 'connector-test-secret',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ];
        $container->extension('framework', $framework);
        $container->extension('assistant_hub_connector', [
            'connector_id' => 'test-site',
            'connector_name' => 'Site de test',
            'encryption_key' => 'connector-test-encryption-key-32-bytes',
            'api_base_url' => 'http://site.test',
            'allowed_hub_redirect_uris' => ['http://hub.test/sites/callback'],
            'pairing_modes' => ['demo'],
            'demo_mode' => true,
            'demo_pair_key' => 'connector-test-key',
            'proposal_ttl_seconds' => 600,
        ]);

        $services = $container->services();
        $services->set(TestSiteCapabilityAdapter::class)->autowire()->autoconfigure();
        $services->set(TestSiteCapability::class)->autowire()->autoconfigure();
        $services->alias('test.connector_service', ConnectorService::class)->public();
        $services->alias('test.capability_registry', CapabilityRegistry::class)->public();
        $services->alias('test.adapter_registry', AdapterRegistry::class)->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(dirname(__DIR__, 2).'/config/routes.yaml');
    }
}
