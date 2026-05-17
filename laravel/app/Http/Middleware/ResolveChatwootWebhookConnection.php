<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ChatwootConnectionStatus;
use App\Models\ChatwootConnection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveChatwootWebhookConnection
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $connectionUuid = (string) $request->route('connectionUuid');
        $connection = ChatwootConnection::query()
            ->where('connection_uuid', $connectionUuid)
            ->first();

        if (! $connection || $connection->status !== ChatwootConnectionStatus::Active) {
            return response()->json(['message' => 'Webhook connection not found.'], 404);
        }

        if (! $this->hasValidSignature($request, $connection)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $request->attributes->set('chatwoot_connection', $connection);

        return $next($request);
    }

    private function hasValidSignature(Request $request, ChatwootConnection $connection): bool
    {
        $secret = (string) $connection->webhook_secret;
        $signature = (string) $request->header('X-Chatwoot-Signature');

        if ($secret === '') {
            return filled($connection->agent_bot_id) && filled($connection->api_access_token);
        }

        if ($signature === '') {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $request->getContent(), $secret);
        $signature = str($signature)->after('sha256=')->toString();

        return hash_equals($expectedSignature, $signature);
    }
}
