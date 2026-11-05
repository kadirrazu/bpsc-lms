<?php
namespace App\Http\Controllers;
use App\Services\Library\{BorrowingPolicy,Audit};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};
class BorrowingSettingsController extends Controller {
 public function edit(BorrowingPolicy $policy){Gate::authorize('manage-system');return view('library.borrowing-settings',['settings'=>$policy->values()]);}
 public function update(Request $r,BorrowingPolicy $policy){
  Gate::authorize('manage-system');$data=$r->validate(['loan_limit'=>'required|integer|min:1|max:100','loan_days'=>'required|integer|min:1|max:365']);
  DB::transaction(function()use($r,$data){$before=DB::table('borrowing_settings')->where('id',1)->lockForUpdate()->first();abort_unless($before,503,'Borrowing settings are not installed.');$after=['loan_limit'=>(int)$data['loan_limit'],'loan_days'=>(int)$data['loan_days'],'revision'=>(int)$before->revision+1,'updated_by'=>$r->user()->id,'updated_at'=>now()];DB::table('borrowing_settings')->where('id',1)->update($after);app(Audit::class)->record('library.borrowing_policy_updated',['before'=>['loan_limit'=>$before->loan_limit,'loan_days'=>$before->loan_days],'after'=>$data]);});
  $policy->refresh();return back()->with('success','Borrowing settings updated. New issues and renewal defaults will use these values.');
 }
}
