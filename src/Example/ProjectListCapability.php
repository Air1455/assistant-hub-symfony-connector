<?php

namespace AssistantHub\SymfonyConnector\Example;

use AssistantHub\SymfonyConnector\Contract\CapabilityInterface;
use AssistantHub\SymfonyConnector\Protocol\CapabilityDefinition;
use AssistantHub\SymfonyConnector\Protocol\LocalContext;

/** Exemple générique de lecture, sans dépendance à une application réelle. */
final class ProjectListCapability implements CapabilityInterface
{
    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            'example.project.list',
            '1.0.0',
            'read',
            'List example projects',
            'Returns static demonstration data.',
            ['type' => 'object', 'additionalProperties' => false],
            [
                'type' => 'object',
                'properties' => ['projects' => ['type' => 'array']],
                'required' => ['projects'],
            ],
            false,
        );
    }

    public function normalizeInput(array $input): array
    {
        if ([] !== $input) {
            throw new \InvalidArgumentException('This example read capability accepts no input.');
        }

        return [];
    }

    public function preview(array $input, LocalContext $context): string
    {
        return 'List example projects.';
    }

    public function execute(array $input, LocalContext $context): array
    {
        return [
            'projects' => [
                ['id' => 'example_alpha', 'name' => 'Example Alpha'],
                ['id' => 'example_beta', 'name' => 'Example Beta'],
            ],
            'authorizedAs' => $context->localActorId,
        ];
    }
}
