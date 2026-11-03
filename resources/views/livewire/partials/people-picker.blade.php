{{-- Choosing people for a group (new group, add members): who's ticked, a search, and the people found. --}}
<div class="people-picker">
    @if ($pickedPeople->isNotEmpty())
        <ul class="picked" aria-label="{{ __('site.messages.picked') }}">
            @foreach ($pickedPeople as $p)
                <li wire:key="picked-{{ $p->id }}">
                    {{ $p->name ?: __('site.comments.member') }}
                    <button type="button" wire:click="unpick({{ $p->id }})" aria-label="{{ __('site.messages.unpick', ['name' => $p->name]) }}">×</button>
                </li>
            @endforeach
        </ul>
    @endif
    <input type="search" wire:model.live.debounce.300ms="peopleSearch" maxlength="50" placeholder="{{ __('site.messages.people_search') }}" aria-label="{{ __('site.messages.people_search') }}">
    <p class="muted small" style="margin:0">{{ __('site.messages.people_hint') }}</p>
    <ul class="people-list">
        @forelse ($people as $p)
            <li wire:key="person-{{ $p->id }}">
                <label class="person-pick">
                    <input type="checkbox" wire:model.live="picked" value="{{ $p->id }}">
                    @include('livewire.partials.chat-avatar', ['group' => false, 'photo' => \App\Support\ProfilePhoto::url($p->avatar, 96), 'initials' => mb_strtoupper(mb_substr($p->name ?: '?', 0, 2)), 'tone' => $p->id % 4])
                    <span>{{ $p->name ?: __('site.comments.member') }}</span>
                </label>
            </li>
        @empty
            <li class="muted small">{{ __('site.messages.no_people') }}</li>
        @endforelse
    </ul>
    @error('picked')<p class="error small" role="alert">{{ $message }}</p>@enderror
</div>
