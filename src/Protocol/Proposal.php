<?php

namespace AssistantHub\SymfonyConnector\Protocol;

final readonly class Proposal
{
    /** @param array<string, mixed> $input */
    private function __construct(
        public string $id,
        public string $pairId,
        public string $capabilityId,
        public string $capabilityVersion,
        public array $input,
        public string $summary,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public string $fingerprint,
    ) {
    }

    /** @param array<string, mixed> $input */
    public static function create(
        string $pairId,
        CapabilityDefinition $capability,
        array $input,
        string $summary,
        int $ttlSeconds,
    ): self {
        $createdAt = new \DateTimeImmutable();
        $data = [
            'id' => 'proposal_'.bin2hex(random_bytes(16)),
            'pairId' => $pairId,
            'capabilityId' => $capability->id,
            'capabilityVersion' => $capability->version,
            'input' => $input,
            'summary' => $summary,
            'createdAt' => $createdAt->format(DATE_ATOM),
            'expiresAt' => $createdAt->modify(sprintf('+%d seconds', $ttlSeconds))->format(DATE_ATOM),
        ];

        return new self(
            $data['id'],
            $data['pairId'],
            $data['capabilityId'],
            $data['capabilityVersion'],
            $data['input'],
            $data['summary'],
            $createdAt,
            new \DateTimeImmutable($data['expiresAt']),
            hash('sha256', CanonicalJson::encode($data)),
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['pairId'],
            (string) $data['capabilityId'],
            (string) $data['capabilityVersion'],
            (array) $data['input'],
            (string) $data['summary'],
            new \DateTimeImmutable((string) $data['createdAt']),
            new \DateTimeImmutable((string) $data['expiresAt']),
            (string) $data['fingerprint'],
        );
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'pairId' => $this->pairId,
            'capabilityId' => $this->capabilityId,
            'capabilityVersion' => $this->capabilityVersion,
            'input' => $this->input,
            'summary' => $this->summary,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
            'expiresAt' => $this->expiresAt->format(DATE_ATOM),
            'fingerprint' => $this->fingerprint,
        ];
    }
}
