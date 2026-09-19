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
        Schema::table('candidate_profiles', function (Blueprint $table) {
            // Nullable at the DB level (a fresh profile has none yet) —
            // transcript/CNIC front/CNIC back are only *required* in the
            // sense that ApplicationController::store() refuses to let a
            // candidate apply until they're present; FSC/Matric
            // certificates are optional supporting documents. Files
            // themselves live in private storage (see CandidateProfileController::uploadDocument())
            // — these columns hold only the stored path.
            $table->string('transcript_path')->nullable()->after('years_experience');
            $table->string('cnic_front_path')->nullable()->after('transcript_path');
            $table->string('cnic_back_path')->nullable()->after('cnic_front_path');
            $table->string('fsc_certificate_path')->nullable()->after('cnic_back_path');
            $table->string('matric_certificate_path')->nullable()->after('fsc_certificate_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'transcript_path',
                'cnic_front_path',
                'cnic_back_path',
                'fsc_certificate_path',
                'matric_certificate_path',
            ]);
        });
    }
};
