<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_login_page_is_available(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }
}
