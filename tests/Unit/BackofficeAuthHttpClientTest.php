<?php

namespace Tests\Unit;

use App\Services\Auth\BackofficeAuthHttpClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackofficeAuthHttpClientTest extends TestCase
{
    public function test_it_maps_a_successful_operator_authorization_response(): void
    {
        config()->set('workerhub.backoffice.base_url', 'https://backoffice.test');
        config()->set('workerhub.backoffice.shared_token', 'shared-token');

        Http::fake([
            'https://backoffice.test/api/internal/workerhub/operators/authenticate' => Http::response([
                'authenticated' => true,
                'authorized' => true,
                'active' => true,
                'role_id' => 20,
                'user' => [
                    'id' => 10,
                    'email' => 'admin@comodisimos.com',
                    'name' => 'Admin',
                ],
            ], 200),
        ]);

        $result = app(BackofficeAuthHttpClient::class)->authenticateOperator('admin@comodisimos.com', 'secret');

        $this->assertTrue($result->reachable);
        $this->assertTrue($result->isAllowed());
        $this->assertSame('admin@comodisimos.com', $result->email);
    }

    public function test_it_marks_the_dependency_as_unavailable_when_connection_fails(): void
    {
        config()->set('workerhub.backoffice.base_url', 'https://backoffice.test');

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('connection timeout');
        });

        $result = app(BackofficeAuthHttpClient::class)->authenticateOperator('admin', 'secret');

        $this->assertFalse($result->reachable);
        $this->assertSame(503, $result->statusCode);
    }
}
