<?php

namespace App\Services\Workers;

use App\Exceptions\WorkerTaskProcessingException;
use Illuminate\Contracts\Config\Repository;

class EpsaSoapConfigurationValidator
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function validate(): void
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
