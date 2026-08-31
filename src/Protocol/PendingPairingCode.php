<?php

namespace AssistantHub\SymfonyConnector\Protocol;

final readonly class PendingPairingCode
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $codeHash,
        public string $localActorId,
        public array $scopes,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $consumedAt = null,
    ) {
        if (64 !== strlen($codeHash) || 1 !== preg_match('/^[a-f0-9]{64}$/', $codeHash)) {
            throw new \InvalidArgumentException('Pairing code hashes must be lowercase SHA-256 values.');
        }
        if ('' === trim($localActorId)) {
            throw new \InvalidArgumentException('A local actor is required.');
        }
        if ($expiresAt <= $createdAt) {
            throw new \InvalidArgumentException('A pairing code must expire after its creation.');
        }
    }

    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return null === $this->consumedAt && $now < $this->expiresAt;
    }
}
