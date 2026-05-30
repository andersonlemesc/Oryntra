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
        $usersExist = User::query()->exists();

        if ($request->is('register') && $usersExist) {
            return $request->isMethod('GET')
                ? redirect()->route('login')
                : redirect()->route('login', [], 303);
        }

        if ($request->is('login') && ! $usersExist) {
            return redirect('/register');
        }

        return $next($request);
    }
}
