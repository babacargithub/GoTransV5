<div class="mx-auto w-full max-w-2xl">
    <flux:heading size="xl" level="1">Paramètres</flux:heading>
    <flux:text class="mt-1">Configuration de l'application mobile et du site public</flux:text>

    @if (session('status'))
        <flux:callout class="mt-4" variant="success" icon="check-circle">
            <flux:callout.text>{{ session('status') }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:separator class="my-6" variant="subtle" />

    <form wire:submit="save" class="space-y-8">
        <div class="space-y-6">
            <flux:heading size="lg">Application</flux:heading>

            <flux:field>
                <flux:label>Nom de l'application</flux:label>
                <flux:input wire:model="appName" />
                <flux:error name="appName" />
            </flux:field>

            <flux:field>
                <flux:label>Description</flux:label>
                <flux:textarea wire:model="appDescription" rows="2" />
                <flux:error name="appDescription" />
            </flux:field>

            <flux:field>
                <flux:label>Version minimale</flux:label>
                <flux:input wire:model="minimumVersion" placeholder="1.0.0" />
                <flux:error name="minimumVersion" />
            </flux:field>
        </div>

        <flux:separator variant="subtle" />

        <div class="space-y-6">
            <flux:heading size="lg">Points d'accès</flux:heading>

            <flux:field>
                <flux:label>URL de l'API</flux:label>
                <flux:input wire:model="apiEndpoint" />
                <flux:error name="apiEndpoint" />
            </flux:field>

            <flux:field>
                <flux:label>URL du site public</flux:label>
                <flux:input wire:model="frontEndpoint" />
                <flux:error name="frontEndpoint" />
            </flux:field>

            <flux:field>
                <flux:label>URL du serveur</flux:label>
                <flux:input wire:model="serverEndpoint" />
                <flux:error name="serverEndpoint" />
            </flux:field>
        </div>

        <flux:separator variant="subtle" />

        <div class="space-y-6">
            <flux:heading size="lg">Service client</flux:heading>

            <div class="grid gap-6 sm:grid-cols-3">
                <flux:field>
                    <flux:label>Numéro principal</flux:label>
                    <flux:input wire:model="mainCustomerServiceNumber" inputmode="numeric" />
                    <flux:error name="mainCustomerServiceNumber" />
                </flux:field>

                <flux:field>
                    <flux:label>Deuxième numéro</flux:label>
                    <flux:input wire:model="secondCustomerServiceNumber" inputmode="numeric" />
                    <flux:error name="secondCustomerServiceNumber" />
                </flux:field>

                <flux:field>
                    <flux:label>Troisième numéro</flux:label>
                    <flux:input wire:model="thirdCustomerServiceNumber" inputmode="numeric" />
                    <flux:error name="thirdCustomerServiceNumber" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>Numéro du convoyeur par défaut</flux:label>
                <flux:input wire:model="busAgentDefaultNumber" inputmode="numeric" />
                <flux:error name="busAgentDefaultNumber" />
            </flux:field>
        </div>

        <flux:separator variant="subtle" />

        <div class="space-y-6">
            <flux:heading size="lg">Réservation</flux:heading>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Montant de la réduction (FCFA)</flux:label>
                    <flux:input type="number" wire:model="discountPrice" />
                    <flux:error name="discountPrice" />
                </flux:field>

                <flux:field>
                    <flux:label>Condition de la réduction</flux:label>
                    <flux:input wire:model="discountCondition" placeholder="more_than_1_ticket" />
                    <flux:error name="discountCondition" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>Message d'avertissement avant réservation</flux:label>
                <flux:textarea wire:model="warningMessageBeforeBooking" rows="3" />
                <flux:description>Les variables {ticketPrice}, {payment_method} sont remplacées automatiquement.</flux:description>
                <flux:error name="warningMessageBeforeBooking" />
            </flux:field>
        </div>

        <div class="flex items-center justify-end gap-2">
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                Enregistrer les paramètres
            </flux:button>
        </div>
    </form>
</div>
