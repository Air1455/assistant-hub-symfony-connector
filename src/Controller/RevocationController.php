<?php

namespace AssistantHub\SymfonyConnector\Controller;

use AssistantHub\SymfonyConnector\Contract\PairAuthenticatorInterface;
use AssistantHub\SymfonyConnector\Protocol\ProtocolException;
use AssistantHub\SymfonyConnector\Store\ConnectorStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RevocationController
{
    public function __construct(private PairAuthenticatorInterface $authenticator, private ConnectorStore $store) {}

    #[Route('/assistant-hub/pairing', name: 'assistant_hub_connector_pairing_revoke', methods: ['DELETE'])]
    public function revoke(Request $request): JsonResponse
    {
        try {
            $pair = $this->authenticator->authenticate($request);
            $this->store->revokePair($pair->pairId);
            return new JsonResponse(null, 204);
        } catch (ProtocolException $e) {
            return new JsonResponse($e->toArray(), $e->httpStatus);
        } catch (\Throwable) {
            return new JsonResponse(['error' => ['code' => 'PAIR_INVALID', 'message' => 'The pair is invalid or revoked.', 'retryable' => false]], 401);
        }
    }
}
