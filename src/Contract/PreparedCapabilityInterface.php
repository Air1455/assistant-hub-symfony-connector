<?php

namespace AssistantHub\SymfonyConnector\Contract;

use AssistantHub\SymfonyConnector\Protocol\LocalContext;
use AssistantHub\SymfonyConnector\Protocol\PreparedAction;

/** Optional, read-only preparation of a complete action after local authorization. */
interface PreparedCapabilityInterface extends CapabilityInterface
{
    /** execute() later receives the frozen PreparedAction::input, never new model arguments. */
    public function prepare(array $input, LocalContext $context): PreparedAction;
}
