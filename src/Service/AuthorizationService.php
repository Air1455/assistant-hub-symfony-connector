<?php

namespace AssistantHub\SymfonyConnector\Service;

use AssistantHub\SymfonyConnector\Contract\PairingIdentityProviderInterface;
use AssistantHub\SymfonyConnector\Store\ConnectorStore;

final readonly class AuthorizationService
{
    /** @param list<string> $allowedRedirectUris */
    public function __construct(
        private PairingIdentityProviderInterface $identityProvider,
        private ConnectorStore $store,
        private array $allowedRedirectUris,
    ) {
    }

    public function validateRequest(array $query): array
    {
        foreach (['client_id', 'redirect_uri', 'state', 'code_challenge'] as $field) {
            if (!is_string($query[$field] ?? null) || '' === trim($query[$field])) {
                throw new \InvalidArgumentException('La demande d’autorisation est incomplète.');
            }
        }
        if (!in_array($query['redirect_uri'], $this->allowedRedirectUris, true)) {
            throw new \InvalidArgumentException('L’adresse de retour du Hub n’est pas autorisée.');
        }
        if (!preg_match('/^[A-Za-z0-9._~-]{16,200}$/', $query['state']) || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $query['code_challenge'])) {
            throw new \InvalidArgumentException('Les protections de la demande d’autorisation sont invalides.');
        }
        if (strlen($query['client_id']) > 160) {
            throw new \InvalidArgumentException('L’identifiant du Hub est trop long.');
        }

        return array_intersect_key($query, array_flip(['client_id', 'redirect_uri', 'state', 'code_challenge']));
    }

    public function requiresCredentials(): bool
    {
        return $this->identityProvider->requiresCredentials();
    }

    public function connect(?string $username = null, ?string $password = null): string
    {
        $pairingIdentity = $this->identityProvider->acquire($username, $password);

        return $this->store->createVault($pairingIdentity->credentials, $pairingIdentity->identity);
    }

    /** @deprecated Use connect() so session-based providers can omit credentials. */
    public function login(string $username, string $password): string
    {
        return $this->connect($username, $password);
    }

    public function authorize(array $authorization, string $vaultId): string
    {
        return $this->store->createAuthorizationCode($authorization['client_id'], $authorization['redirect_uri'], $authorization['code_challenge'], $vaultId);
    }

    public function exchange(array $payload): array
    {
        foreach (['code', 'client_id', 'redirect_uri', 'code_verifier'] as $field) {
            if (!is_string($payload[$field] ?? null) || '' === $payload[$field]) {
                throw new \InvalidArgumentException('The token exchange request is incomplete.');
            }
        }
        $pair = $this->store->exchangeAuthorizationCode($payload['code'], $payload['client_id'], $payload['redirect_uri'], $payload['code_verifier']);
        $vault = $this->store->vault($pair['vaultId']);
        $roles = array_values(array_filter($vault['identity']['roles'] ?? [], 'is_string'));

        return [
            'pairId' => $pair['pairId'],
            'secret' => $pair['secret'],
            'localActorId' => $vault['actorId'],
            'scopes' => $roles,
            'createdAt' => $pair['createdAt'],
        ];
    }
}
