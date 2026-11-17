<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('book_loans', function (Blueprint $table) {
            $table->unsignedBigInteger('returned_by')->nullable();
            $table->string('return_receiver_name')->nullable();
            $table->string('return_receiver_designation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('book_loans', fn (Blueprint $table) => $table->dropColumn([
            'returned_by', 'return_receiver_name', 'return_receiver_designation',
        ]));
    }
};
