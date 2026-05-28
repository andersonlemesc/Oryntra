<?php

declare(strict_types=1);

use App\Services\AgentTools\ExternalToolSchemaBuilder;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->builder = new ExternalToolSchemaBuilder;
});

it('converts repeater rows into a normalized param schema', function () {
    $schema = $this->builder->fromRepeater([
        ['name' => 'order_id', 'type' => 'string', 'description' => 'ID', 'location' => 'path', 'required' => true],
        ['name' => 'page', 'type' => 'integer', 'description' => '', 'location' => 'query', 'required' => false],
        ['name' => '', 'type' => 'string', 'location' => 'query'],
    ]);

    expect($schema['properties'])->toHaveKeys(['order_id', 'page'])
        ->and($schema['properties'])->not->toHaveKey('')
        ->and($schema['properties']['order_id']['location'])->toBe('path')
        ->and($schema['properties']['order_id']['required'])->toBeTrue()
        ->and($schema['properties']['page']['type'])->toBe('integer');
});

it('round-trips repeater <-> schema without losing fields', function () {
    $rows = [
        ['name' => 'order_id', 'type' => 'string', 'description' => 'ID do pedido', 'location' => 'path', 'required' => true],
    ];

    $back = $this->builder->toRepeater($this->builder->fromRepeater($rows));

    expect($back)->toBe($rows);
});

it('accepts a valid schema', function () {
    $this->builder->validate([
        'properties' => [
            'order_id' => ['type' => 'string', 'description' => 'ID', 'location' => 'query', 'required' => true],
        ],
    ]);
})->throwsNoExceptions();

it('rejects a non-snake_case parameter name', function () {
    $this->builder->validate([
        'properties' => ['OrderId' => ['type' => 'string', 'location' => 'query']],
    ]);
})->throws(InvalidArgumentException::class);

it('rejects an unknown parameter type', function () {
    $this->builder->validate([
        'properties' => ['order_id' => ['type' => 'object', 'location' => 'query']],
    ]);
})->throws(InvalidArgumentException::class);

it('rejects an invalid parameter location', function () {
    $this->builder->validate([
        'properties' => ['order_id' => ['type' => 'string', 'location' => 'cookie']],
    ]);
})->throws(InvalidArgumentException::class);

it('parses a raw JSON schema from the advanced editor', function () {
    $schema = $this->builder->fromJson('{"properties":{"order_id":{"type":"string","location":"query"}}}');

    expect($schema['properties']['order_id']['type'])->toBe('string');
});

it('treats empty JSON as an empty schema', function () {
    expect($this->builder->fromJson('  '))->toBe(['properties' => []]);
});

it('rejects malformed JSON', function () {
    $this->builder->fromJson('{not json');
})->throws(InvalidArgumentException::class);
