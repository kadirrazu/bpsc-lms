@extends('layouts.app')
@section('title','Roles & Permissions')
@section('content')
<h1>Roles & Permissions</h1><p>Four predefined roles. Assign a role from the user edit form.</p>
<div class="card table-responsive"><table class="table"><thead><tr><th>Role</th><th>Current permissions</th></tr></thead><tbody>
<tr><td>Administrator</td><td>All users, role assignment, designations, units, deletion and audit logs.</td></tr>
<tr><td>Librarian</td><td>Create, edit and delete staff and member accounts; manage designations and view audit logs.</td></tr>
<tr><td>Library Staff</td><td>Create and edit books, authors, publishers, categories and designations; no user management, deletion or audit log access.</td></tr>
<tr><td>Library Member</td><td>Member portal, own name/email editing and password changes. Catalog search, filters and book details. Issue requests are planned.</td></tr>
</tbody></table></div><p class="mt-3 text-secondary">Book deletion requires all issued copies to be returned. Issue requests and circulation screens will be added in a later phase.</p>
@endsection
