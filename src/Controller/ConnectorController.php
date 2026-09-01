<?php

namespace AssistantHub\SymfonyConnector\Controller;

use AssistantHub\SymfonyConnector\Protocol\Confirmation;
use AssistantHub\SymfonyConnector\Protocol\ProtocolException;
use AssistantHub\SymfonyConnector\Service\ConnectorService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/assistant-hub')]
final class ConnectorController
{
    public function __construct(
        private readonly ConnectorService $connector,
        #[Autowire('%assistant_hub_connector.demo_mode%')]
        private readonly bool $demoMode,
    ) {
    }

    #[Route('/pairing/demo', name: 'assistant_hub_connector_pairing_demo', methods: ['POST'])]
    public function pairDemo(): JsonResponse
    {
        if (!$this->demoMode) {
            return new JsonResponse([
                'error' => [
                    'code' => 'AUTHENTICATION_FAILED',
                    'message' => 'The fictitious pairing endpoint is disabled.',
                    'retryable' => false,
                ],
            ], 404);
        }

        return new JsonResponse([
            'pairing' => [
                'mode' => 'fictitious-local-demo',
                'pairId' => 'pair_demo_local',
                'warning' => 'No key exchange or production authentication occurred.',
                'next' => 'Send the configured demo key in X-Assistant-Hub-Demo-Key.',
            ],
        ], 201);
    }

    #[Route('/capabilities', name: 'assistant_hub_connector_capabilities', methods: ['GET'])]
    public function capabilities(Request $request): JsonResponse
    {
        return $this->respond(fn (): array => ['capabilities' => $this->connector->catalog($request)]);
    }

    #[Route('/actions/{capabilityId}/read', name: 'assistant_hub_connector_read', methods: ['POST'])]
    public function read(string $capabilityId, Request $request): JsonResponse
    {
        return $this->respond(fn (): array => [
            'result' => $this->connector->executeRead($capabilityId, $this->body($request), $request),
        ]);
    }

    #[Route('/actions/{capabilityId}/proposals', name: 'assistant_hub_connector_propose', methods: ['POST'])]
    public function propose(string $capabilityId, Request $request): JsonResponse
    {
        return $this->respond(fn (): array => [
            'proposal' => $this->connector->prepareProposal($capabilityId, $this->body($request), $request),
        ], 201);
    }

    #[Route('/actions/{capabilityId}/execute', name: 'assistant_hub_connector_execute', methods: ['POST'])]
    public function execute(string $capabilityId, Request $request): JsonResponse
    {
        return $this->respond(fn (): array => [
            'result' => $this->connector->executeConfirmed(
                $capabilityId,
                Confirmation::fromArray($this->body($request)),
                $request,
            ),
        ]);
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable $exception) {
            throw new ProtocolException('INVALID_INPUT', 'The request body must be a JSON object.', 400);
        }
    }

    private function respond(callable $operation, int $successStatus = 200): JsonResponse
    {
        try {
            return new JsonResponse($operation(), $successStatus);
        } catch (ProtocolException $exception) {
            return new JsonResponse($exception->toArray(), $exception->httpStatus);
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return new JsonResponse((new ProtocolException('INVALID_INPUT', $exception->getMessage(), 422))->toArray(), 422);
        } catch (\Throwable) {
            return new JsonResponse((new ProtocolException('INTERNAL_ERROR', 'The connector failed safely.', 500))->toArray(), 500);
        }
    }
}
