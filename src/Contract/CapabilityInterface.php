<?php

namespace AssistantHub\SymfonyConnector\Contract;

use AssistantHub\SymfonyConnector\Protocol\CapabilityDefinition;
use AssistantHub\SymfonyConnector\Protocol\LocalContext;

interface CapabilityInterface
{
    public function definition(): CapabilityDefinition;

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function normalizeInput(array $input): array;

    /** @param array<string, mixed> $input */
    public function preview(array $input, LocalContext $context): string;

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function execute(array $input, LocalContext $context): array;
}
