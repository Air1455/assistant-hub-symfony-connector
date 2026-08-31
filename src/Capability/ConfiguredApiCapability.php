<?php

namespace AssistantHub\SymfonyConnector\Capability;

use AssistantHub\SymfonyConnector\Contract\CapabilityInterface;
use AssistantHub\SymfonyConnector\Protocol\CapabilityDefinition;
use AssistantHub\SymfonyConnector\Protocol\LocalContext;
use AssistantHub\SymfonyConnector\Protocol\ProtocolException;
use AssistantHub\SymfonyConnector\Service\SiteApiClient;
use AssistantHub\SymfonyConnector\Validation\JsonSchemaValidator;

final readonly class ConfiguredApiCapability implements CapabilityInterface
{
    private CapabilityDefinition $definition;

    /** @param array<string, mixed> $config */
    public function __construct(
        private SiteApiClient $api,
        private JsonSchemaValidator $schemaValidator,
        private array $config,
    )
    {
        foreach (['id', 'method', 'path'] as $required) {
            if (!is_string($config[$required] ?? null) || '' === trim($config[$required])) {
                throw new \InvalidArgumentException(sprintf('Configured capability field "%s" is required.', $required));
            }
        }
        if (1 !== preg_match('#^/(?:[A-Za-z0-9._~-]+|\{[A-Za-z][A-Za-z0-9_]*\})(?:/(?:[A-Za-z0-9._~-]+|\{[A-Za-z][A-Za-z0-9_]*\}))*$#D', $config['path'])) {
            throw new \InvalidArgumentException('A capability path must be a safe relative API path with optional whole-segment placeholders.');
        }
        preg_match_all('/\{([A-Za-z][A-Za-z0-9_]*)\}/', $config['path'], $pathMatches);
        $pathInputs = array_values(array_unique($pathMatches[1] ?? []));
        $inputSchema = is_array($config['input_schema'] ?? null) ? $config['input_schema'] : ['type' => 'object', 'additionalProperties' => false];
        $properties = is_array($inputSchema['properties'] ?? null) ? $inputSchema['properties'] : [];
        $required = is_array($inputSchema['required'] ?? null) ? $inputSchema['required'] : [];
        foreach ($pathInputs as $pathInput) {
            if (!isset($properties[$pathInput]) || !in_array($pathInput, $required, true)) {
                throw new \InvalidArgumentException(sprintf('Path placeholder "%s" must be a declared required input property.', $pathInput));
            }
        }
        $method = strtoupper($config['method']);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new \InvalidArgumentException('A capability HTTP method is not allowed.');
        }
        $kind = (string) ($config['kind'] ?? ('GET' === $method ? 'read' : 'write'));
        if ('read' === $kind && 'GET' !== $method) {
            throw new \InvalidArgumentException('A read capability must use GET. Any other method must use the confirmed write channel.');
        }
        if ('write' === $kind && 'GET' === $method) {
            throw new \InvalidArgumentException('A GET capability cannot be declared as a write.');
        }
        // The normalized method is validated here and normalized again by the API client.
        $this->definition = new CapabilityDefinition(
            $config['id'],
            (string) ($config['version'] ?? '1.0'),
            $kind,
            (string) ($config['title'] ?? $config['id']),
            (string) ($config['description'] ?? ''),
            $inputSchema,
            is_array($config['output_schema'] ?? null) ? $config['output_schema'] : ['type' => 'object'],
            'write' === $kind,
            array_values(array_filter($config['required_roles'] ?? [], 'is_string')),
        );
    }

    public function definition(): CapabilityDefinition
    {
        return $this->definition;
    }

    public function normalizeInput(array $input): array
    {
        $this->schemaValidator->assertValid($input, $this->definition->inputSchema, 'input');

        return $input;
    }

    public function preview(array $input, LocalContext $context): string
    {
        return (string) ($this->config['preview'] ?? sprintf('Exécuter %s avec les paramètres validés.', $this->definition->title));
    }

    public function execute(array $input, LocalContext $context): array
    {
        if (null === $context->pairId) {
            throw new \LogicException('A configured API capability requires an authenticated pair.');
        }

        $result = $this->api->execute($context->pairId, $this->config, $input, $context->idempotencyKey);
        try {
            $this->schemaValidator->assertValid($result, $this->definition->outputSchema, 'output', true);
        } catch (\InvalidArgumentException $exception) {
            throw new ProtocolException('SITE_API_ERROR', 'L’API du site a renvoyé une réponse qui ne respecte pas la capacité déclarée.', 502, false, previous: $exception);
        }

        return $result;
    }
}
