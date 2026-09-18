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
            // Nullable at the DB level so existing accounts (all created
            // before this feature) remain valid — required at the
            // application/form level for new candidate registrations only.
            $table->string('username')->nullable()->unique()->after('name');
            // Stores the CNIC in reversible form (Laravel's `encrypted`
            // cast) for legitimate display back to its owner/admin. TEXT
            // because the encrypted payload (IV + MAC + ciphertext, base64)
            // is far longer than the plaintext CNIC it holds.
            $table->text('cnic')->nullable()->after('username');
            // A keyed, deterministic, one-way HMAC of the CNIC — lets login
            // find "does any user have this CNIC" via a fast indexed
            // lookup without ever comparing the CNIC in reversible form for
            // that purpose. Unique (not just indexed) so a duplicate CNIC
            // can never slip in under a race condition; MySQL and SQLite
            // both allow multiple NULLs through a unique index.
            $table->string('cnic_hash', 64)->nullable()->unique()->after('cnic');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'cnic', 'cnic_hash']);
        });
    }
};
