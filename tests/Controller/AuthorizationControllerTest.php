<?php

namespace AssistantHub\SymfonyConnector\Tests\Controller;

use AssistantHub\SymfonyConnector\Contract\PairingIdentityProviderInterface;
use AssistantHub\SymfonyConnector\Controller\AuthorizationController;
use AssistantHub\SymfonyConnector\Protocol\PairingIdentity;
use AssistantHub\SymfonyConnector\Security\SecretCipher;
use AssistantHub\SymfonyConnector\Service\AuthorizationService;
use AssistantHub\SymfonyConnector\Storage\ConnectorDatabase;
use AssistantHub\SymfonyConnector\Store\ConnectorStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class AuthorizationControllerTest extends TestCase
{
    public function testSessionIdentityGoesDirectlyToConsentWithoutCopyingSessionSecrets(): void
    {
        $path = sys_get_temp_dir().'/connector-session-pairing-'.bin2hex(random_bytes(8)).'.sqlite';
        try {
            $store = new ConnectorStore(new ConnectorDatabase($path), new SecretCipher(str_repeat('s', 32)));
            $provider = new class implements PairingIdentityProviderInterface {
                public function requiresCredentials(): bool
                {
                    return false;
                }

                public function acquire(?string $username = null, ?string $password = null): PairingIdentity
                {
                    return new PairingIdentity([], [
                        'id' => '42',
                        'email' => 'viewer@example.test',
                        'roles' => ['ROLE_RTO_VIEWER'],
                    ]);
                }
            };
            $controller = new AuthorizationController(
                new AuthorizationService($provider, $store, ['https://hub.example.test/sites/callback']),
                'EA-RTO',
            );
            $request = Request::create('/connector/authorize', 'GET', $this->authorizationQuery());
            $request->setSession(new Session(new MockArraySessionStorage()));

            $response = $controller->authorize($request);
            $content = (string) $response->getContent();

            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Compte authentifié par la session sécurisée de EA-RTO', $content);
            self::assertStringContainsString('Aucun cookie de session ni mot de passe', $content);
            self::assertStringNotContainsString('name="password"', $content);

            $vaultId = $request->getSession()->get('assistant_hub_connector.vault');
            self::assertIsString($vaultId);
            self::assertSame([], $store->vault($vaultId)['tokens']);
            self::assertSame('42', $store->vault($vaultId)['actorId']);
        } finally {
            @unlink($path);
            @unlink($path.'-wal');
            @unlink($path.'-shm');
        }
    }

    public function testApiTokenIdentityStillShowsTheCredentialForm(): void
    {
        $path = sys_get_temp_dir().'/connector-api-pairing-'.bin2hex(random_bytes(8)).'.sqlite';
        try {
            $store = new ConnectorStore(new ConnectorDatabase($path), new SecretCipher(str_repeat('a', 32)));
            $provider = new class implements PairingIdentityProviderInterface {
                public function requiresCredentials(): bool
                {
                    return true;
                }

                public function acquire(?string $username = null, ?string $password = null): PairingIdentity
                {
                    throw new \LogicException('Credentials are collected by the form first.');
                }
            };
            $controller = new AuthorizationController(
                new AuthorizationService($provider, $store, ['https://hub.example.test/sites/callback']),
                'POWEG',
            );
            $request = Request::create('/connector/authorize', 'GET', $this->authorizationQuery());
            $request->setSession(new Session(new MockArraySessionStorage()));

            $response = $controller->authorize($request);
            $content = (string) $response->getContent();

            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Connexion à POWEG', $content);
            self::assertStringContainsString('name="password"', $content);
            self::assertFalse($request->getSession()->has('assistant_hub_connector.vault'));
        } finally {
            @unlink($path);
            @unlink($path.'-wal');
            @unlink($path.'-shm');
        }
    }

    /** @return array<string, string> */
    private function authorizationQuery(): array
    {
        return [
            'client_id' => 'hub-client-identifier',
            'redirect_uri' => 'https://hub.example.test/sites/callback',
            'state' => str_repeat('s', 32),
            'code_challenge' => str_repeat('c', 43),
            'code_challenge_method' => 'S256',
        ];
    }
}
