<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {Schema::table('book_issue_requests',fn(Blueprint $t)=>$t->timestamp('withdrawn_at')->nullable());}
 public function down(): void {Schema::table('book_issue_requests',fn(Blueprint $t)=>$t->dropColumn('withdrawn_at'));}
};
