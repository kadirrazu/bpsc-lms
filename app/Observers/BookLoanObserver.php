<?php
namespace App\Observers;
use App\Models\{Book,BookLoan};
use App\Services\Library\Audit;
use App\Services\Library\CatalogRevision;
class BookLoanObserver {
 private function snapshot(BookLoan $loan,bool $old=false): array {return array_intersect_key($old?$loan->getRawOriginal():$loan->getAttributes(),array_flip(['book_id','user_id','quantity','issued_at','due_at','returned_at','limit_override_by','limit_override_at','active_count_at_issue','accession_snapshot','title_snapshot','authors_snapshot','issued_by','issuer_name','renewal_count','renewed_at','is_fined','fined_at','fined_by']));}
 private function record(BookLoan $loan,string $action,array $details): void {app(Audit::class)->record($action,['loan_id'=>$loan->id,'member_id'=>$loan->user_id,'member_name'=>$loan->user?->name]+$details,$loan->book()->first());}
 public function created(BookLoan $loan): void {
  app(CatalogRevision::class)->touch(false);
  Book::whereKey($loan->book_id)->increment('total_issue_count');
  $number=(int)Book::whereKey($loan->book_id)->value('total_issue_count');
  $this->record($loan,'book.issued',['issue_number'=>$number,'after'=>$this->snapshot($loan)]);
  if($loan->returned_at)$this->record($loan,'book.returned',['after'=>$this->snapshot($loan)]);
 }
 public function updated(BookLoan $loan): void {
  if(!array_intersect(array_keys($loan->getChanges()),['user_id','quantity','issued_at','due_at','returned_at','limit_override_by','limit_override_at','active_count_at_issue','accession_snapshot','title_snapshot','authors_snapshot','issued_by','issuer_name','renewal_count','renewed_at','is_fined','fined_at','fined_by']))return;
  app(CatalogRevision::class)->touch(false);
  $action=$loan->wasChanged('returned_at') && $loan->getRawOriginal('returned_at')===null && $loan->returned_at ? 'book.returned':'book.issue_updated';
  $this->record($loan,$action,['before'=>$this->snapshot($loan,true),'after'=>$this->snapshot($loan)]);
 }
 public function deleted(BookLoan $loan): void {app(CatalogRevision::class)->touch(false);$this->record($loan,'book.issue_deleted',['before'=>$this->snapshot($loan)]);}
}
