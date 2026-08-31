<?php

namespace AssistantHub\SymfonyConnector\Tests\Controller;

use AssistantHub\SymfonyConnector\Controller\DiscoveryController;
use PHPUnit\Framework\TestCase;

final class DiscoveryControllerTest extends TestCase
{
    public function testItPublishesOnlyMinimalPublicMetadata(): void
    {
        $response = (new DiscoveryController('example-site', 'Example Site', ['demo'], true))->discover();
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('assistant-hub-connector-discovery', $payload['schema']);
        self::assertSame('1.0', $payload['protocolVersion']);
        self::assertSame(['id' => 'example-site', 'name' => 'Example Site'], $payload['connector']);
        self::assertSame('/assistant-hub', $payload['api']['basePath']);
        self::assertSame(['demo'], $payload['pairing']['modes']);
        self::assertArrayNotHasKey('capabilities', $payload);
    }

    public function testItDoesNotAdvertiseDemoPairingOutsideDemoMode(): void
    {
        $response = (new DiscoveryController('example-site', 'Example Site', ['demo'], false))->discover();
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $payload['pairing']['modes']);
    }
}
