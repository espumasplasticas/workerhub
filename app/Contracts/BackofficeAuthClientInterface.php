<?php

namespace App\Contracts;

use App\Data\Auth\BackofficeAuthResult;

interface BackofficeAuthClientInterface
{
    public function authenticateOperator(string $username, string $password): BackofficeAuthResult;

    /**
     * @return array{ok: bool, status_code: int|null, message: string|null}
     */
    public function health(): array;
}
