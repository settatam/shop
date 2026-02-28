<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_terms_page_is_accessible(): void
    {
        $response = $this->get('/terms');

        $response->assertStatus(200);
    }

    public function test_privacy_page_is_accessible(): void
    {
        $response = $this->get('/privacy');

        $response->assertStatus(200);
    }

    public function test_registration_fails_without_terms_accepted(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'store_name' => 'Test Store',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('terms_accepted');
    }

    public function test_registration_fails_when_terms_not_accepted(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'store_name' => 'Test Store',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => '0',
        ]);

        $response->assertSessionHasErrors('terms_accepted');
    }

    public function test_registration_succeeds_with_terms_accepted(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'store_name' => 'Test Store',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => '1',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    }
}
