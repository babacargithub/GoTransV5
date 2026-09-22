<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicWebsiteHomeTest extends TestCase
{
    private function websiteUrl(string $path = '/'): string
    {
        return 'http://'.config('app.public_website_domain').$path;
    }

    public function test_the_public_website_home_renders_on_its_own_domain(): void
    {
        $response = $this->get($this->websiteUrl('/'));

        $response->assertStatus(200);
        $response->assertViewIs('website.home');
        $response->assertViewHas('trajets');
        $response->assertSee('Nos caravanes');
    }

    public function test_the_shared_website_layout_emits_the_seo_tags(): void
    {
        $publicWebsiteDomain = config('app.public_website_domain');

        $response = $this->get($this->websiteUrl('/'));

        $response->assertSee('<title>Globe One Transport — Bus Saint-Louis (UGB) ↔ Dakar</title>', false);
        $response->assertSee('<meta name="description" content="Réservez votre trajet en bus', false);
        $response->assertSee('<meta name="robots" content="index, follow">', false);
        $response->assertSee('<link rel="canonical" href="http://'.$publicWebsiteDomain.'">', false);
        $response->assertSee('<meta property="og:title" content="Globe One Transport — Bus Saint-Louis (UGB) ↔ Dakar">', false);
        $response->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
        $response->assertSee('application/ld+json', false);
        $response->assertSee('"@type":"Organization"', false);
    }

    public function test_the_public_website_home_route_is_named(): void
    {
        $this->assertSame(
            'http://'.config('app.public_website_domain'),
            route('website.home'),
        );
    }
}
