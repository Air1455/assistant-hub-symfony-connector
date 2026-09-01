<?php

namespace AssistantHub\SymfonyConnector\Tests\Security;

use AssistantHub\SymfonyConnector\Protocol\PairingIdentity;
use AssistantHub\SymfonyConnector\Protocol\ProtocolException;
use AssistantHub\SymfonyConnector\Security\DefaultSessionUserIdentityMapper;
use AssistantHub\SymfonyConnector\Security\SymfonySessionPairingIdentityProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class PairingIdentityProviderTest extends TestCase
{
    public function testDefaultMapperCreatesATokenlessIdentityFromTheCurrentSymfonyUser(): void
    {
        $user = new InMemoryUser('viewer@example.test', null, ['ROLE_RTO_VIEWER']);
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $provider = new SymfonySessionPairingIdentityProvider($storage, new DefaultSessionUserIdentityMapper());

        self::assertFalse($provider->requiresCredentials());
        self::assertEquals(new PairingIdentity([], [
            'id' => 'viewer@example.test',
            'roles' => ['ROLE_RTO_VIEWER'],
        ]), $provider->acquire());
    }

    public function testSessionProviderRejectsAnAnonymousRequest(): void
    {
        $provider = new SymfonySessionPairingIdentityProvider(new TokenStorage(), new DefaultSessionUserIdentityMapper());

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('session authentifiée');
        $provider->acquire();
    }

    public function testPairingIdentityRequiresAStableActorIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PairingIdentity([], ['roles' => ['ROLE_USER']]);
    }
}
