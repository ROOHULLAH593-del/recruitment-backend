<?php

use App\Models\Interview;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One-time backfill for the lazy-generation gap left by
     * 2026_09_20_112418_add_video_room_to_interviews_table: any interview
     * that predates that column, or was created outside
     * InterviewController::store() (a factory/seeder, a direct DB write),
     * has a null video_room — and until this ran, every one of those rows
     * paid for its own backfill with an extra UPDATE query the next time
     * anyone viewed it in a list. Confirmed on a page of ~100 such
     * interviews: 90 extra queries, pushing that one request from ~15ms to
     * over 800ms. Chunked (can't batch into one UPDATE — every row needs
     * its own unique random identifier) so this stays safe against a large
     * table.
     */
    public function up(): void
    {
        Interview::whereNull('video_room')
            ->chunkById(500, function ($interviews) {
                foreach ($interviews as $interview) {
                    $interview->update(['video_room' => Interview::generateVideoRoomIdentifier()]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally irreversible: these rooms may already be in active
        // use by the time this runs, and nulling video_room back out would
        // just reintroduce the exact N+1 this migration exists to remove.
    }
};
