<?php

namespace AssistantHub\SymfonyConnector\Contract;

use AssistantHub\SymfonyConnector\Protocol\CapabilityDefinition;
use AssistantHub\SymfonyConnector\Protocol\LocalContext;
use AssistantHub\SymfonyConnector\Protocol\PairIdentity;

interface LocalAuthorizationInterface
{
    /** @param array<string, mixed> $input */
    public function authorize(PairIdentity $pair, CapabilityDefinition $capability, array $input): LocalContext;
}
