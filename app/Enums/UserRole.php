<?php
namespace App\Enums;
enum UserRole: string {
 case Admin = 'admin'; case Librarian = 'librarian'; case Staff = 'library_staff'; case Member = 'library_member';
 public function label(): string { return match($this) {self::Admin=>'Administrator',self::Librarian=>'Librarian',self::Staff=>'Library Staff',self::Member=>'Library Member'}; }
 public static function options(): array { return array_map(fn(self $r)=>['value'=>$r->value,'label'=>$r->label()], self::cases()); }
 public static function assignable(UserRole $actor): array { return array_values(array_filter(self::options(), fn($r)=>match($actor) {self::Admin=>true,self::Librarian=>in_array($r['value'],['library_staff','library_member']),self::Staff=>$r['value']==='library_member',default=>false})); }
}
