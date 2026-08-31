<?php

namespace AssistantHub\SymfonyConnector\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class DiscoveryController
{
    /** @param list<string> $pairingModes */
    public function __construct(
        #[Autowire('%assistant_hub_connector.connector_id%')]
        private readonly string $connectorId,
        #[Autowire('%assistant_hub_connector.connector_name%')]
        private readonly string $connectorName,
        #[Autowire('%assistant_hub_connector.pairing_modes%')]
        private readonly array $pairingModes,
        #[Autowire('%assistant_hub_connector.demo_mode%')]
        private readonly bool $demoMode,
        private readonly string $authorizationEndpoint = '/connector/authorize',
        private readonly string $tokenEndpoint = '/assistant-hub/pairing/token',
    ) {
    }

    #[Route('/.well-known/assistant-hub', name: 'assistant_hub_connector_discovery', methods: ['GET'])]
    public function discover(): JsonResponse
    {
        $pairingModes = array_values(array_filter(
            $this->pairingModes,
            fn (string $mode): bool => 'demo' !== $mode || $this->demoMode,
        ));

        return new JsonResponse([
            'schema' => 'assistant-hub-connector-discovery',
            'protocolVersion' => '1.0',
            'connector' => ['id' => $this->connectorId, 'name' => $this->connectorName],
            'api' => [
                'basePath' => '/assistant-hub',
                'capabilitiesPath' => '/assistant-hub/capabilities',
            ],
            'pairing' => [
                'modes' => $pairingModes,
                'authorizationEndpoint' => $this->authorizationEndpoint,
                'tokenEndpoint' => $this->tokenEndpoint,
            ],
        ]);
    }
}
