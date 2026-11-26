<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('current_accession_number', 100)->nullable()
                ->virtualAs('CASE WHEN deleted_at IS NULL THEN accession_number ELSE NULL END');
        });
        Schema::table('books', function (Blueprint $table) {
            $table->unique('current_accession_number', 'books_current_accession_unique');
            $table->dropUnique('books_accession_number_unique');
            $table->index('accession_number');
        });

        Schema::table('book_copies', function (Blueprint $table) {
            $table->timestamp('accession_released_at')->nullable();
        });
        DB::table('book_copies')->whereIn('book_id', DB::table('books')->whereNotNull('deleted_at')->select('id'))
            ->update(['accession_released_at' => now()]);
        Schema::table('book_copies', function (Blueprint $table) {
            $table->string('current_accession_number', 100)->nullable()
                ->virtualAs('CASE WHEN accession_released_at IS NULL THEN accession_number ELSE NULL END');
        });
        Schema::table('book_copies', function (Blueprint $table) {
            $table->unique('current_accession_number', 'book_copies_current_accession_unique');
            $table->dropUnique('book_copies_accession_number_unique');
            $table->index('accession_number');
        });
    }

    public function down(): void
    {
        foreach (['books', 'book_copies'] as $name) {
            if (DB::table($name)->select('accession_number')->groupBy('accession_number')->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('Accession numbers have been reused. Resolve duplicates in archived history before rolling back this migration.');
            }
        }
        Schema::table('book_copies', function (Blueprint $table) {
            $table->dropUnique('book_copies_current_accession_unique');
            $table->dropIndex('book_copies_accession_number_index');
            $table->dropColumn('current_accession_number');
        });
        Schema::table('book_copies', function (Blueprint $table) {
            $table->dropColumn('accession_released_at');
            $table->unique('accession_number');
        });
        Schema::table('books', function (Blueprint $table) {
            $table->dropUnique('books_current_accession_unique');
            $table->dropIndex('books_accession_number_index');
            $table->dropColumn('current_accession_number');
        });
        Schema::table('books', fn (Blueprint $table) => $table->unique('accession_number'));
    }
};
