<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\UserInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class InvitationController extends Controller
{
    public function show(string $token): View|RedirectResponse
    {
        $invitation = $this->findUsableInvitation($token);

        if (! $invitation) {
            return redirect()->route('login')->withErrors([
                'invitation' => 'Convite inválido, expirado ou já aceito.',
            ]);
        }

        return view('auth.accept-invitation', [
            'invitation' => $invitation,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->findUsableInvitation($token);

        if (! $invitation) {
            return redirect()->route('login')->withErrors([
                'invitation' => 'Convite inválido, expirado ou já aceito.',
            ]);
        }

        $user = $invitation->user;
        if (! $user) {
            return redirect()->route('login')->withErrors([
                'invitation' => 'Convite inválido, expirado ou já aceito.',
            ]);
        }

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        DB::transaction(function () use ($invitation, $user, $data): void {
            $user->forceFill([
                'password' => Hash::make($data['password']),
                'email_verified_at' => now(),
            ])->save();

            $invitation->markAccepted();
        });

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended('/admin');
    }

    private function findUsableInvitation(string $token): ?UserInvitation
    {
        if (mb_strlen($token) < 32 || mb_strlen($token) > 128) {
            return null;
        }

        $invitation = UserInvitation::query()
            ->with('user')
            ->where('token', $token)
            ->first();

        if (! $invitation || ! $invitation->isUsable()) {
            return null;
        }

        return $invitation;
    }
}
