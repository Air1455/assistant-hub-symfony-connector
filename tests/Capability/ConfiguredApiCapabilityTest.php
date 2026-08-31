<?php

namespace AssistantHub\SymfonyConnector\Tests\Capability;

use AssistantHub\SymfonyConnector\Capability\ConfiguredApiCapability;
use AssistantHub\SymfonyConnector\Registry\AdapterRegistry;
use AssistantHub\SymfonyConnector\Security\SecretCipher;
use AssistantHub\SymfonyConnector\Service\SiteApiClient;
use AssistantHub\SymfonyConnector\Storage\ConnectorDatabase;
use AssistantHub\SymfonyConnector\Store\ConnectorStore;
use AssistantHub\SymfonyConnector\Validation\JsonSchemaValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class ConfiguredApiCapabilityTest extends TestCase
{
    public function testAReadCapabilityCannotUseAWriteMethod(): void
    {
        [$api, $path] = $this->api();
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('read capability must use GET');
            new ConfiguredApiCapability($api, new JsonSchemaValidator(), [
                'id' => 'site.record.delete',
                'kind' => 'read',
                'method' => 'DELETE',
                'path' => '/api/records/1',
            ]);
        } finally {
            $this->cleanup($path);
        }
    }

    public function testAGetCapabilityCannotUseTheWriteChannel(): void
    {
        [$api, $path] = $this->api();
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('GET capability cannot be declared as a write');
            new ConfiguredApiCapability($api, new JsonSchemaValidator(), [
                'id' => 'site.record.list',
                'kind' => 'write',
                'method' => 'GET',
                'path' => '/api/records',
            ]);
        } finally {
            $this->cleanup($path);
        }
    }

    public function testAPathPlaceholderMustBeARequiredDeclaredInput(): void
    {
        [$api, $path] = $this->api();
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Path placeholder "recordId" must be a declared required input property');
            new ConfiguredApiCapability($api, new JsonSchemaValidator(), [
                'id' => 'site.record.update',
                'kind' => 'write',
                'method' => 'PATCH',
                'path' => '/api/records/{recordId}',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['recordId' => ['type' => 'integer']],
                    'additionalProperties' => false,
                ],
            ]);
        } finally {
            $this->cleanup($path);
        }
    }

    /** @return array{SiteApiClient, string} */
    private function api(): array
    {
        $path = sys_get_temp_dir().'/configured-capability-'.bin2hex(random_bytes(8)).'.sqlite';
        $store = new ConnectorStore(new ConnectorDatabase($path), new SecretCipher(str_repeat('k', 32)));

        return [new SiteApiClient(new MockHttpClient(), $store, new AdapterRegistry([]), 'https://api.example.test', []), $path];
    }

    private function cleanup(string $path): void
    {
        @unlink($path);
        @unlink($path.'-wal');
        @unlink($path.'-shm');
    }
}
