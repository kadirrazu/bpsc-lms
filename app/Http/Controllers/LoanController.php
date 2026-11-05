<?php
namespace App\Http\Controllers;
use App\Models\{User,Book};
use App\Enums\UserRole;
use App\Services\Library\LoanIssuance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
class LoanController extends Controller {
 public function create(Request $r){
  Gate::authorize('manage-library');
  $bookId=old('book_id',$r->input('book_id'));$memberId=old('member_id');
  $book=is_scalar($bookId)?Book::find($bookId):null;$member=is_scalar($memberId)?User::where('role',UserRole::Member->value)->where('is_active',true)->find($memberId):null;
  return view('loans.create',compact('book','member'));
 }
 public function store(Request $r,LoanIssuance $issuer){
  Gate::authorize('manage-library');$data=$r->validate(['book_id'=>'required|integer|exists:books,id','member_id'=>'required|integer|exists:users,id','confirmation_token'=>'nullable|string|max:64','confirmed'=>'required|accepted']);
  $result=$issuer->issue($r,(int)$data['book_id'],(int)$data['member_id']);
  if($result['confirmation'])return view('loans.confirm-limit',$result);
  return redirect()->route('issue-requests.index')->with('success','Book issued. Due: '.\App\Support\Ui::date($result['loan']->due_at));
 }
 public function options(Request $r,string $kind){
  Gate::authorize('manage-library');$data=$r->validate(['q'=>'nullable|string|max:150']);$term=trim($data['q']??'');$pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$term).'%';
  if($kind==='members')$rows=User::where('role',UserRole::Member->value)->where('is_active',true)->when($term!=='',fn($q)=>$q->where(fn($u)=>$u->whereRaw("name LIKE ? ESCAPE '!'",[$pattern])->orWhereRaw("email LIKE ? ESCAPE '!'",[$pattern])->orWhereRaw("mobile_number LIKE ? ESCAPE '!'",[$pattern])))->orderBy('name')->limit(20)->get()->map(fn($u)=>['id'=>$u->id,'label'=>$u->name.' — '.$u->email]);
  elseif($kind==='books')$rows=Book::when($term!=='',fn($q)=>$q->where(function($b)use($pattern){foreach(['title_bn','title_en','accession_number','call_number'] as $field)$b->orWhereRaw("$field LIKE ? ESCAPE '!'",[$pattern]);}))->latest('id')->limit(20)->get()->map(fn($b)=>['id'=>$b->id,'label'=>$b->title_bn.' — '.$b->accession_number]);else abort(404);
  return response()->json(['results'=>$rows]);
 }
}
