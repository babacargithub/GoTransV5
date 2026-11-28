<?php

namespace Tests\Feature;

use App\Enums\ScreenshotBatchStatus;
use App\Livewire\BackOffice\ScreenshotBatchList;
use App\Livewire\BackOffice\ScreenshotBatchShow;
use App\Models\ScreenshotBatch;
use App\Models\User;
use App\Services\ScreenshotBatchService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class ScreenshotBatchTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ScreenshotBatchService::DISK);
    }

    public function test_starting_a_submission_creates_a_pending_batch_for_the_logged_in_agent(): void
    {
        $fieldAgent = User::factory()->create();

        $startResult = Livewire::actingAs($fieldAgent)->test(ScreenshotBatchList::class)
            ->set('note', 'Groupe L1 Droit')
            ->call('startBatch')
            ->assertReturned(fn (array $result): bool => $result['error'] === null && $result['batchUuid'] !== null);

        $batch = ScreenshotBatch::query()->where('user_id', $fieldAgent->id)->latest('id')->firstOrFail();
        $this->assertSame('Groupe L1 Droit', $batch->note);
        $this->assertSame(ScreenshotBatchStatus::Pending, $batch->status);
        $this->assertSame(0, $batch->file_count);
    }

    public function test_starting_a_submission_without_a_name_is_refused_and_creates_no_batch(): void
    {
        $fieldAgent = User::factory()->create();

        Livewire::actingAs($fieldAgent)->test(ScreenshotBatchList::class)
            ->set('note', '   ')
            ->call('startBatch')
            ->assertReturned(fn (array $result): bool => $result['batchUuid'] === null && $result['error'] === 'Indiquez d\'où viennent les fichiers.');

        $this->assertSame(0, ScreenshotBatch::query()->where('user_id', $fieldAgent->id)->count());
    }

    public function test_attached_images_are_stored_in_the_batch_folder_and_counted(): void
    {
        $fieldAgent = User::factory()->create();
        $batch = ScreenshotBatch::factory()->create(['user_id' => $fieldAgent->id]);

        Livewire::actingAs($fieldAgent)->test(ScreenshotBatchList::class)
            ->set('pendingImages', [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.jpg')])
            ->call('attachPendingImages', $batch->uuid)
            ->assertReturned(['storedCount' => 2, 'error' => null]);

        $this->assertSame(2, $batch->fresh()->file_count);
        $this->assertCount(2, Storage::disk(ScreenshotBatchService::DISK)->files('screenshot-batches/'.$batch->uuid));
    }

    public function test_a_file_that_is_not_an_image_is_refused_and_nothing_is_stored(): void
    {
        $fieldAgent = User::factory()->create();
        $batch = ScreenshotBatch::factory()->create(['user_id' => $fieldAgent->id]);

        Livewire::actingAs($fieldAgent)->test(ScreenshotBatchList::class)
            ->set('pendingImages', [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->call('attachPendingImages', $batch->uuid)
            ->assertReturned(fn (array $result): bool => $result['storedCount'] === 0 && $result['error'] !== null);

        $this->assertSame(0, $batch->fresh()->file_count);
    }

    public function test_an_agent_cannot_attach_images_to_the_batch_of_another_agent(): void
    {
        $otherAgentsBatch = ScreenshotBatch::factory()->create();

        Livewire::actingAs(User::factory()->create())->test(ScreenshotBatchList::class)
            ->set('pendingImages', [UploadedFile::fake()->image('a.png')])
            ->call('attachPendingImages', $otherAgentsBatch->uuid)
            ->assertReturned(['storedCount' => 0, 'error' => 'Lot introuvable.']);

        $this->assertSame(0, $otherAgentsBatch->fresh()->file_count);
    }

    public function test_a_batch_never_goes_over_two_hundred_images(): void
    {
        $batch = ScreenshotBatch::factory()->create(['file_count' => 199]);

        $this->expectException(DomainException::class);

        app(ScreenshotBatchService::class)->addImagesToBatch($batch, [
            UploadedFile::fake()->image('a.png'),
            UploadedFile::fake()->image('b.png'),
        ]);
    }

    public function test_the_list_only_shows_the_batches_of_the_logged_in_agent(): void
    {
        $fieldAgent = User::factory()->create();
        ScreenshotBatch::factory()->create(['user_id' => $fieldAgent->id, 'note' => 'Mon lot']);
        ScreenshotBatch::factory()->create(['note' => 'Lot d\'un collègue']);

        Livewire::actingAs($fieldAgent)->test(ScreenshotBatchList::class)
            ->assertSee('Mon lot')
            ->assertDontSee('Lot d\'un collègue');
    }

    public function test_a_full_access_user_sees_opens_and_downloads_the_batches_of_every_agent(): void
    {
        $fullAccessUser = $this->createUserWithFullAccess();
        $batch = ScreenshotBatch::factory()->create(['note' => 'Lot d\'un collègue']);
        app(ScreenshotBatchService::class)->addImagesToBatch($batch, [UploadedFile::fake()->image('a.png')]);
        $storedFileName = app(ScreenshotBatchService::class)->imageFileNames($batch)[0];

        Livewire::actingAs($fullAccessUser)->test(ScreenshotBatchList::class)
            ->assertSee('Lot d\'un collègue')
            ->assertSee($batch->user->name);
        Livewire::actingAs($fullAccessUser)->test(ScreenshotBatchShow::class, ['screenshotBatch' => $batch])
            ->assertOk();
        $this->actingAs($fullAccessUser)
            ->get(route('back-office.screenshot-batches.image', [$batch->uuid, $storedFileName]))
            ->assertOk();
        $this->actingAs($fullAccessUser)
            ->get(route('back-office.screenshot-batches.download', $batch->uuid))
            ->assertOk();
    }

    public function test_an_agent_opens_their_batch_and_sees_its_images(): void
    {
        $fieldAgent = User::factory()->create();
        $batch = ScreenshotBatch::factory()->create(['user_id' => $fieldAgent->id]);
        app(ScreenshotBatchService::class)->addImagesToBatch($batch, [UploadedFile::fake()->image('a.png')]);
        $storedFileName = app(ScreenshotBatchService::class)->imageFileNames($batch)[0];

        Livewire::actingAs($fieldAgent)->test(ScreenshotBatchShow::class, ['screenshotBatch' => $batch])
            ->assertSeeHtml($storedFileName);

        $this->actingAs($fieldAgent)
            ->get(route('back-office.screenshot-batches.image', [$batch->uuid, $storedFileName]))
            ->assertOk();
    }

    public function test_an_agent_downloads_all_the_images_of_their_batch_as_a_zip(): void
    {
        $fieldAgent = User::factory()->create();
        $batch = ScreenshotBatch::factory()->create(['user_id' => $fieldAgent->id, 'note' => 'Groupe L1 Droit']);
        app(ScreenshotBatchService::class)->addImagesToBatch($batch, [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.jpg')]);

        $response = $this->actingAs($fieldAgent)
            ->get(route('back-office.screenshot-batches.download', $batch->uuid))
            ->assertOk()
            ->assertDownload('groupe-l1-droit.zip');

        $zipArchive = new ZipArchive;
        $this->assertTrue($zipArchive->open($response->baseResponse->getFile()->getPathname()));
        $this->assertSame(2, $zipArchive->numFiles);
        $this->assertSame(['capture-001.png', 'capture-002.jpg'], [$zipArchive->getNameIndex(0), $zipArchive->getNameIndex(1)]);
        $zipArchive->close();
    }

    public function test_another_agent_cannot_download_the_zip_of_a_batch(): void
    {
        $batch = ScreenshotBatch::factory()->create();
        app(ScreenshotBatchService::class)->addImagesToBatch($batch, [UploadedFile::fake()->image('a.png')]);

        $this->actingAs(User::factory()->create())
            ->get(route('back-office.screenshot-batches.download', $batch->uuid))
            ->assertNotFound();
    }

    public function test_another_agent_can_neither_open_a_batch_nor_download_its_images(): void
    {
        $batch = ScreenshotBatch::factory()->create();
        app(ScreenshotBatchService::class)->addImagesToBatch($batch, [UploadedFile::fake()->image('a.png')]);
        $storedFileName = app(ScreenshotBatchService::class)->imageFileNames($batch)[0];
        $otherAgent = User::factory()->create();

        Livewire::actingAs($otherAgent)->test(ScreenshotBatchShow::class, ['screenshotBatch' => $batch])
            ->assertNotFound();

        $this->actingAs($otherAgent)
            ->get(route('back-office.screenshot-batches.image', [$batch->uuid, $storedFileName]))
            ->assertNotFound();
    }
}
