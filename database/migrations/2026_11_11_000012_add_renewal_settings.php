<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('borrowing_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('renewal_limit')->default(2);
            $table->unsignedSmallInteger('renewal_days')->default(15);
        });
    }

    public function down(): void
    {
        Schema::table('borrowing_settings', function (Blueprint $table) {
            $table->dropColumn(['renewal_limit', 'renewal_days']);
        });
    }
};
