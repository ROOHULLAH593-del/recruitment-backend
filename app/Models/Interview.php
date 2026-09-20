<?php

namespace App\Models;

use App\Enums\InterviewStatus;
use Database\Factories\InterviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'application_id',
    'interviewer_id',
    'scheduled_at',
    'google_calendar_event_id',
    'status',
    'notes',
    'video_room',
])]
class Interview extends Model
{
    /** @use HasFactory<InterviewFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'status' => InterviewStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewer_id');
    }

    /**
     * The Jitsi room identifier for this interview, generating and
     * persisting one first if this row predates the video_room column —
     * a lazy per-row backfill instead of a bulk migration.
     */
    public function videoRoom(): string
    {
        if ($this->video_room === null) {
            $this->update(['video_room' => self::generateVideoRoomIdentifier()]);
        }

        return $this->video_room;
    }

    /**
     * High-entropy (32 random chars — astronomically collision-resistant
     * on its own) and app-prefixed, so it can never collide with an
     * unrelated Jitsi user's room on the shared public meet.jit.si server.
     */
    public static function generateVideoRoomIdentifier(): string
    {
        return 'recruitment-'.Str::random(32);
    }
}
