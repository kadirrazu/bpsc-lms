<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Plan the backfill before changing the schema so exhausted years fail cleanly.
        $members = DB::table('users')->whereIn('role', ['library_member', 'borrower'])
            ->orderBy('created_at')->orderBy('id')->get(['id', 'created_at']);
        $sequences = [];
        $numbers = [];
        foreach ($members as $member) {
            $prefix = $member->created_at ? date('y', strtotime($member->created_at)) : now()->format('y');
            $sequence = ($sequences[$prefix] ?? 0) + 1;
            if ($sequence > 9999) {
                throw new RuntimeException("Membership numbers for prefix $prefix exceed the four-digit capacity.");
            }
            $sequences[$prefix] = $sequence;
            $numbers[$member->id] = sprintf('%s%04d', $prefix, $sequence);
        }

        Schema::create('membership_sequences', function (Blueprint $table) {
            $table->char('prefix', 2)->primary();
            $table->unsignedSmallInteger('last_number');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->char('membership_number', 6)->nullable()->unique();
        });
        DB::transaction(function () use ($numbers, $sequences) {
            foreach ($sequences as $prefix => $sequence) {
                DB::table('membership_sequences')->insert(['prefix' => sprintf('%02d', $prefix), 'last_number' => $sequence]);
            }
            foreach ($numbers as $id => $number) {
                DB::table('users')->where('id', $id)->update(['membership_number' => $number]);
            }
        });

        Schema::create('issue_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('member_name');
            $table->char('membership_number', 6);
            $table->dateTime('issued_at');
        });
        Schema::table('book_loans', function (Blueprint $table) {
            $table->foreignId('issue_receipt_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('book_loans', fn (Blueprint $table) => $table->dropConstrainedForeignId('issue_receipt_id'));
        Schema::dropIfExists('issue_receipts');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['membership_number']);
            $table->dropColumn('membership_number');
        });
        Schema::dropIfExists('membership_sequences');
    }
};
