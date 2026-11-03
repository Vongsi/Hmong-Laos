{{-- A member's photo or initials, or a group's icon (Messages page). --}}
<span class="avatar tone-{{ $tone }} {{ $group ? 'group-avatar' : '' }}">
    @if ($group)
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14.2a5 5 0 0 1 6 4.8"/></svg>
    @elseif ($photo)
        <img src="{{ $photo }}" alt="" width="44" height="44" loading="lazy">
    @else
        {{ $initials }}
    @endif
</span>
