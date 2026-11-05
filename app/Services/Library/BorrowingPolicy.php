<?php
namespace App\Services\Library;
use Illuminate\Support\Facades\{DB,Schema};
class BorrowingPolicy {
 private ?array $cached=null;
 public function values(): array {
  if($this->cached!==null)return $this->cached;
  $row=Schema::hasTable('borrowing_settings')?DB::table('borrowing_settings')->where('id',1)->first():null;
  return $this->cached=['loan_limit'=>(int)($row->loan_limit??2),'loan_days'=>(int)($row->loan_days??15),'revision'=>(int)($row->revision??0)];
 }
 public function limit(): int {return $this->values()['loan_limit'];}
 public function days(): int {return $this->values()['loan_days'];}
 public function revision(): int {return $this->values()['revision'];}
 public function refresh(): void {$this->cached=null;}
}
