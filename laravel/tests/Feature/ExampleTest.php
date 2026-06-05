<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_guests_to_register_when_no_users_exist(): void
    {
        $this->get('/')->assertRedirect('/register');
    }

    public function test_root_redirects_guests_to_login_when_users_exist(): void
    {
        User::factory()->create();

        $this->get('/')->assertRedirect(route('login'));
    }
}
