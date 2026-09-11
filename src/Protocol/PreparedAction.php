<?php

namespace AssistantHub\SymfonyConnector\Protocol;

/** Site-generated execution input and plain-text preview; not an execution authorization. */
final readonly class PreparedAction
{
    /**
     * @param array<string, mixed> $input Frozen arguments understood by the capability's execute().
     * @param list<array{label: string, before: ?string, after: ?string}> $changes
     * @param list<string> $notices
     */
    public function __construct(
        public array $input,
        public string $summary,
        public array $changes = [],
        public array $notices = [],
    ) {
        if (($input !== [] && array_is_list($input)) || trim($summary) === '' || strlen($summary) > 12000) {
            throw new \InvalidArgumentException('Invalid prepared action.');
        }
        self::assertPreview($changes, $notices);
        if (strlen(json_encode([$input, $summary, $changes, $notices], JSON_THROW_ON_ERROR)) > 262144) {
            throw new \InvalidArgumentException('Prepared action is too large; narrow its scope.');
        }
    }

    public static function assertPreview(array $changes, array $notices): void
    {
        if (!array_is_list($changes) || count($changes) > 100 || !array_is_list($notices) || count($notices) > 20) {
            throw new \InvalidArgumentException('Invalid proposal preview size.');
        }
        foreach ($changes as $change) {
            if (!is_array($change) || array_diff(array_keys($change), ['label', 'before', 'after'])
                || !is_string($change['label'] ?? null) || trim($change['label']) === ''
                || !array_key_exists('before', $change) || !array_key_exists('after', $change)) {
                throw new \InvalidArgumentException('Invalid proposal change.');
            }
            foreach (['label', 'before', 'after'] as $key) {
                if (($change[$key] !== null && !is_string($change[$key])) || strlen($change[$key] ?? '') > 4000) {
                    throw new \InvalidArgumentException('Preview values must be bounded plain text.');
                }
            }
        }
        foreach ($notices as $notice) {
            if (!is_string($notice) || strlen($notice) > 4000) throw new \InvalidArgumentException('Invalid proposal notice.');
        }
    }
}
