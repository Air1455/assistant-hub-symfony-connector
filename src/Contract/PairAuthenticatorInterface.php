<?php

namespace AssistantHub\SymfonyConnector\Contract;

use AssistantHub\SymfonyConnector\Protocol\PairIdentity;
use Symfony\Component\HttpFoundation\Request;

interface PairAuthenticatorInterface
{
    public function authenticate(Request $request): PairIdentity;
}
