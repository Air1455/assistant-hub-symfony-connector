<?php

namespace AssistantHub\SymfonyConnector\Protocol;

final readonly class LocalContext
{
    /** @param list<string> $permissions */
    public function __construct(
        public string $localActorId,
        public array $permissions,
        public string $authorizationDecisionId,
        public ?string $pairId = null,
        public ?string $idempotencyKey = null,
    ) {
    }

    public function withIdempotencyKey(string $idempotencyKey): self
    {
        return new self($this->localActorId, $this->permissions, $this->authorizationDecisionId, $this->pairId, $idempotencyKey);
    }
}
