<?php

namespace Tests\Feature\Ambassador;

use App\Enums\AmbassadorProgramDocumentType;
use App\Models\AmbassadorProgramDocument;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProgramDocumentLinkTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        AmbassadorProgramDocument::query()->updateOrCreate(
            ['type' => AmbassadorProgramDocumentType::Presentation],
            ['title' => 'Titre confidentiel', 'content' => "# Programme secret\n\nContenu <script>alert(1)</script> réservé."],
        );
    }

    private function oneTimeUrl(int $daysBeforeExpiry = 7, string $documentType = 'presentation'): string
    {
        return URL::temporarySignedRoute('ambassador-program-document', now()->addDays($daysBeforeExpiry), ['documentType' => $documentType, 'link' => Str::uuid()->toString()]);
    }

    public function test_first_open_shows_the_protected_document_and_second_open_is_expired(): void
    {
        $oneTimeUrl = $this->oneTimeUrl();

        $firstOpenResponse = $this->get($oneTimeUrl)
            ->assertOk()
            ->assertSee('Programme secret')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('addEventListener(eventName, blockEvent)', false)
            ->assertSee('user-select: none', false)
            ->assertSee('document--blurred', false)
            ->assertSee('linesVisibleAtOnce = 7', false);

        $this->assertStringContainsString('noindex', $firstOpenResponse->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', $firstOpenResponse->headers->get('Cache-Control'));

        $this->get($oneTimeUrl)
            ->assertStatus(410)
            ->assertSee('Document expiré')
            ->assertDontSee('Programme secret');
    }

    public function test_tampered_url_is_expired(): void
    {
        $this->get($this->oneTimeUrl().'x')
            ->assertStatus(410)
            ->assertSee('Document expiré');
    }

    public function test_unsigned_url_is_expired(): void
    {
        $this->get('http://'.config('app.ambassador_domain').'/programme/presentation/'.Str::uuid())
            ->assertStatus(410)
            ->assertSee('Document expiré');
    }

    public function test_link_past_its_expiry_date_is_expired_even_if_never_opened(): void
    {
        $oneTimeUrl = $this->oneTimeUrl(daysBeforeExpiry: 1);

        $this->travel(2)->days();

        $this->get($oneTimeUrl)
            ->assertStatus(410)
            ->assertSee('Document expiré');
    }

    public function test_link_preview_crawlers_do_not_consume_the_link(): void
    {
        $oneTimeUrl = $this->oneTimeUrl();

        $this->get($oneTimeUrl, ['User-Agent' => 'WhatsApp/2.23.20 A'])
            ->assertOk()
            ->assertDontSee('Programme secret');

        $this->get($oneTimeUrl, ['User-Agent' => 'Mozilla/5.0 (iPhone) Safari'])
            ->assertOk()
            ->assertSee('Programme secret');
    }

    public function test_missing_document_does_not_burn_the_link(): void
    {
        $oneTimeUrl = $this->oneTimeUrl(documentType: 'contract');
        AmbassadorProgramDocument::query()->where('type', AmbassadorProgramDocumentType::Contract)->delete();

        $this->get($oneTimeUrl)->assertStatus(503);

        AmbassadorProgramDocument::factory()->contract()->create(['title' => 'Contrat signé', 'content' => 'Clauses du contrat']);
        $this->get($oneTimeUrl)->assertOk()->assertSee('Clauses du contrat')->assertSee('Contrat signé');
    }

    public function test_document_type_in_the_url_selects_the_document(): void
    {
        $this->get($this->oneTimeUrl())->assertOk()->assertSee('Titre confidentiel')->assertDontSee('Clauses du contrat');
    }

    public function test_unknown_document_type_is_unavailable_without_burning_the_link(): void
    {
        $this->get($this->oneTimeUrl(documentType: 'unknown'))->assertStatus(503);
    }

    public function test_command_prints_a_signed_one_time_url(): void
    {
        $this->artisan('ambassador:create-program-document-link', ['--days' => 3])
            ->expectsOutputToContain('/programme/presentation/')
            ->assertSuccessful();
    }
}
