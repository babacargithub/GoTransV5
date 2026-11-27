<div class="mx-auto w-full max-w-3xl">
    <div>
        <flux:heading size="xl" level="1">Captures d'écran</flux:heading>
        <flux:text class="mt-1">Envoyez les captures des membres de vos groupes : jusqu'à {{ $maximumImagesPerBatch }} images par envoi.</flux:text>
    </div>

    <flux:separator class="my-6" variant="subtle" />

    <div
        x-data="{
            maximumImagesPerBatch: {{ $maximumImagesPerBatch }},
            maximumImagesPerRequest: 5,
            maximumBytesPerRequest: 8 * 1024 * 1024,
            selectedFiles: [],
            isSubmitting: false,
            storedCount: 0,
            errorMessage: '',
            successMessage: '',
            chunkProgressPercent: 0,
            pickFiles(event) {
                this.selectedFiles = Array.from(event.target.files);
                this.errorMessage = this.selectedFiles.length > this.maximumImagesPerBatch
                    ? 'Maximum ' + this.maximumImagesPerBatch + ' images par envoi.'
                    : '';
                this.successMessage = '';
            },
            splitIntoChunks(files) {
                const chunks = [];
                let currentChunk = [];
                let currentBytes = 0;
                for (const file of files) {
                    const chunkIsFull = currentChunk.length >= this.maximumImagesPerRequest
                        || (currentChunk.length > 0 && currentBytes + file.size > this.maximumBytesPerRequest);
                    if (chunkIsFull) {
                        chunks.push(currentChunk);
                        currentChunk = [];
                        currentBytes = 0;
                    }
                    currentChunk.push(file);
                    currentBytes += file.size;
                }
                if (currentChunk.length > 0) {
                    chunks.push(currentChunk);
                }
                return chunks;
            },
            uploadChunkOnce(chunk) {
                return new Promise((resolve, reject) => {
                    $wire.uploadMultiple('pendingImages', chunk, resolve, (uploadErrors) => reject(new Error('L\'envoi d\'une image a échoué' + (uploadErrors ? ' (' + String(uploadErrors).slice(0, 200) + ')' : '') + '.')), (event) => this.chunkProgressPercent = event.detail.progress);
                });
            },
            async uploadChunk(chunk) {
                const maximumAttempts = 3;
                for (let attemptNumber = 1; attemptNumber <= maximumAttempts; attemptNumber++) {
                    try {
                        return await this.uploadChunkOnce(chunk);
                    } catch (uploadError) {
                        if (attemptNumber === maximumAttempts) {
                            throw uploadError;
                        }
                        await new Promise((resolveDelay) => setTimeout(resolveDelay, 1000 * attemptNumber));
                    }
                }
            },
            async submit() {
                if (this.selectedFiles.length === 0 || this.selectedFiles.length > this.maximumImagesPerBatch || this.isSubmitting) {
                    return;
                }
                this.isSubmitting = true;
                this.errorMessage = '';
                this.successMessage = '';
                this.storedCount = 0;
                let batchWasCreated = false;
                try {
                    const startResult = await $wire.startBatch();
                    if (startResult.error) {
                        throw new Error(startResult.error);
                    }
                    batchWasCreated = true;
                    const chunksToSend = this.splitIntoChunks(this.selectedFiles);
                    const totalChunkCount = chunksToSend.length;
                    let currentChunkNumber = 0;
                    for (const chunk of chunksToSend) {
                        currentChunkNumber++;
                        try {
                            await this.uploadChunk(chunk);
                        } catch (chunkError) {
                            console.error('Screenshot upload failed', chunkError);
                            throw new Error('Lot ' + currentChunkNumber + '/' + totalChunkCount + ' : ' + chunkError.message);
                        }
                        const attachResult = await $wire.attachPendingImages(startResult.batchUuid);
                        if (attachResult.error) {
                            throw new Error(attachResult.error);
                        }
                        this.storedCount += attachResult.storedCount;
                    }
                    this.successMessage = this.storedCount + ' image(s) envoyée(s).';
                    this.selectedFiles = [];
                    this.$refs.imagePicker.value = '';
                } catch (submissionError) {
                    this.errorMessage = submissionError.message + (this.storedCount > 0 ? ' ' + this.storedCount + ' image(s) déjà enregistrée(s) dans ce lot.' : '');
                } finally {
                    this.isSubmitting = false;
                    if (batchWasCreated) {
                        await $wire.finishSubmission();
                    }
                }
            },
        }"
        class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
    >
        <flux:input wire:model="note" label="Les fichiers sont issues de ?" placeholder="Ex. : groupe L1 Droit, Résidence B" maxlength="150" required x-bind:disabled="isSubmitting" />

        <div>
            <flux:label>Images</flux:label>
            <input
                type="file"
                multiple
                accept="image/jpeg,image/png,image/webp"
                x-ref="imagePicker"
                x-on:change="pickFiles($event)"
                x-bind:disabled="isSubmitting"
                class="mt-2 block w-full text-sm text-zinc-700 dark:text-zinc-300 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-cyan file:px-4 file:py-2 file:font-semibold file:text-white"
            >
            <flux:text class="mt-1 text-sm" x-show="selectedFiles.length > 0" x-cloak>
                <span x-text="selectedFiles.length"></span> image(s) choisie(s)
            </flux:text>
        </div>

        <div x-show="isSubmitting" x-cloak class="space-y-1">
            <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                <div class="h-full bg-brand-cyan transition-all" x-bind:style="'width: ' + chunkProgressPercent + '%'"></div>
            </div>
            <flux:text class="text-sm">Envoi en cours… <span x-text="storedCount"></span> / <span x-text="selectedFiles.length"></span> enregistrée(s). Ne fermez pas la page.</flux:text>
        </div>

        <p x-show="errorMessage" x-cloak x-text="errorMessage" class="text-sm text-red-600 dark:text-red-400" role="alert"></p>
        <p x-show="successMessage" x-cloak x-text="successMessage" class="text-sm text-green-700 dark:text-green-400"></p>

        <flux:button
            type="button"
            variant="primary"
            icon="arrow-up-tray"
            class="w-full"
            x-on:click="submit()"
            x-bind:disabled="isSubmitting || selectedFiles.length === 0 || selectedFiles.length > maximumImagesPerBatch"
        >
            Envoyer les captures
        </flux:button>
    </div>

    <flux:heading size="lg" level="2" class="mt-8">Mes envois</flux:heading>

    <div class="mt-3 space-y-3">
        @forelse ($batches as $batch)
            <a
                href="{{ route('back-office.screenshot-batches.show', $batch->uuid) }}"
                wire:navigate
                wire:key="screenshot-batch-{{ $batch->id }}"
                class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 p-4 text-zinc-900 hover:bg-zinc-50 dark:border-zinc-700 dark:text-white dark:hover:bg-zinc-800"
            >
                <div class="min-w-0">
                    <div class="truncate font-medium">{{ $batch->note ?? 'Sans nom' }}</div>
                    <flux:text class="truncate text-sm">{{ $batch->created_at->locale('fr')->translatedFormat('d F Y à H:i') }} · {{ $batch->file_count }} image(s)</flux:text>
                </div>
                <flux:badge size="sm" :color="$batch->status->badgeColor()">{{ $batch->status->label() }}</flux:badge>
            </a>
        @empty
            <flux:text>Vous n'avez encore envoyé aucune capture.</flux:text>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $batches->links() }}
    </div>
</div>
