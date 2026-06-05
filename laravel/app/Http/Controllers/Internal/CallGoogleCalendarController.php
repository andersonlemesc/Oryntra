<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Actions\AgentTools\CallGoogleCalendar;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\CallGoogleCalendarRequest;
use Illuminate\Http\JsonResponse;

class CallGoogleCalendarController extends Controller
{
    public function __invoke(
        CallGoogleCalendarRequest $request,
        CallGoogleCalendar $action,
    ): JsonResponse {
        $result = $action->execute($request->validated());

        return response()->json($result);
    }
}
