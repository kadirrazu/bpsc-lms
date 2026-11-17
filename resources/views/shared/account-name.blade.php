<span class="text-start ps-2 lh-base" style="max-width: min(48vw, 15rem); overflow-wrap: anywhere">
    <strong class="d-block">{{ auth()->user()->name }}</strong>
    <span class="d-block small fw-normal text-secondary">{{ auth()->user()->designation?->name ?? __('Not assigned') }}</span>
</span>
