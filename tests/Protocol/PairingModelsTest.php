<?php

namespace AssistantHub\SymfonyConnector\Tests\Protocol;

use AssistantHub\SymfonyConnector\Protocol\EstablishedPair;
use AssistantHub\SymfonyConnector\Protocol\PendingPairingCode;
use PHPUnit\Framework\TestCase;

final class PairingModelsTest extends TestCase
{
    public function testPendingCodeAndPairRespectExpiryAndRevocation(): void
    {
        $now = new \DateTimeImmutable('2026-08-27T12:00:00+00:00');
        $code = new PendingPairingCode(hash('sha256', 'one-time-code'), 'user-42', ['customer:read'], $now, $now->modify('+5 minutes'));
        $pair = new EstablishedPair('pair-1', 'user-42', hash('sha256', 'pair-secret'), ['customer:read'], $now, $now->modify('+1 day'));

        self::assertTrue($code->isUsableAt($now));
        self::assertFalse($code->isUsableAt($now->modify('+5 minutes')));
        self::assertTrue($pair->isActiveAt($now));
        self::assertFalse($pair->isActiveAt($now->modify('+1 day')));
    }
}
