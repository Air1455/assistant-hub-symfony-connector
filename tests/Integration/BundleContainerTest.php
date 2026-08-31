<?php

namespace AssistantHub\SymfonyConnector\Tests\Integration;

use AssistantHub\SymfonyConnector\Service\ConnectorService;
use AssistantHub\SymfonyConnector\Tests\Fixtures\TestKernel;
use AssistantHub\SymfonyConnector\Tests\Fixtures\TestSiteCapabilityAdapter;
use PHPUnit\Framework\TestCase;

final class BundleContainerTest extends TestCase
{
    public function testBundleCompilesAndRegistersDemoCapabilitiesOnlyInHostApplication(): void
    {
        $kernel = new TestKernel('test', true);

        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');

            self::assertInstanceOf(ConnectorService::class, $container->get('test.connector_service'));
            $catalog = $container->get('test.capability_registry')->catalog();
            self::assertCount(3, $catalog);
            self::assertContains('test.site.custom', array_column($catalog, 'id'));
            self::assertInstanceOf(
                TestSiteCapabilityAdapter::class,
                $container->get('test.adapter_registry')->for('test.autoconfigured'),
            );
        } finally {
            $kernel->shutdown();
        }
    }
}
