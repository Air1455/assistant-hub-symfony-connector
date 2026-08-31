<?php

namespace AssistantHub\SymfonyConnector\Contract;

use AssistantHub\SymfonyConnector\Protocol\ExecutionResult;
use AssistantHub\SymfonyConnector\Protocol\Proposal;
use AssistantHub\SymfonyConnector\Protocol\ProposalExecutionReservation;

interface ProposalStoreInterface
{
    public function save(Proposal $proposal): void;

    public function find(string $proposalId): ?Proposal;

    public function reserve(string $proposalId): ProposalExecutionReservation;

    public function complete(string $proposalId, ExecutionResult $result): void;

    public function fail(string $proposalId, string $failureCode): void;
}
