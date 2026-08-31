<?php

namespace AssistantHub\SymfonyConnector\Protocol;

final readonly class ProposalExecutionReservation
{
    public const RESERVED = 'reserved';
    public const EXECUTING = 'executing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    /** @param array<string, mixed>|null $completedResult */
    public function __construct(
        public Proposal $proposal,
        public string $status,
        public string $idempotencyKey,
        public ?array $completedResult = null,
        public ?string $failureCode = null,
    ) {
        if (!in_array($status, [self::RESERVED, self::EXECUTING, self::COMPLETED, self::FAILED], true)) {
            throw new \InvalidArgumentException('Unknown proposal execution status.');
        }
    }
}
