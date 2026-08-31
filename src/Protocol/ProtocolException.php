<?php

namespace AssistantHub\SymfonyConnector\Protocol;

final class ProtocolException extends \RuntimeException
{
    public function __construct(
        public readonly string $protocolCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly bool $retryable = false,
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'error' => [
                'code' => $this->protocolCode,
                'message' => $this->getMessage(),
                'retryable' => $this->retryable,
                'details' => $this->details,
            ],
        ];
    }
}
