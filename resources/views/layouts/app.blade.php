@extends(auth()->user()->role === \App\Enums\UserRole::Member ? 'layouts.member' : 'layouts.admin')
