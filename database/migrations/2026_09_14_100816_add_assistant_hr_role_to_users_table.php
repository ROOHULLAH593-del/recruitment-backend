<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite has no native enum type (Laravel's enum() column
            // becomes a CHECK constraint there) and no ALTER TABLE ...
            // MODIFY COLUMN; Blueprint::change() handles this natively for
            // SQLite by rebuilding the table, no doctrine/dbal required
            // (that package isn't installed in this project — it's only
            // needed for ->change() on MySQL/PostgreSQL, not SQLite).
            Schema::table('users', function (Blueprint $table) {
                $table->enum('role', ['admin', 'hr', 'assistant_hr', 'candidate'])
                    ->default('candidate')
                    ->change();
            });

            return;
        }

        // Laravel's Schema builder has no first-class "add a value to an
        // existing enum column" operation for MySQL, so this widens the
        // native enum the same way the original role column was created:
        // a raw MODIFY COLUMN matching MySQL's own enum syntax.
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'hr', 'assistant_hr', 'candidate') NOT NULL DEFAULT 'candidate'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->enum('role', ['admin', 'hr', 'candidate'])
                    ->default('candidate')
                    ->change();
            });

            return;
        }

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'hr', 'candidate') NOT NULL DEFAULT 'candidate'");
    }
};
