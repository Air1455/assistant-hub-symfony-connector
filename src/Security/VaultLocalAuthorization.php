<?php

namespace AssistantHub\SymfonyConnector\Security;

use AssistantHub\SymfonyConnector\Contract\LocalAuthorizationInterface;
use AssistantHub\SymfonyConnector\Protocol\CapabilityDefinition;
use AssistantHub\SymfonyConnector\Protocol\LocalContext;
use AssistantHub\SymfonyConnector\Protocol\PairIdentity;
use AssistantHub\SymfonyConnector\Protocol\ProtocolException;
use AssistantHub\SymfonyConnector\Store\ConnectorStore;

final readonly class VaultLocalAuthorization implements LocalAuthorizationInterface
{
    public function __construct(private ConnectorStore $store)
    {
    }

    public function authorize(PairIdentity $pair, CapabilityDefinition $capability, array $input): LocalContext
    {
        try {
            $storedPair = $this->store->pair($pair->pairId);
            $vault = $this->store->vault($storedPair['vaultId']);
        } catch (\DomainException|\RuntimeException) {
            throw new ProtocolException('LOCAL_POLICY_DENIED', 'The site connection is no longer authorized.', 403);
        }
        $roles = array_values(array_filter($vault['identity']['roles'] ?? [], 'is_string'));
        foreach ($capability->requiredRoles as $requiredRole) {
            if (!in_array($requiredRole, $roles, true)) {
                throw new ProtocolException('LOCAL_POLICY_DENIED', 'The site account cannot use this capability.', 403);
            }
        }

        return new LocalContext(
            $vault['actorId'],
            $roles,
            'decision_'.bin2hex(random_bytes(12)),
            $pair->pairId,
        );
    }
}
