<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            // Nullable so existing rows don't need a bulk backfill — see
            // Interview::videoRoom(), which generates and persists one on
            // first access instead. Unique as cheap insurance against a
            // collision on the shared public Jitsi server, though the
            // random suffix's entropy already makes that astronomically
            // unlikely on its own.
            $table->string('video_room')->nullable()->unique()->after('google_calendar_event_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            $table->dropColumn('video_room');
        });
    }
};
