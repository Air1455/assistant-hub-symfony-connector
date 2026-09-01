<?php

namespace AssistantHub\SymfonyConnector\Contract;

use AssistantHub\SymfonyConnector\Protocol\PairingIdentity;

interface PairingIdentityProviderInterface
{
    public function requiresCredentials(): bool;

    public function acquire(?string $username = null, ?string $password = null): PairingIdentity;
}
