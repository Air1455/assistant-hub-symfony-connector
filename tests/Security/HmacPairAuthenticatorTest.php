<?php

namespace AssistantHub\SymfonyConnector\Tests\Security;

use AssistantHub\SymfonyConnector\Protocol\ProtocolException;
use AssistantHub\SymfonyConnector\Security\HmacPairAuthenticator;
use AssistantHub\SymfonyConnector\Security\SecretCipher;
use AssistantHub\SymfonyConnector\Storage\ConnectorDatabase;
use AssistantHub\SymfonyConnector\Store\ConnectorStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class HmacPairAuthenticatorTest extends TestCase
{
    public function testAnUnknownOrRevokedPairHasADistinctProtocolCode(): void
    {
        $path = sys_get_temp_dir().'/assistant-hub-invalid-pair-'.bin2hex(random_bytes(8)).'.sqlite';
        try {
            $store = new ConnectorStore(new ConnectorDatabase($path), new SecretCipher(str_repeat('k', 32)));
            $authenticator = new HmacPairAuthenticator($store, 300);
            $request = Request::create('/assistant-hub/pairing', 'DELETE', [], [], [], [
                'HTTP_X_ASSISTANT_HUB_PAIR_ID' => 'pair_unknown',
                'HTTP_X_ASSISTANT_HUB_TIMESTAMP' => (string) time(),
                'HTTP_X_ASSISTANT_HUB_NONCE' => rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='),
                'HTTP_X_ASSISTANT_HUB_SIGNATURE' => str_repeat('0', 64),
            ]);

            try {
                $authenticator->authenticate($request);
                self::fail('An unknown pair must be rejected.');
            } catch (ProtocolException $exception) {
                self::assertSame('PAIR_INVALID', $exception->protocolCode);
                self::assertSame(401, $exception->httpStatus);
                self::assertFalse($exception->retryable);
            }
        } finally {
            @unlink($path);
            @unlink($path.'-wal');
            @unlink($path.'-shm');
        }
    }
}
