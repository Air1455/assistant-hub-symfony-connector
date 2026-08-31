<?php

namespace AssistantHub\SymfonyConnector\Tests\Validation;

use AssistantHub\SymfonyConnector\Validation\JsonSchemaValidator;
use PHPUnit\Framework\TestCase;

final class JsonSchemaValidatorTest extends TestCase
{
    public function testItValidatesNestedDeclaredData(): void
    {
        (new JsonSchemaValidator())->assertValid([
            'items' => [['id' => 1, 'label' => 'Alpha']],
            'count' => 1,
        ], $this->collectionSchema(), 'output', true);

        self::addToAssertionCount(1);
    }

    public function testClosedOutputRejectsAnUndeclaredNestedField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('output.items[0].secret');

        (new JsonSchemaValidator())->assertValid([
            'items' => [['id' => 1, 'label' => 'Alpha', 'secret' => 'hidden']],
            'count' => 1,
        ], $this->collectionSchema(), 'output', true);
    }

    public function testItEnforcesRequiredTypesAndBounds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('input.limit');

        (new JsonSchemaValidator())->assertValid(['limit' => 51], [
            'type' => 'object',
            'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]],
            'required' => ['limit'],
            'additionalProperties' => false,
        ], 'input');
    }

    private function collectionSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'label' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'label'],
                    ],
                ],
                'count' => ['type' => 'integer'],
            ],
            'required' => ['items', 'count'],
        ];
    }
}
