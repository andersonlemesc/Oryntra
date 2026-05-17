<?php

namespace App\Actions\Invitations;

use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use Illuminate\Support\Facades\DB;

class SendUserInvitation
{
    /**
     * Create an invitation for the user and dispatch the notification on
     * the "emails" queue. Returns the invitation.
     */
    public function execute(
        User $user,
        ?User $invitedBy = null,
        string $source = 'manual',
    ): UserInvitation {
        $ttlHours = (int) config('invitations.ttl_hours', 168);

        $invitation = DB::transaction(function () use ($user, $invitedBy, $source, $ttlHours): UserInvitation {
            $invitation = UserInvitation::create([
                'user_id' => $user->id,
                'token' => UserInvitation::generateToken(),
                'email_sent_to' => $user->email,
                'expires_at' => now()->addHours($ttlHours),
                'invited_by_user_id' => $invitedBy?->id,
                'source' => $source,
            ]);

            $user->forceFill(['last_invitation_sent_at' => now()])->save();

            return $invitation;
        });

        $user->notify(new UserInvitationNotification($invitation));

        return $invitation;
    }
}
