<?php

namespace App\Services\Workers;

use App\Exceptions\WorkerTaskProcessingException;
use App\Services\Workers\Receipts\ReceiptLineFactory;
use App\Services\Workers\Receipts\ReceiptPrototypeRepository;
use Epsalibrary\Contracts\ImportManagerInterface;

class ReceiptMigrationService
{
    public function __construct(
        private readonly ImportManagerInterface $importManager,
        private readonly EpsaSoapConfigurationValidator $soapConfigurationValidator,
        private readonly ReceiptPrototypeRepository $repository,
        private readonly ReceiptLineFactory $lineFactory
    ) {
    }

    public function handle(array $payload): array
    {
        $header = $this->repository->findHeader($payload);
        $payments = $this->repository->findPayments($payload);
        $lines = $this->lineFactory->build($header, $payments);

        $this->soapConfigurationValidator->validate();

        $result = $this->importManager->import($lines);

        if (!$result->success) {
            throw new WorkerTaskProcessingException(
                $result->message,
                [
                    'errors' => $result->errors,
                    'payload' => $payload,
                    'xml_payload' => $result->payload,
                ]
            );
        }

        return [
            'document_id' => $payload['document_id'] ?? $this->buildReference($payload),
            'source' => $payload['source'] ?? null,
            'message' => $result->message,
            'errors' => $result->errors,
            'import_payload' => $result->payload,
            'line_count' => count($lines),
            'payment_count' => count($payments),
            'receipt_reference' => $this->buildReference($payload),
        ];
    }

    private function buildReference(array $payload): string
    {
        return implode('-', array_filter([
            trim((string) ($payload['operational_center'] ?? '')),
            trim((string) ($payload['document_type'] ?? '')),
            trim((string) ($payload['document_number'] ?? '')),
        ], static fn (string $value): bool => $value !== ''));
    }
}
