<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_starts_at_the_installer(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/install/1');
    }
}
