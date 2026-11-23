<?php

namespace App\Console\Commands;

use App\Enums\AmbassadorProgramDocumentType;
use Illuminate\Console\Command;

class CreateAmbassadorProgramDocumentLink extends Command
{
    protected $signature = 'ambassador:create-program-document-link {type=presentation : presentation|contract} {--days=7 : Days before an unopened link expires}';

    protected $description = 'Print a one-time signed URL to the ambassador program document (it expires on first open, or after --days)';

    public function handle(): int
    {
        $documentType = AmbassadorProgramDocumentType::tryFrom($this->argument('type'));

        if ($documentType === null) {
            $this->error('Unknown document type, use presentation or contract.');

            return self::FAILURE;
        }

        $oneTimeUrl = $documentType->createOneTimeUrl((int) $this->option('days'));

        $this->info('One-time link (it expires as soon as it is opened once):');
        $this->line($oneTimeUrl);

        return self::SUCCESS;
    }
}
