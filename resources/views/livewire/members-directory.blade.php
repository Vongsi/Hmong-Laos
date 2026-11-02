<div class="stack" style="gap:20px">
    <p class="members-summary">
        <span>{{ trans_choice('site.members.total', $total, ['count' => number_format($total)]) }}</span>
        <span class="online-now" wire:ignore><span class="online-dot" aria-hidden="true"></span>{!! str_replace(':count', '<b data-online-count>'.$activeNow.'</b>', e(__('site.members.online_now'))) !!}</span>
    </p>

    @guest
        <p class="panel">{{ __('site.members.join_prompt') }} <a href="{{ url('/join') }}">{{ __('site.nav.join') }}</a></p>
    @else
        <div class="panel">
            <label class="check">
                <input type="checkbox" wire:model.live="listed">
                <span>{{ __('site.members.listed') }}</span>
            </label>
            <p class="muted small">{{ __('site.members.listed_help') }}</p>
        </div>

        <div class="members-filters">
            <input type="search" wire:model.live.debounce.300ms="search" maxlength="50"
                   placeholder="{{ __('site.members.search') }}" aria-label="{{ __('site.members.search') }}">
            @if ($provinces->isNotEmpty() || $countries->isNotEmpty())
                <select wire:model.live="place" aria-label="{{ __('site.members.place') }}">
                    <option value="">{{ __('site.members.everywhere') }}</option>
                    <optgroup label="{{ $laos }}">
                        <option value="{{ \App\Support\Countries::LAOS }}">{{ __('site.members.all_laos') }}</option>
                        @foreach ($provinces as $name)
                            <option value="{{ $name }}">{{ $name }}</option>
                        @endforeach
                    </optgroup>
                    @if ($countries->isNotEmpty())
                        <optgroup label="{{ __('site.members.abroad') }}">
                            @foreach ($countries as $code => $name)
                                <option value="{{ $code }}">{{ $name }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
            @endif
            {{-- Cards or rows; the same member markup either way, so the live online dots (live.js) work in both. --}}
            <div class="view-switch" role="group" aria-label="{{ __('site.members.view') }}">
                <button type="button" wire:click="setView('grid')" aria-pressed="{{ $view === 'grid' ? 'true' : 'false' }}" title="{{ __('site.members.view_grid') }}">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
                    <span class="sr-only">{{ __('site.members.view_grid') }}</span>
                </button>
                <button type="button" wire:click="setView('list')" aria-pressed="{{ $view === 'list' ? 'true' : 'false' }}" title="{{ __('site.members.view_list') }}">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1" fill="currentColor"/><circle cx="3.5" cy="12" r="1" fill="currentColor"/><circle cx="3.5" cy="18" r="1" fill="currentColor"/></svg>
                    <span class="sr-only">{{ __('site.members.view_list') }}</span>
                </button>
            </div>
        </div>

        @if ($members->isEmpty())
            <p class="muted">{{ $filtered ? __('site.members.no_match') : __('site.members.empty') }}</p>
        @else
            <ul class="{{ $view === 'list' ? 'member-list' : 'member-grid' }}">
                @foreach ($members as $member)
                    <li class="member" data-member-key="{{ $member->onlineKey() }}" wire:key="m{{ $member->id }}">
                        {{-- Their profile photo (App\Support\ProfilePhoto, twice the size shown for sharp phone screens), or their initials. --}}
                        <span class="avatar tone-{{ $member->id % 4 }}">
                            @if ($member->avatar)
                                <img src="{{ \App\Support\ProfilePhoto::url($member->avatar, 96) }}" alt="" width="44" height="44" loading="lazy">
                            @else
                                {{ mb_strtoupper(mb_substr($member->name ?: '?', 0, 2)) }}
                            @endif
                            <span class="presence" aria-hidden="true"></span>
                        </span>
                        <span class="member-info">
                            <b>{{ $member->name ?: __('site.comments.member') }}</b>
                            <span class="muted small">
                                @if ($where = $this->placeOf($member)){{ $where }} · @endif
                                <span class="status-live">{{ __('site.members.status_online') }}</span>
                                <span class="status-seen">{{ $member->last_seen_at ? __('site.members.last_active', ['time' => $member->last_seen_at->diffForHumans()]) : __('site.members.never_active') }}</span>
                            </span>
                        </span>
                    </li>
                @endforeach
            </ul>

            <p class="muted small members-range">{{ __('site.members.showing', ['from' => $members->firstItem(), 'to' => $members->lastItem(), 'total' => number_format($members->total())]) }}</p>
            @include('livewire.partials.pager', ['page' => $members])
        @endif
    @endguest
</div>
