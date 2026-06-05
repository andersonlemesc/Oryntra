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

        // No HMAC secret means we cannot verify the sender. Reject until the
        // operator copies the webhook secret from Chatwoot into the connection.
        if ($secret === '') {
            return false;
        }

        if ($signature === '') {
            return false;
        }

        $signature = str($signature)->after('sha256=')->toString();
        $body = $request->getContent();
        $timestamp = (string) $request->header('X-Chatwoot-Timestamp');

        // Chatwoot agent bot webhooks sign "{timestamp}.{body}" (see
        // lib/webhooks/trigger.rb). Account-level webhooks may sign the raw body,
        // so accept either to stay compatible.
        $signedPayloads = [];
        if ($timestamp !== '') {
            $signedPayloads[] = "{$timestamp}.{$body}";
        }
        $signedPayloads[] = $body;

        foreach ($signedPayloads as $signedPayload) {
            if (hash_equals(hash_hmac('sha256', $signedPayload, $secret), $signature)) {
                return true;
            }
        }

        return false;
    }
}
