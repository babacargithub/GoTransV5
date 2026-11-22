<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class CreateAmbassadorProgramDocumentLink extends Command
{
    protected $signature = 'ambassador:create-program-document-link {--days=7 : Days before an unopened link expires}';

    protected $description = 'Print a one-time signed URL to the ambassador program document (it expires on first open, or after --days)';

    public function handle(): int
    {
        $oneTimeUrl = URL::temporarySignedRoute(
            'ambassador-program-document',
            now()->addDays((int) $this->option('days')),
            ['link' => Str::uuid()->toString()],
        );

        $this->info('One-time link (it expires as soon as it is opened once):');
        $this->line($oneTimeUrl);

        return self::SUCCESS;
    }
}
