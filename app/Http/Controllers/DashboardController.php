<?php
namespace App\Http\Controllers;
use App\Enums\UserRole;
use App\Models\{Book,BookLoan};
use App\Services\Library\DashboardOverview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
class DashboardController extends Controller {
 public function index(Request $request,DashboardOverview $overview){
  if($request->user()->role===UserRole::Member)return redirect()->route('member.home');
  Gate::authorize('manage-library');$data=$overview->snapshot();
  $recentBooks=Book::with('authors')->whereIn('id',$data['recent_book_ids'])->latest('id')->get();
  $recentIssues=BookLoan::with(['book','user'])->whereIn('id',$data['recent_loan_ids'])->orderByDesc('issued_at')->orderByDesc('id')->get();
  return view('library.dashboard',['stats'=>$data['stats'],'authors'=>$data['authors'],'categories'=>$data['categories'],'recentBooks'=>$recentBooks,'recentIssues'=>$recentIssues]);
 }
}
