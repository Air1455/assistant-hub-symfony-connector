<?php

namespace AssistantHub\SymfonyConnector\Security;

use AssistantHub\SymfonyConnector\Contract\PairAuthenticatorInterface;
use AssistantHub\SymfonyConnector\Protocol\PairIdentity;
use AssistantHub\SymfonyConnector\Protocol\ProtocolException;
use Symfony\Component\HttpFoundation\Request;

/** Fictif : à remplacer avant toute intégration réelle. */
final class DemoPairAuthenticator implements PairAuthenticatorInterface
{
    public function __construct(
        private readonly bool $demoMode,
        private readonly string $demoPairKey,
    ) {
    }

    public function authenticate(Request $request): PairIdentity
    {
        if (!$this->demoMode) {
            throw new ProtocolException('AUTHENTICATION_FAILED', 'No production pair authenticator is configured.', 401);
        }

        $provided = $request->headers->get('X-Assistant-Hub-Demo-Key', '');
        if (!hash_equals($this->demoPairKey, $provided)) {
            throw new ProtocolException('AUTHENTICATION_FAILED', 'The local demo pair key is invalid.', 401);
        }

        return new PairIdentity('pair_demo_local', 'organization_demo', 'hub_demo_local');
    }
}
