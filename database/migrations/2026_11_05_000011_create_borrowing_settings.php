<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
 public function up(): void {Schema::create('borrowing_settings',function(Blueprint $t){$t->unsignedTinyInteger('id')->primary();$t->unsignedInteger('loan_limit')->default(2);$t->unsignedInteger('loan_days')->default(15);$t->unsignedBigInteger('revision')->default(1);$t->unsignedBigInteger('updated_by')->nullable();$t->timestamps();});DB::table('borrowing_settings')->insert(['id'=>1,'loan_limit'=>2,'loan_days'=>15,'revision'=>1,'created_at'=>now(),'updated_at'=>now()]);}
 public function down(): void {Schema::dropIfExists('borrowing_settings');}
};
