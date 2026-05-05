<?php

namespace App\Data\Auth;

final class BackofficeAuthResult
{
    public function __construct(
        public readonly bool $reachable,
        public readonly bool $authenticated,
        public readonly bool $authorized,
        public readonly bool $active,
        public readonly ?int $roleId,
        public readonly ?int $userId,
        public readonly ?string $email,
        public readonly ?string $name,
        public readonly ?string $message,
        public readonly int $statusCode,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload, int $statusCode): self
    {
        $user = is_array($payload['user'] ?? null) ? $payload['user'] : [];

        return new self(
            reachable: true,
            authenticated: (bool) ($payload['authenticated'] ?? false),
            authorized: (bool) ($payload['authorized'] ?? false),
            active: (bool) ($payload['active'] ?? false),
            roleId: isset($payload['role_id']) ? (int) $payload['role_id'] : null,
            userId: isset($user['id']) ? (int) $user['id'] : null,
            email: isset($user['email']) ? (string) $user['email'] : null,
            name: isset($user['name']) ? (string) $user['name'] : null,
            message: isset($payload['message']) ? (string) $payload['message'] : null,
            statusCode: $statusCode,
        );
    }

    public static function unavailable(string $message): self
    {
        return new self(
            reachable: false,
            authenticated: false,
            authorized: false,
            active: false,
            roleId: null,
            userId: null,
            email: null,
            name: null,
            message: $message,
            statusCode: 503,
        );
    }

    public function isAllowed(): bool
    {
        return $this->reachable
            && $this->authenticated
            && $this->authorized
            && $this->active
            && $this->roleId === (int) config('workerhub.backoffice.admin_role_id', 20);
    }
}
