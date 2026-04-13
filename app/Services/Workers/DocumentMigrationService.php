<?php

namespace App\Services\Workers;

use App\Exceptions\WorkerTaskProcessingException;
use Epsalibrary\Contracts\ImportManagerInterface;
use Illuminate\Contracts\Config\Repository;

class DocumentMigrationService
{
    public function __construct(
        private readonly ImportManagerInterface $importManager,
        private readonly Repository $config
    )
    {
    }

    public function handle(array $payload): array
    {
        $lines = $payload['lines'] ?? null;

        if (!is_array($lines) || $lines === []) {
            throw new WorkerTaskProcessingException(
                'El payload de document_migration debe incluir lines como arreglo no vacio.',
                ['payload' => $payload]
            );
        }

        $normalizedLines = array_map(static function ($line): string {
            if (!is_scalar($line)) {
                throw new WorkerTaskProcessingException('Cada linea a importar debe ser escalar.');
            }

            return (string) $line;
        }, $lines);

        $this->ensureSoapConfigurationIsValid();

        $result = $this->importManager->import($normalizedLines);

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
            'document_id' => $payload['document_id'] ?? null,
            'source' => $payload['source'] ?? null,
            'message' => $result->message,
            'errors' => $result->errors,
            'import_payload' => $result->payload,
            'line_count' => count($normalizedLines),
        ];
    }

    private function ensureSoapConfigurationIsValid(): void
    {
        $soapConfig = (array) $this->config->get('epsa_library.soap', []);
        $url = trim((string) ($soapConfig['url'] ?? ''));
        $user = trim((string) ($soapConfig['user'] ?? ''));
        $password = trim((string) ($soapConfig['password'] ?? ''));
        $connection = trim((string) ($soapConfig['connection'] ?? ''));

        $missing = [];

        if ($url === '') {
            $missing[] = 'EPSA_SIESA_SOAP_URL';
        }

        if ($user === '') {
            $missing[] = 'EPSA_SIESA_SOAP_USER';
        }

        if ($password === '') {
            $missing[] = 'EPSA_SIESA_SOAP_PASSWORD';
        }

        if ($connection === '') {
            $missing[] = 'EPSA_SIESA_SOAP_CONNECTION';
        }

        if ($missing !== []) {
            throw new WorkerTaskProcessingException(
                'La configuracion SOAP de epsa_library esta incompleta. Configura: ' . implode(', ', $missing) . '.',
                [
                    'missing_configuration' => $missing,
                    'soap_url' => $url !== '' ? $url : null,
                ]
            );
        }

        if (!$this->isAbsoluteHttpUrl($url)) {
            throw new WorkerTaskProcessingException(
                'La configuracion SOAP de epsa_library es invalida. EPSA_SIESA_SOAP_URL debe ser una URL absoluta HTTP(S).',
                [
                    'invalid_configuration' => ['EPSA_SIESA_SOAP_URL'],
                    'soap_url' => $url,
                ]
            );
        }
    }

    private function isAbsoluteHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true);
    }
}
