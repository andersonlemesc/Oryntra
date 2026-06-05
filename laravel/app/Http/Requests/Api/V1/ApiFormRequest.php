<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for /api/v1 form requests. Exposes the workspace resolved by the
 * api.workspace middleware and a helper to scope `exists` rules to it.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function workspace(): Workspace
    {
        /** @var Workspace $workspace */
        $workspace = $this->attributes->get('workspace');

        return $workspace;
    }

    public function workspaceId(): int
    {
        return (int) $this->workspace()->getKey();
    }
}
