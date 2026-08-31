<?php

namespace AssistantHub\SymfonyConnector\Validation;

final class JsonSchemaValidator
{
    public function assertValid(mixed $value, array $schema, string $path = 'value', bool $closedObjects = false): void
    {
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            throw new \InvalidArgumentException(sprintf('Field "%s" does not match an allowed value.', $path));
        }

        $types = $schema['type'] ?? null;
        $types = is_string($types) ? [$types] : (is_array($types) ? array_values(array_filter($types, 'is_string')) : []);
        if ([] !== $types && !$this->matchesOneType($value, $types)) {
            throw new \InvalidArgumentException(sprintf('Field "%s" does not match its declared type.', $path));
        }

        if (is_string($value)) {
            $length = mb_strlen($value);
            if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
                throw new \InvalidArgumentException(sprintf('Field "%s" is too short.', $path));
            }
            if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
                throw new \InvalidArgumentException(sprintf('Field "%s" is too long.', $path));
            }
        }

        if ((is_int($value) || is_float($value)) && !is_bool($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                throw new \InvalidArgumentException(sprintf('Field "%s" is below its minimum.', $path));
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                throw new \InvalidArgumentException(sprintf('Field "%s" is above its maximum.', $path));
            }
            if (isset($schema['exclusiveMinimum']) && $value <= $schema['exclusiveMinimum']) {
                throw new \InvalidArgumentException(sprintf('Field "%s" is below its exclusive minimum.', $path));
            }
            if (isset($schema['exclusiveMaximum']) && $value >= $schema['exclusiveMaximum']) {
                throw new \InvalidArgumentException(sprintf('Field "%s" is above its exclusive maximum.', $path));
            }
        }

        if (is_array($value) && array_is_list($value) && $this->allowsType($types, 'array')) {
            if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
                throw new \InvalidArgumentException(sprintf('Field "%s" has too few items.', $path));
            }
            if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
                throw new \InvalidArgumentException(sprintf('Field "%s" has too many items.', $path));
            }
            if (true === ($schema['uniqueItems'] ?? false)) {
                $encoded = array_map(static fn (mixed $item): string => serialize($item), $value);
                if (count($encoded) !== count(array_unique($encoded))) {
                    throw new \InvalidArgumentException(sprintf('Field "%s" contains duplicate items.', $path));
                }
            }
            if (is_array($schema['items'] ?? null)) {
                foreach ($value as $index => $item) {
                    $this->assertValid($item, $schema['items'], sprintf('%s[%d]', $path, $index), $closedObjects);
                }
            }
        }

        if (is_array($value) && ([] === $value || !array_is_list($value)) && $this->allowsType($types, 'object')) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach (($schema['required'] ?? []) as $required) {
                if (!is_string($required) || !array_key_exists($required, $value)) {
                    throw new \InvalidArgumentException(sprintf('Field "%s" is missing a required property.', $path));
                }
            }
            if (isset($schema['minProperties']) && count($value) < (int) $schema['minProperties']) {
                throw new \InvalidArgumentException(sprintf('Field "%s" has too few properties.', $path));
            }
            if (isset($schema['maxProperties']) && count($value) > (int) $schema['maxProperties']) {
                throw new \InvalidArgumentException(sprintf('Field "%s" has too many properties.', $path));
            }
            $additional = $schema['additionalProperties'] ?? !$closedObjects;
            foreach ($value as $name => $child) {
                if (!is_string($name)) {
                    throw new \InvalidArgumentException(sprintf('Field "%s" contains an invalid property name.', $path));
                }
                if (is_array($properties[$name] ?? null)) {
                    $this->assertValid($child, $properties[$name], $path.'.'.$name, $closedObjects);
                    continue;
                }
                if (false === $additional) {
                    throw new \InvalidArgumentException(sprintf('Field "%s.%s" is not declared.', $path, $name));
                }
                if (is_array($additional)) {
                    $this->assertValid($child, $additional, $path.'.'.$name, $closedObjects);
                }
            }
        }
    }

    /** @param list<string> $types */
    private function matchesOneType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            if (match ($type) {
                'null' => null === $value,
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => (is_int($value) || is_float($value)) && !is_bool($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && ([] === $value || !array_is_list($value)),
                default => false,
            }) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $types */
    private function allowsType(array $types, string $type): bool
    {
        return [] === $types || in_array($type, $types, true);
    }
}
