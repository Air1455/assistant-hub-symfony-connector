<?php

namespace AssistantHub\SymfonyConnector\Example;

use AssistantHub\SymfonyConnector\Contract\CapabilityInterface;
use AssistantHub\SymfonyConnector\Protocol\CapabilityDefinition;
use AssistantHub\SymfonyConnector\Protocol\LocalContext;

/** Exemple d'écriture confirmée. Ne persiste aucune donnée métier. */
final class TaskCreateCapability implements CapabilityInterface
{
    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            'example.task.create',
            '1.0.0',
            'write',
            'Create an example task',
            'Demonstrates an exact confirmation before a simulated write.',
            [
                'type' => 'object',
                'properties' => ['title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120]],
                'required' => ['title'],
                'additionalProperties' => false,
            ],
            [
                'type' => 'object',
                'properties' => ['task' => ['type' => 'object'], 'simulated' => ['type' => 'boolean']],
                'required' => ['task', 'simulated'],
            ],
            true,
        );
    }

    public function normalizeInput(array $input): array
    {
        if (array_diff(array_keys($input), ['title'])) {
            throw new \InvalidArgumentException('Unknown fields are not accepted.');
        }
        $title = trim((string) ($input['title'] ?? ''));
        if ('' === $title || mb_strlen($title) > 120) {
            throw new \InvalidArgumentException('Title must contain between 1 and 120 characters.');
        }

        return ['title' => $title];
    }

    public function preview(array $input, LocalContext $context): string
    {
        return sprintf('Create the example task "%s".', $input['title']);
    }

    public function execute(array $input, LocalContext $context): array
    {
        // Un adaptateur réel effectuerait ici une ultime vérification métier puis
        // une écriture transactionnelle et idempotente dans l'application hôte.
        return [
            'task' => [
                'id' => 'example_task_'.bin2hex(random_bytes(6)),
                'title' => $input['title'],
                'authorizedAs' => $context->localActorId,
            ],
            'simulated' => true,
        ];
    }
}
