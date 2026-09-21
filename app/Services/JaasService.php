<?php

namespace App\Services;

use App\Models\Interview;
use App\Models\User;
use Firebase\JWT\JWT;

/**
 * Builds the room name and signed JWT a client needs to join an interview's
 * video call on JaaS (Jitsi as a Service) — the managed offering meet.jit.si
 * was migrated to after the public server stopped reliably supporting the
 * embedded-IFrame-API use case (rooms unexpectedly requiring lobby
 * approval with no way for an anonymous participant to grant it).
 *
 * Interview::videoRoom() still owns generating/persisting the actual random
 * per-interview identifier — this class only knows how to turn that
 * identifier into what JaaS specifically requires: the App-ID-prefixed room
 * name, and a token scoped to exactly that room.
 */
class JaasService
{
    /**
     * A token lasts long enough to cover a single interview running late,
     * not so long that a leaked token stays useful for long.
     */
    private const TOKEN_LIFETIME_SECONDS = 60 * 60 * 3;

    /**
     * The room identifier as JaaS's own room@conference XMPP address
     * normalizes it: lowercase (Interview::generateVideoRoomIdentifier()
     * produces mixed case via Str::random(), which the actual conference
     * address doesn't preserve). The stored video_room column itself keeps
     * its original case — only JaaS-facing values are normalized here.
     */
    private function normalizedRoom(Interview $interview): string
    {
        return strtolower($interview->videoRoom());
    }

    /**
     * The full room name the External API's `roomName` option requires:
     * "<App ID>/<room>", not just the bare identifier.
     */
    public function fullRoomName(Interview $interview): string
    {
        return config('services.jaas.app_id').'/'.$this->normalizedRoom($interview);
    }

    /**
     * A signed, room-scoped JWT authorizing $user to join $interview's call.
     * Deliberately scoped to this one room (never "*") — a token leaked
     * from one interview must not double as access to any other.
     */
    public function tokenFor(Interview $interview, User $user): string
    {
        $now = time();

        $payload = [
            'aud' => 'jitsi',
            'iss' => 'chat',
            'sub' => config('services.jaas.app_id'),
            // Deliberately the BARE room identifier, not fullRoomName()'s
            // App-ID-prefixed form: JaaS strips that prefix from the
            // External API's roomName before comparing against this claim,
            // so prefixing it here causes every join to fail validation
            // with a generic "Room and token mismatched" error — confirmed
            // by testing both forms directly against JaaS.
            'room' => $this->normalizedRoom($interview),
            'nbf' => $now,
            'exp' => $now + self::TOKEN_LIFETIME_SECONDS,
            'context' => [
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    // JaaS expects this as the literal string "true"/"false",
                    // not a JSON boolean.
                    'moderator' => $user->isStaff() ? 'true' : 'false',
                ],
            ],
        ];

        $privateKey = file_get_contents(config('services.jaas.private_key_path'));

        return JWT::encode($payload, $privateKey, 'RS256', config('services.jaas.key_id'));
    }
}
