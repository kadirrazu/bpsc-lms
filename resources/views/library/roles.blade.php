@extends('layouts.app')
@section('title','Roles & Permissions')
@section('content')
<h1>Roles & Permissions</h1><p>Four predefined roles. Assign a role from the user edit form.</p>
<div class="card table-responsive"><table class="table"><thead><tr><th>Role</th><th>Current permissions</th></tr></thead><tbody>
<tr><td>Administrator</td><td>Administration, Reports, catalog and circulation. Manage all users and view Audit Logs, Roles &amp; Permissions and Book Activity.</td></tr>
<tr><td>Librarian</td><td>Manage staff and member accounts, designations, units, borrowing settings and member registration requests. Access Reports, catalog, circulation and Book Activity.</td></tr>
<tr><td>Library Staff</td><td>Create and edit books, authors, publishers and categories. Manage circulation.</td></tr>
<tr><td>Library Member</td><td>Member portal, own name/email editing and password changes. Catalog search, filters and book details. Request books and view own loans.</td></tr>
</tbody></table></div><p class="mt-3 text-secondary">Book deletion requires all issued copies to be returned.</p>
@endsection
