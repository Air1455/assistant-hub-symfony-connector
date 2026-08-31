<?php

namespace AssistantHub\SymfonyConnector\Protocol;

final readonly class CapabilityDefinition
{
    /**
     * @param array<string, mixed> $inputSchema
     * @param array<string, mixed> $outputSchema
     * @param list<string> $requiredRoles
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $kind,
        public string $title,
        public string $description,
        public array $inputSchema,
        public array $outputSchema,
        public bool $requiresConfirmation,
        public array $requiredRoles = [],
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.-]+$/', $id)) {
            throw new \InvalidArgumentException('Capability id must be explicit and machine-safe.');
        }
        if (!in_array($kind, ['read', 'write'], true)) {
            throw new \InvalidArgumentException('Capability kind must be read or write.');
        }
        if ('write' === $kind && !$requiresConfirmation) {
            throw new \InvalidArgumentException('Every write capability must require confirmation.');
        }
        if ('read' === $kind && $requiresConfirmation) {
            throw new \InvalidArgumentException('A read capability cannot use the write confirmation channel.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'kind' => $this->kind,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'outputSchema' => $this->outputSchema,
            'requiresConfirmation' => $this->requiresConfirmation,
            'requiredRoles' => $this->requiredRoles,
        ];
    }
}
