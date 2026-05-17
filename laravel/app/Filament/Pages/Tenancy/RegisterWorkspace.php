<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use App\Models\Workspace;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class RegisterWorkspace extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Criar workspace';
    }

    public static function canView(): bool
    {
        return Auth::check();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome do workspace')
                    ->required()
                    ->maxLength(255)
                    ->autofocus(),
            ]);
    }

    /**
     * @param array{name: string} $data
     */
    protected function handleRegistration(array $data): Model
    {
        $workspace = Workspace::create([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['name']),
            'timezone' => config('app.timezone', 'UTC'),
            'locale' => config('app.locale', 'en'),
        ]);

        $workspace->users()->attach(Auth::id(), ['role' => 'owner']);

        return $workspace;
    }

    private function uniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name) ?: 'workspace';
        $slug = $baseSlug;
        $suffix = 2;

        while (Workspace::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
