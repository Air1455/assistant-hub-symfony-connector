<?php

namespace AssistantHub\SymfonyConnector\Protocol;

final readonly class PairingIdentity
{
    /**
     * @param array<string, mixed> $credentials Secrets used by an optional downstream site API.
     * @param array<string, mixed> $identity Public local identity metadata stored in the encrypted vault.
     */
    public function __construct(
        public array $credentials,
        public array $identity,
    ) {
        $actorId = $identity['id'] ?? $identity['email'] ?? null;
        if ((!is_string($actorId) && !is_int($actorId)) || '' === trim((string) $actorId)) {
            throw new \InvalidArgumentException('A pairing identity must expose a stable id or email.');
        }
    }
}
