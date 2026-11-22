<?php

namespace Tests\Feature\Ambassador;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProgramDocumentLinkTest extends TestCase
{
    private string $temporaryDocumentPath;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->temporaryDocumentPath = tempnam(sys_get_temp_dir(), 'program-document');
        file_put_contents($this->temporaryDocumentPath, "# Programme secret\n\nContenu <script>alert(1)</script> réservé.");
        config(['app.ambassador_program_document_path' => $this->temporaryDocumentPath]);
    }

    protected function tearDown(): void
    {
        @unlink($this->temporaryDocumentPath);

        parent::tearDown();
    }

    private function oneTimeUrl(int $daysBeforeExpiry = 7): string
    {
        return URL::temporarySignedRoute('ambassador-program-document', now()->addDays($daysBeforeExpiry), ['link' => Str::uuid()->toString()]);
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
        $this->get('http://'.config('app.ambassador_domain').'/programme/'.Str::uuid())
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

    public function test_missing_document_file_does_not_burn_the_link(): void
    {
        $oneTimeUrl = $this->oneTimeUrl();
        config(['app.ambassador_program_document_path' => '/nonexistent/file.md']);

        $this->get($oneTimeUrl)->assertStatus(503);

        config(['app.ambassador_program_document_path' => $this->temporaryDocumentPath]);
        $this->get($oneTimeUrl)->assertOk()->assertSee('Programme secret');
    }

    public function test_command_prints_a_signed_one_time_url(): void
    {
        $this->artisan('ambassador:create-program-document-link', ['--days' => 3])
            ->expectsOutputToContain('/programme/')
            ->assertSuccessful();
    }
}
