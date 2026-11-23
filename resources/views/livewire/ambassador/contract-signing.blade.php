<div class="w-full space-y-6">
    <flux:heading size="xl" level="1">Mon contrat</flux:heading>

    <x-flash-toasts />

    @php($shownContract = $this->signature?->document ?? $this->currentContract)
    @if ($shownContract === null)
        <flux:text>Aucun contrat n'est disponible pour le moment.</flux:text>
    @else
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex items-start justify-between gap-3">
                <flux:heading size="lg" level="2">{{ $shownContract->title }}</flux:heading>
                <flux:badge size="sm">Version {{ $shownContract->version }}</flux:badge>
            </div>

            <article class="prose prose-sm mt-4 max-w-none dark:prose-invert">{!! $this->contractHtml !!}</article>
        </div>

        @if ($this->signature)
            <flux:callout variant="success" icon="check-circle" heading="Contrat signé">
                <flux:callout.text>
                    Vous avez signé la version {{ $shownContract->version }} le {{ $this->signature->signed_at->format('d/m/Y à H:i') }}
                    sous le nom « {{ $this->signature->typed_full_name }} ».
                </flux:callout.text>
            </flux:callout>
        @else
            <flux:callout variant="warning" icon="exclamation-triangle" heading="Signature requise">
                <flux:callout.text>
                    Tant que vous n'avez pas signé le contrat, votre code n'est plus rattaché aux nouveaux clients et vos gains ne peuvent pas être payés.
                </flux:callout.text>
            </flux:callout>

            <form wire:submit="signContract" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading size="lg" level="2">Signer le contrat</flux:heading>

                @if (! $signingCodeWasSent)
                    <div wire:key="signing-step-request-code" class="space-y-4">
                        <flux:field variant="inline">
                            <flux:checkbox wire:model="acceptsContract" />
                            <flux:label>J'ai lu le contrat en entier et je l'accepte.</flux:label>
                            <flux:error name="acceptsContract" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Votre nom complet</flux:label>
                            <flux:input wire:model="typedFullName" placeholder="{{ $this->ambassador->full_name }}" autocomplete="off" />
                            <flux:error name="typedFullName" />
                        </flux:field>

                        <flux:button type="button" variant="primary" icon="chat-bubble-left-ellipsis" wire:click="sendSigningCode" class="w-full">
                            Recevoir mon code par SMS
                        </flux:button>
                        <flux:error name="signingCode" />
                    </div>
                @else
                    <div wire:key="signing-step-enter-code" class="space-y-4">
                        <flux:text>Contrat accepté au nom de <strong>{{ $typedFullName }}</strong>.</flux:text>

                        <flux:field>
                            <flux:label>Code reçu par SMS</flux:label>
                            <flux:input wire:model="signingCode" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="000000" autofocus />
                            <flux:description>Envoyé au {{ $this->ambassador->phone_number }}. Valable {{ \App\Services\AmbassadorContractSigningService::CODE_VALIDITY_MINUTES }} minutes.</flux:description>
                            <flux:error name="signingCode" />
                        </flux:field>

                        <div class="flex flex-col gap-2 sm:flex-row">
                            <flux:button type="submit" variant="primary" icon="pencil-square" class="w-full sm:w-auto">Signer le contrat</flux:button>
                            <flux:button type="button" variant="ghost" wire:click="sendSigningCode" class="w-full sm:w-auto">Renvoyer le code</flux:button>
                            <flux:button type="button" variant="ghost" wire:click="restartSigning" class="w-full sm:w-auto">Modifier mon nom</flux:button>
                        </div>
                    </div>
                @endif
            </form>
        @endif
    @endif
</div>
