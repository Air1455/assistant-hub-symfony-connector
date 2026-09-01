<?php

namespace AssistantHub\SymfonyConnector\Security;

use AssistantHub\SymfonyConnector\Contract\SessionUserIdentityMapperInterface;
use AssistantHub\SymfonyConnector\Protocol\PairingIdentity;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class DefaultSessionUserIdentityMapper implements SessionUserIdentityMapperInterface
{
    public function map(UserInterface $user): PairingIdentity
    {
        return new PairingIdentity([], [
            'id' => $user->getUserIdentifier(),
            'roles' => array_values(array_unique(array_filter($user->getRoles(), 'is_string'))),
        ]);
    }
}
