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
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('failed_login_attempts')->default(0)->after('password');
            // Null = not locked. Non-null doubles as both the "is this
            // account locked" flag and a record of when it happened — the
            // same pattern email_verified_at already uses on this table.
            // There's no auto-unlock timer: the only way back in is a
            // successful password reset, which clears both columns.
            $table->timestamp('locked_at')->nullable()->after('failed_login_attempts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['failed_login_attempts', 'locked_at']);
        });
    }
};
