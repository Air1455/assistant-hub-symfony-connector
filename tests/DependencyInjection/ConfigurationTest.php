<?php

namespace AssistantHub\SymfonyConnector\Tests\DependencyInjection;

use AssistantHub\SymfonyConnector\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testSecuritySensitiveConfigurationHasNoDevelopmentFallback(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[]]);
    }

    public function testExplicitSecurityConfigurationIsAccepted(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'encryption_key' => 'explicit-test-encryption-key-32-bytes',
            'api_base_url' => 'https://api.example.test',
            'allowed_hub_redirect_uris' => ['https://hub.example.test/sites/callback'],
        ]]);

        self::assertSame('explicit-test-encryption-key-32-bytes', $config['encryption_key']);
        self::assertSame('https://api.example.test', $config['api_base_url']);
        self::assertSame(['https://hub.example.test/sites/callback'], $config['allowed_hub_redirect_uris']);
    }
}
