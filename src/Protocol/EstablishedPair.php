<?php

namespace AssistantHub\SymfonyConnector\Protocol;

final readonly class EstablishedPair
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $pairId,
        public string $localActorId,
        public string $secretHash,
        public array $scopes,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $expiresAt = null,
        public ?\DateTimeImmutable $revokedAt = null,
    ) {
        if ('' === trim($pairId) || '' === trim($localActorId)) {
            throw new \InvalidArgumentException('Pair and local actor identifiers are required.');
        }
        if (64 !== strlen($secretHash) || 1 !== preg_match('/^[a-f0-9]{64}$/', $secretHash)) {
            throw new \InvalidArgumentException('Pair secret hashes must be lowercase SHA-256 values.');
        }
        if (null !== $expiresAt && $expiresAt <= $createdAt) {
            throw new \InvalidArgumentException('A pair must expire after its creation.');
        }
    }

    public function isActiveAt(\DateTimeImmutable $now): bool
    {
        return null === $this->revokedAt && (null === $this->expiresAt || $now < $this->expiresAt);
    }
}
