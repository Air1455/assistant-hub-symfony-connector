<?php

namespace AssistantHub\SymfonyConnector\Contract;

use AssistantHub\SymfonyConnector\Protocol\PairingIdentity;
use Symfony\Component\Security\Core\User\UserInterface;

interface SessionUserIdentityMapperInterface
{
    public function map(UserInterface $user): PairingIdentity;
}
