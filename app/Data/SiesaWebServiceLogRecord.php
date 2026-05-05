<?php

namespace App\Data;

final class SiesaWebServiceLogRecord
{
    /**
     * @param array<string, mixed>|null $context
     */
    public function __construct(
        public readonly int $id,
        public readonly string $xml,
        public readonly ?array $context = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'xml' => $this->xml,
            'context' => $this->context,
        ];
    }
}
