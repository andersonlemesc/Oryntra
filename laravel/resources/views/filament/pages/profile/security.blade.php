<x-filament-panels::page>
    {{-- Trocar senha --}}
    <x-filament::section>
        <x-slot name="heading">Trocar senha</x-slot>

        <form wire:submit="updatePassword" class="space-y-4">
            {{ $this->passwordForm }}

            <div class="flex justify-end">
                <x-filament::button type="submit">Atualizar senha</x-filament::button>
            </div>
        </form>
    </x-filament::section>

    {{-- Autenticação em dois fatores --}}
    <x-filament::section>
        <x-slot name="heading">Autenticação em dois fatores (2FA)</x-slot>
        <x-slot name="description">Use um app autenticador (Google Authenticator, 1Password, etc).</x-slot>

        @if (! $this->twoFactorEnabled())
            <p class="text-sm text-gray-500 mb-4">2FA está desativado.</p>
            <x-filament::button wire:click="enableTwoFactor" icon="heroicon-o-lock-closed">
                Ativar 2FA
            </x-filament::button>
        @else
            @if (! $this->twoFactorConfirmed())
                <p class="text-sm text-gray-600 dark:text-gray-300 mb-3">
                    1. Escaneie o QR code abaixo no seu app autenticador.
                </p>
                <div class="mb-4 inline-block rounded-lg bg-white p-3">
                    {!! $this->qrCodeSvg() !!}
                </div>

                <p class="text-sm text-gray-600 dark:text-gray-300 mb-2">
                    2. Digite o código gerado para confirmar:
                </p>
                <div class="flex items-end gap-2 max-w-sm">
                    <x-filament::input.wrapper class="flex-1">
                        <x-filament::input
                            type="text"
                            wire:model="confirmationCode"
                            placeholder="000000"
                            inputmode="numeric"
                        />
                    </x-filament::input.wrapper>
                    <x-filament::button wire:click="confirmTwoFactor">Confirmar</x-filament::button>
                </div>
            @else
                <div class="flex items-center gap-2 mb-4 text-sm text-green-600 dark:text-green-400">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5" />
                    2FA está ativo na sua conta.
                </div>

                <div class="mb-4">
                    <p class="text-sm font-medium mb-2">Códigos de recuperação</p>
                    <p class="text-xs text-gray-500 mb-2">
                        Guarde-os em local seguro. Cada um pode ser usado uma vez se você perder o acesso ao app.
                    </p>
                    <div class="grid grid-cols-2 gap-1 rounded-lg bg-gray-50 dark:bg-white/5 p-3 font-mono text-sm max-w-md">
                        @foreach ($this->recoveryCodes() as $code)
                            <span class="select-all">{{ $code }}</span>
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-filament::button color="gray" wire:click="regenerateRecoveryCodes">
                        Regenerar códigos
                    </x-filament::button>
                    <x-filament::button
                        color="danger"
                        wire:click="disableTwoFactor"
                        wire:confirm="Desativar 2FA? Sua conta ficará menos protegida."
                    >
                        Desativar 2FA
                    </x-filament::button>
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
