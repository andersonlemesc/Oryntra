<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LoginResponse as FortifyLoginResponse;
use Laravel\Fortify\Contracts\RegisterResponse as FortifyRegisterResponse;
use Symfony\Component\HttpFoundation\Response;

class RedirectAfterAuth implements FortifyLoginResponse, FortifyRegisterResponse
{
    public function toResponse($request): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isSuperAdmin() && Workspace::query()->doesntExist()) {
            return new RedirectResponse(route('setup.platform.show'));
        }

        return new RedirectResponse('/admin');
    }
}
