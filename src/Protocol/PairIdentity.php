<?php

namespace AssistantHub\SymfonyConnector\Protocol;

final readonly class PairIdentity
{
    public function __construct(
        public string $pairId,
        public string $organizationId,
        public string $hubInstanceId,
    ) {
        if ('' === $pairId || '' === $organizationId || '' === $hubInstanceId) {
            throw new \InvalidArgumentException('A pair identity must be complete.');
        }
    }
}
