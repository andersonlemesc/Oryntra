<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Actions\AgentTools\SendDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\SendDocumentRequest;
use Illuminate\Http\JsonResponse;

class SendDocumentController extends Controller
{
    public function __invoke(
        SendDocumentRequest $request,
        SendDocument $sendDocument,
    ): JsonResponse {
        /** @var array{workspace_id:int,agent_run_id:int,document_id:int,caption?:string,conversation_id:int} $payload */
        $payload = $request->validated();

        $result = $sendDocument->execute($payload);

        return response()->json($result);
    }
}
