<?php

namespace AssistantHub\SymfonyConnector\Security;

use AssistantHub\SymfonyConnector\Contract\PairAuthenticatorInterface;
use AssistantHub\SymfonyConnector\Protocol\PairIdentity;
use AssistantHub\SymfonyConnector\Protocol\ProtocolException;
use AssistantHub\SymfonyConnector\Store\ConnectorStore;
use Symfony\Component\HttpFoundation\Request;

final readonly class HmacPairAuthenticator implements PairAuthenticatorInterface
{
    public function __construct(private ConnectorStore $store, private int $clockSkewSeconds)
    {
    }

    public function authenticate(Request $request): PairIdentity
    {
        $pairId = $request->headers->get('X-Assistant-Hub-Pair-Id', '');
        $timestamp = $request->headers->get('X-Assistant-Hub-Timestamp', '');
        $nonce = $request->headers->get('X-Assistant-Hub-Nonce', '');
        $signature = $request->headers->get('X-Assistant-Hub-Signature', '');
        if ('' === $pairId || !ctype_digit($timestamp) || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            throw new ProtocolException('AUTHENTICATION_FAILED', 'The signed connector request is incomplete.', 401);
        }
        if (abs(time() - (int) $timestamp) > $this->clockSkewSeconds) {
            throw new ProtocolException('AUTHENTICATION_FAILED', 'The signed connector request has expired.', 401);
        }
        try {
            $pair = $this->store->pair($pairId);
            $vault = $this->store->vault($pair['vaultId']);
        } catch (\DomainException|\RuntimeException) {
            throw new ProtocolException('PAIR_INVALID', 'The connector pair is invalid or revoked.', 401);
        }
        $canonical = implode("\n", [
            strtoupper($request->getMethod()),
            $request->getPathInfo(),
            $timestamp,
            $nonce,
            hash('sha256', $request->getContent()),
        ]);
        if (!hash_equals(hash_hmac('sha256', $canonical, $pair['secret']), $signature)) {
            throw new ProtocolException('AUTHENTICATION_FAILED', 'The connector request signature is invalid.', 401);
        }
        try {
            $this->store->consumeNonce($pairId, $nonce, $this->clockSkewSeconds * 2);
        } catch (\DomainException) {
            throw new ProtocolException('AUTHENTICATION_FAILED', 'The connector request was replayed.', 401);
        }

        return new PairIdentity($pairId, $vault['actorId'], $pair['clientId']);
    }
}
