<?php

namespace AssistantHub\SymfonyConnector\Tests\Service;

use AssistantHub\SymfonyConnector\Registry\AdapterRegistry;
use AssistantHub\SymfonyConnector\Security\SecretCipher;
use AssistantHub\SymfonyConnector\Service\SiteApiClient;
use AssistantHub\SymfonyConnector\Storage\ConnectorDatabase;
use AssistantHub\SymfonyConnector\Store\ConnectorStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SiteApiClientTest extends TestCase
{
    public function testConfirmedWriteForwardsTheStableIdempotencyKey(): void
    {
        $path = sys_get_temp_dir().'/site-api-'.bin2hex(random_bytes(8)).'.sqlite';
        try {
            $store = new ConnectorStore(new ConnectorDatabase($path), new SecretCipher(str_repeat('k', 32)));
            $vault = $store->createVault(['access_token' => 'access-token', 'refresh_token' => null, 'expires_at' => time() + 3600], ['id' => 'actor-1', 'roles' => ['ROLE_USER']]);
            $verifier = str_repeat('v', 64);
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $code = $store->createAuthorizationCode('hub-1', 'https://hub.test/callback', $challenge, $vault);
            $pair = $store->exchangeAuthorizationCode($code, 'hub-1', 'https://hub.test/callback', $verifier);

            $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
                self::assertSame('PATCH', $method);
                self::assertSame('https://api.example.test/items/customer%2F42', $url);
                self::assertSame('Idempotency-Key: write_stable_key', $options['normalized_headers']['idempotency-key'][0]);
                self::assertSame('Authorization: Bearer access-token', $options['normalized_headers']['authorization'][0]);
                self::assertSame('Accept: application/ld+json', $options['normalized_headers']['accept'][0]);
                self::assertSame('Content-Type: application/merge-patch+json', $options['normalized_headers']['content-type'][0]);

                return new MockResponse('{"id":"item-1"}');
            });
            $client = new SiteApiClient($http, $store, new AdapterRegistry([]), 'https://api.example.test', [
                'login_path' => '/login',
                'refresh_path' => '/refresh',
                'refresh_token_field' => 'refresh_token',
                'access_token_field' => 'token',
            ]);

            $result = $client->execute($pair['pairId'], [
                'id' => 'example.item.update',
                'kind' => 'write',
                'method' => 'PATCH',
                'path' => '/items/{itemId}',
                'input_mapping' => ['name' => 'name'],
                'accept' => 'application/ld+json',
                'content_type' => 'application/merge-patch+json',
            ], ['itemId' => 'customer/42', 'name' => 'Exact'], 'write_stable_key');

            self::assertSame(['id' => 'item-1'], $result);
        } finally {
            @unlink($path);
            @unlink($path.'-wal');
            @unlink($path.'-shm');
        }
    }

    public function testAWriteRejectsANonJsonContentTypeBeforeCallingTheApi(): void
    {
        $path = sys_get_temp_dir().'/site-api-'.bin2hex(random_bytes(8)).'.sqlite';
        try {
            $store = new ConnectorStore(new ConnectorDatabase($path), new SecretCipher(str_repeat('k', 32)));
            $vault = $store->createVault(
                ['access_token' => 'access-token', 'refresh_token' => null, 'expires_at' => time() + 3600],
                ['id' => 'actor-1', 'roles' => ['ROLE_USER']],
            );
            $verifier = str_repeat('v', 64);
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $code = $store->createAuthorizationCode('hub-1', 'https://hub.test/callback', $challenge, $vault);
            $pair = $store->exchangeAuthorizationCode($code, 'hub-1', 'https://hub.test/callback', $verifier);
            $http = new MockHttpClient(static function (): never {
                self::fail('The site API must not be called with a non-JSON Content-Type.');
            });
            $client = new SiteApiClient($http, $store, new AdapterRegistry([]), 'https://api.example.test', [
                'login_path' => '/login',
                'refresh_path' => '/refresh',
                'refresh_token_field' => 'refresh_token',
                'access_token_field' => 'token',
            ]);

            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('Content-Type must describe a JSON representation');
            $client->execute($pair['pairId'], [
                'id' => 'example.item.update',
                'kind' => 'write',
                'method' => 'PATCH',
                'path' => '/items/{itemId}',
                'content_type' => 'text/plain',
            ], ['itemId' => 42], 'write_stable_key');
        } finally {
            @unlink($path);
            @unlink($path.'-wal');
            @unlink($path.'-shm');
        }
    }
}
