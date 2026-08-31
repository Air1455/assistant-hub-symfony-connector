<?php

namespace AssistantHub\SymfonyConnector\Protocol;

final readonly class Confirmation
{
    public function __construct(
        public string $proposalId,
        public string $fingerprint,
        public string $confirmedBy,
        public \DateTimeImmutable $confirmedAt,
    ) {
        if ('' === $proposalId || '' === $fingerprint || '' === $confirmedBy) {
            throw new \InvalidArgumentException('A confirmation must target an exact proposal.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['proposalId'] ?? ''),
            (string) ($data['fingerprint'] ?? ''),
            (string) ($data['confirmedBy'] ?? ''),
            new \DateTimeImmutable((string) ($data['confirmedAt'] ?? 'now')),
        );
    }
}
