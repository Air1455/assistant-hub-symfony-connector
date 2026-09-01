<?php

namespace AssistantHub\SymfonyConnector\Security;

use AssistantHub\SymfonyConnector\Contract\PairingIdentityProviderInterface;
use AssistantHub\SymfonyConnector\Protocol\PairingIdentity;
use AssistantHub\SymfonyConnector\Service\SiteApiClient;

final readonly class ApiTokenPairingIdentityProvider implements PairingIdentityProviderInterface
{
    public function __construct(private SiteApiClient $api)
    {
    }

    public function requiresCredentials(): bool
    {
        return true;
    }

    public function acquire(?string $username = null, ?string $password = null): PairingIdentity
    {
        return $this->api->authenticate((string) $username, (string) $password);
    }
}
