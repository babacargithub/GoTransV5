<?php

namespace Tests\Feature;

use App\Models\Depart;
use App\Models\Trajet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SeoFilesTest extends TestCase
{
    use DatabaseTransactions;

    private function urlOnDomain(string $domain, string $path): string
    {
        return 'http://'.$domain.$path;
    }

    private function createTrajet(array $overrides = []): Trajet
    {
        return Trajet::create(array_merge([
            'name' => 'Caravane '.uniqid(),
            'departure_city' => 'DAKAR',
            'arrival_city' => 'SAINT-LOUIS',
        ], $overrides));
    }

    public function test_the_sitemap_lists_static_pages_and_visible_caravane_pages_but_not_disabled_ones(): void
    {
        $visibleTrajet = $this->createTrajet();
        $disabledTrajet = $this->createTrajet(['disabled' => true]);

        $response = $this->get($this->urlOnDomain(config('app.public_website_domain'), '/sitemap.xml'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $response->assertSee(route('website.home'), false);
        $response->assertSee(route('website.yobante'), false);
        $response->assertSee(route('website.aide'), false);
        $response->assertSee(route('website.caravanes.show', ['trajet' => $visibleTrajet->slug]), false);
        $response->assertDontSee($disabledTrajet->slug, false);
        $response->assertDontSee('changefreq', false);
        $response->assertDontSee('priority', false);
    }

    public function test_a_caravane_lastmod_is_the_latest_change_of_its_upcoming_departs_only(): void
    {
        $trajetWithUpcomingDepart = $this->createTrajet();
        $trajetWithoutUpcomingDepart = $this->createTrajet();

        $upcomingDepart = Depart::create([
            'name' => 'DEPART UPCOMING '.uniqid(),
            'date' => now()->addDays(3),
            'trajet_id' => $trajetWithUpcomingDepart->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $upcomingDepart->forceFill(['updated_at' => '2026-03-10 08:30:00'])->saveQuietly();

        $passedDepart = Depart::create([
            'name' => 'DEPART PASSED '.uniqid(),
            'date' => now()->subDays(3),
            'trajet_id' => $trajetWithoutUpcomingDepart->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $passedDepart->forceFill(['updated_at' => '2026-03-11 09:00:00'])->saveQuietly();

        $sitemapXml = $this->get($this->urlOnDomain(config('app.public_website_domain'), '/sitemap.xml'))
            ->assertOk()
            ->getContent();

        $upcomingEntry = $this->sitemapEntryFor($sitemapXml, $trajetWithUpcomingDepart->slug);
        $this->assertStringContainsString('<lastmod>2026-03-10T08:30:00', $upcomingEntry);

        $passedOnlyEntry = $this->sitemapEntryFor($sitemapXml, $trajetWithoutUpcomingDepart->slug);
        $this->assertStringNotContainsString('<lastmod>', $passedOnlyEntry);
    }

    public function test_the_public_website_robots_block_transactional_pages_and_point_to_the_sitemap(): void
    {
        $response = $this->get($this->urlOnDomain(config('app.public_website_domain'), '/robots.txt'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertSee('Disallow: /reserver/', false);
        $response->assertSee('Disallow: /reservations/', false);
        $response->assertSee('Sitemap: '.route('website.sitemap'), false);
        $response->assertDontSee('Disallow: /'."\n", false);
    }

    public function test_other_hosts_such_as_the_back_office_are_kept_out_of_search_engines(): void
    {
        $response = $this->get($this->urlOnDomain('backoffice.example.test', '/robots.txt'));

        $response->assertOk();
        $this->assertSame("User-agent: *\nDisallow: /\n", $response->getContent());
    }

    public function test_the_standalone_landing_domains_stay_crawlable(): void
    {
        $response = $this->get($this->urlOnDomain(config('app.concours_domain'), '/robots.txt'));

        $response->assertOk();
        $this->assertSame("User-agent: *\nDisallow:\n", $response->getContent());
    }

    private function sitemapEntryFor(string $sitemapXml, string $trajetSlug): string
    {
        preg_match('#<url>(?:(?!</url>).)*/caravanes/'.preg_quote($trajetSlug, '#').'<(?:(?!</url>).)*</url>#s', $sitemapXml, $matches);
        $this->assertNotEmpty($matches, "No sitemap entry for {$trajetSlug}");

        return $matches[0];
    }
}
