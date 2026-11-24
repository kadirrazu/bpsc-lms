<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('book_loans', function (Blueprint $table) {
            $table->text('exceptional_reason')->nullable();
            $table->unsignedInteger('exceptional_limit')->nullable();
            $table->unsignedInteger('exceptional_total_after')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('book_loans', fn (Blueprint $table) => $table->dropColumn([
            'exceptional_reason', 'exceptional_limit', 'exceptional_total_after',
        ]));
    }
};
