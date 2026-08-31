<?php

namespace AssistantHub\SymfonyConnector\Tests\Store;

use AssistantHub\SymfonyConnector\Security\SecretCipher;
use AssistantHub\SymfonyConnector\Storage\ConnectorDatabase;
use AssistantHub\SymfonyConnector\Store\ConnectorStore;
use PHPUnit\Framework\TestCase;

final class ConnectorStoreTest extends TestCase
{
    public function testAuthorizationCodeIsPkceBoundAndSingleUse(): void
    {
        $path = sys_get_temp_dir().'/connector-'.bin2hex(random_bytes(8)).'.sqlite';
        try {
            $store = new ConnectorStore(new ConnectorDatabase($path), new SecretCipher(str_repeat('k', 32)));
            $vault = $store->createVault(['access_token' => 'secret-token'], ['id' => '7', 'roles' => ['ROLE_ADMIN']]);
            $verifier = str_repeat('v', 64);
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $code = $store->createAuthorizationCode('hub-1', 'https://hub.test/sites/callback', $challenge, $vault);
            $pair = $store->exchangeAuthorizationCode($code, 'hub-1', 'https://hub.test/sites/callback', $verifier);
            self::assertStringStartsWith('pair_', $pair['pairId']);
            self::assertSame('secret-token', $store->vault($vault)['tokens']['access_token']);
            $this->expectException(\DomainException::class);
            $store->exchangeAuthorizationCode($code, 'hub-1', 'https://hub.test/sites/callback', $verifier);
        } finally {
            @unlink($path);
            @unlink($path.'-wal');
            @unlink($path.'-shm');
        }
    }
}
