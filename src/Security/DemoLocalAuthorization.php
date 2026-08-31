<?php

namespace AssistantHub\SymfonyConnector\Security;

use AssistantHub\SymfonyConnector\Contract\LocalAuthorizationInterface;
use AssistantHub\SymfonyConnector\Protocol\CapabilityDefinition;
use AssistantHub\SymfonyConnector\Protocol\LocalContext;
use AssistantHub\SymfonyConnector\Protocol\PairIdentity;
use AssistantHub\SymfonyConnector\Protocol\ProtocolException;

/** Fictif : branchez ici le Security component et les règles métier du site. */
final class DemoLocalAuthorization implements LocalAuthorizationInterface
{
    public function __construct(private readonly bool $demoMode)
    {
    }

    public function authorize(PairIdentity $pair, CapabilityDefinition $capability, array $input): LocalContext
    {
        if (!$this->demoMode || 'pair_demo_local' !== $pair->pairId) {
            throw new ProtocolException('LOCAL_POLICY_DENIED', 'No local authorization policy accepted this request.', 403);
        }
        if (!str_starts_with($capability->id, 'example.')) {
            throw new ProtocolException('LOCAL_POLICY_DENIED', 'The demo policy only allows example capabilities.', 403);
        }

        return new LocalContext(
            'demo-local-admin',
            [$capability->kind.':'.$capability->id],
            'decision_'.bin2hex(random_bytes(8)),
        );
    }
}
