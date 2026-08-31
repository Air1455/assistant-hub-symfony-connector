<?php

namespace AssistantHub\SymfonyConnector\Protocol;

final readonly class ExecutionResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $capabilityId,
        public array $data,
        public string $idempotencyKey,
        public \DateTimeImmutable $executedAt = new \DateTimeImmutable(),
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'capabilityId' => $this->capabilityId,
            'data' => $this->data,
            'idempotencyKey' => $this->idempotencyKey,
            'executedAt' => $this->executedAt->format(DATE_ATOM),
        ];
    }
}
