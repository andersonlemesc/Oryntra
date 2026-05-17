<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockRegisterAfterBootstrap
{
    /**
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('register') && User::query()->exists()) {
            return $request->isMethod('GET')
                ? redirect()->route('login')
                : redirect()->route('login', [], 303);
        }

        return $next($request);
    }
}
