<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issue_receipts', function (Blueprint $table) {
            $table->string('member_designation')->nullable();
            $table->string('member_unit')->nullable();
            $table->unsignedBigInteger('issuer_user_id')->nullable();
            $table->string('issuer_name')->nullable();
            $table->string('issuer_designation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('issue_receipts', fn (Blueprint $table) => $table->dropColumn([
            'member_designation', 'member_unit', 'issuer_user_id', 'issuer_name', 'issuer_designation',
        ]));
    }
};
