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
        Schema::table('hr_invitations', function (Blueprint $table) {
            $table->dropForeign(['invited_by']);
        });

        Schema::table('hr_invitations', function (Blueprint $table) {
            $table->unsignedBigInteger('invited_by')->nullable()->change();
        });

        Schema::table('hr_invitations', function (Blueprint $table) {
            $table->foreign('invited_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hr_invitations', function (Blueprint $table) {
            $table->dropForeign(['invited_by']);
        });

        Schema::table('hr_invitations', function (Blueprint $table) {
            $table->unsignedBigInteger('invited_by')->nullable(false)->change();
        });

        Schema::table('hr_invitations', function (Blueprint $table) {
            $table->foreign('invited_by')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
