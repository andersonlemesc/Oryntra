<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Actions\AgentTools\UpdateChatwootContact;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\UpdateChatwootContactRequest;
use Illuminate\Http\JsonResponse;

class UpdateChatwootContactController extends Controller
{
    public function __invoke(
        UpdateChatwootContactRequest $request,
        UpdateChatwootContact $action,
    ): JsonResponse {
        /** @var array{workspace_id:int,agent_id:int,agent_run_id:int,specialist_id?:int|null,contact_id:int,name?:string|null,email?:string|null,phone_number?:string|null} $payload */
        $payload = $request->validated();

        return response()->json($action->execute($payload));
    }
}
