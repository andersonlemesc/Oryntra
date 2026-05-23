<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Actions\AgentTools\GetChatwootContact;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\GetChatwootContactRequest;
use Illuminate\Http\JsonResponse;

class GetChatwootContactController extends Controller
{
    public function __invoke(
        GetChatwootContactRequest $request,
        GetChatwootContact $action,
    ): JsonResponse {
        /** @var array{workspace_id:int,agent_id:int,agent_run_id:int,specialist_id?:int|null,contact_id:int} $payload */
        $payload = $request->validated();

        return response()->json($action->execute($payload));
    }
}
