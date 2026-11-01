<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('users',fn(Blueprint $t)=>$t->string('mobile_number',11)->nullable());
  Schema::create('member_registrations',function(Blueprint $t){
   $t->id();$t->string('name');$t->string('email')->unique();$t->unsignedBigInteger('designation_id');$t->string('unit');$t->string('mobile_number',11);$t->string('password')->nullable();$t->string('status',20)->default('pending');$t->unsignedBigInteger('reviewed_by')->nullable();$t->timestamp('reviewed_at')->nullable();$t->timestamps();$t->index(['status','id']);
  });
  Schema::create('book_issue_requests',function(Blueprint $t){$t->id();$t->unsignedBigInteger('book_id');$t->unsignedBigInteger('user_id');$t->string('status',20)->default('pending');$t->unsignedBigInteger('loan_id')->nullable();$t->unsignedBigInteger('reviewed_by')->nullable();$t->timestamp('reviewed_at')->nullable();$t->timestamps();$t->index(['user_id','id']);$t->index(['status','id']);$t->index(['book_id','user_id','status']);});
 }
 public function down(): void {Schema::dropIfExists('book_issue_requests');Schema::dropIfExists('member_registrations');Schema::table('users',fn(Blueprint $t)=>$t->dropColumn('mobile_number'));}
};
