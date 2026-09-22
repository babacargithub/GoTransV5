<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomepageTest extends TestCase
{
    public function test_the_homepage_renders_successfully(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertViewIs('homepage');
        $response->assertSee('Bienvenue sur Globe One Transport');
    }

    public function test_the_homepage_shows_the_header_and_sidebar_shell(): void
    {
        $response = $this->get('/');

        $response->assertSee('data-flux-header', false);
        $response->assertSee('data-flux-sidebar', false);
    }
}
