<div class="nav-item dropdown">
    <button type="button" class="nav-link dropdown-toggle d-flex align-items-center border-0 bg-transparent"
        data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('User menu') }}">
        <span class="avatar avatar-sm flex-shrink-0" aria-hidden="true">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
        @include('shared.account-name')
    </button>
    <div class="dropdown-menu dropdown-menu-end">
        <div class="px-3 py-2 lh-base">
            <strong class="d-block">{{ auth()->user()->name }}</strong>
            <div class="small text-secondary">{{ auth()->user()->designation?->name ?? __('Not assigned') }}</div>
            <div class="small text-secondary">{{ \App\Support\Ui::unit(auth()->user()->unit) }}</div>
        </div>
        <div class="dropdown-divider"></div>
        <a class="dropdown-item" href="{{ route('staff-profile.edit') }}"><i class="bi bi-person-circle" aria-hidden="true"></i>{{ __('My Profile') }}</a>
        <a class="dropdown-item" href="{{ route('staff-profile.password') }}"><i class="bi bi-key" aria-hidden="true"></i>{{ __('Change Password') }}</a>
        <form action="{{ route('logout') }}" method="post">@csrf<button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>{{ __('Sign out') }}</button></form>
    </div>
</div>
