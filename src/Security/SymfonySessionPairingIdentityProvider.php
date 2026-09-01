<?php

namespace AssistantHub\SymfonyConnector\Security;

use AssistantHub\SymfonyConnector\Contract\PairingIdentityProviderInterface;
use AssistantHub\SymfonyConnector\Contract\SessionUserIdentityMapperInterface;
use AssistantHub\SymfonyConnector\Protocol\PairingIdentity;
use AssistantHub\SymfonyConnector\Protocol\ProtocolException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class SymfonySessionPairingIdentityProvider implements PairingIdentityProviderInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private SessionUserIdentityMapperInterface $mapper,
    ) {
    }

    public function requiresCredentials(): bool
    {
        return false;
    }

    public function acquire(?string $username = null, ?string $password = null): PairingIdentity
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            throw new ProtocolException('AUTHENTICATION_FAILED', 'Une session authentifiée du site est requise.', 401);
        }

        return $this->mapper->map($user);
    }
}
