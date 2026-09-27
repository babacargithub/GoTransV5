<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicWebsiteStaticPagesTest extends TestCase
{
    private function publicWebsiteUrl(string $path): string
    {
        return 'http://'.config('app.public_website_domain').$path;
    }

    public function test_the_help_page_lists_the_legacy_questions_and_exposes_them_as_faq_structured_data(): void
    {
        $response = $this->get($this->publicWebsiteUrl('/aide'));

        $response->assertOk();
        $response->assertSee('Foire aux questions');
        $response->assertSee('Comment Payer Mon Ticket ?');
        $response->assertSee('Le ticket n\'est pas remboursable', false);

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $response->getContent(), $jsonLdBlocks);
        $faqPage = collect($jsonLdBlocks[1])
            ->map(fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR))
            ->firstWhere('@type', 'FAQPage');

        $this->assertNotNull($faqPage);
        $this->assertCount(8, $faqPage['mainEntity']);
    }

    public function test_the_yobante_page_shows_the_legacy_contact_numbers_and_pickup_points(): void
    {
        $response = $this->get($this->publicWebsiteUrl('/yobante'));

        $response->assertOk();
        $response->assertSee('Envoyer ou recevoir un Yobbante via nos bus');
        $response->assertSee('tel:+221771163003', false);
        $response->assertSee('tel:+221771271212', false);
        $response->assertSee('Point de récupération UGB: Boutique Globe One en face village B');
        $response->assertSee('Point de récupération DAKAR: Bount Pikine');
    }
}
