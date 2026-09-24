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
            // Null = active. Non-null doubles as both the "is this account
            // deactivated" flag and a record of when it happened — same
            // pattern locked_at already uses on this table. This column
            // alone only blocks new logins; deactivating an account also
            // revokes its existing Sanctum tokens (see
            // UserController::deactivate()), since tokens otherwise never
            // expire.
            $table->timestamp('deactivated_at')->nullable()->after('locked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deactivated_at');
        });
    }
};
