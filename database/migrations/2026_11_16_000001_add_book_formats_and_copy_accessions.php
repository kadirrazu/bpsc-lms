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
            $table->string('format', 12)->default('hard');
            $table->decimal('printed_price', 12, 2)->nullable();
            $table->char('printed_currency', 3)->default('BDT');
            $table->string('pdf_path')->nullable();
            $table->unsignedBigInteger('download_count')->default(0);
        });
        Schema::create('book_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->restrictOnDelete();
            $table->string('accession_number', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->index(['book_id', 'is_active']);
        });
        Schema::table('book_loans', function (Blueprint $table) {
            $table->foreignId('book_copy_id')->nullable()->constrained()->restrictOnDelete();
            $table->index(['book_copy_id', 'returned_at']);
        });
        DB::table('books')->select('id', 'accession_number')->chunkById(500, function ($books) {
            DB::table('book_copies')->insert($books->map(fn ($book) => [
                'book_id' => $book->id,
                'accession_number' => $book->accession_number,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::table('book_loans', function (Blueprint $table) {
            $table->dropForeign(['book_copy_id']);
            $table->dropIndex(['book_copy_id', 'returned_at']);
            $table->dropColumn('book_copy_id');
        });
        Schema::dropIfExists('book_copies');
        Schema::table('books', fn (Blueprint $table) => $table->dropColumn([
            'format', 'printed_price', 'printed_currency', 'pdf_path', 'download_count',
        ]));
    }
};
