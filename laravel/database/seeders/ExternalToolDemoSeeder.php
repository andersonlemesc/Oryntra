<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ExternalToolKind;
use App\Models\ExternalTool;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

/**
 * Seeds ready-to-use demo connectors so the external-tool feature can be
 * exercised without filling the Filament form by hand. Idempotent per
 * workspace+slug. Enable the connector on a specialist via the "APIs externas"
 * tab, then trigger a conversation that needs it.
 */
class ExternalToolDemoSeeder extends Seeder
{
    public function run(): void
    {
        $workspaces = Workspace::query()->get();

        if ($workspaces->isEmpty()) {
            $this->command?->warn('No workspaces found — create one before seeding demo connectors.');

            return;
        }

        foreach ($workspaces as $workspace) {
            foreach ($this->connectors() as $connector) {
                ExternalTool::query()->updateOrCreate(
                    ['workspace_id' => $workspace->id, 'slug' => $connector['slug']],
                    $connector,
                );
            }
        }

        $this->command?->info(sprintf(
            'Seeded %d demo connector(s) into %d workspace(s). Enable them on a specialist (aba "APIs externas").',
            count($this->connectors()),
            $workspaces->count(),
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function connectors(): array
    {
        return [
            [
                'slug' => 'consulta_cep',
                'label' => 'Consulta CEP (ViaCEP)',
                'description' => 'Consulta o endereco brasileiro (rua, bairro, cidade, UF) a partir de um CEP. '
                    . 'Use quando o cliente informar um CEP ou pedir para confirmar um endereco de entrega. '
                    . 'Apos consultar, atualize o contato com TODOS os campos retornados (rua, bairro, cidade e UF), '
                    . 'nao apenas o CEP.',
                'kind' => ExternalToolKind::HttpConnector,
                'enabled' => true,
                'config' => [
                    'http_method' => 'GET',
                    'base_url' => 'https://viacep.com.br',
                    'path' => '/ws/{cep}/json/',
                    'auth_type' => 'none',
                    'auth_config' => [],
                    'static_headers' => ['Accept' => 'application/json'],
                    'param_schema' => [
                        'properties' => [
                            'cep' => [
                                'type' => 'string',
                                'description' => 'CEP com 8 digitos, somente numeros (ex: 01001000).',
                                'location' => 'path',
                                'required' => true,
                            ],
                        ],
                    ],
                    'response_extraction' => [
                        'mode' => 'template',
                        'expression' => 'Endereco do CEP {{ cep }}. Atualize o contato com estes campos exatos -> '
                            . 'address_street: {{ logradouro }} | address_neighborhood: {{ bairro }} | '
                            . 'address_city: {{ localidade }} | address_state: {{ uf }} | address_postal_code: {{ cep }}.',
                        'max_length' => 600,
                    ],
                    'timeout_seconds' => null,
                ],
                'credentials' => null,
            ],
        ];
    }
}
