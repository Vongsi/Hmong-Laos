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
            @if ($provinces->isNotEmpty())
                <select wire:model.live="province" aria-label="{{ __('site.feed.province') }}">
                    <option value="">{{ __('site.feed.all_provinces') }}</option>
                    @foreach ($provinces as $name)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        @if ($members->isEmpty())
            <p class="muted">{{ $filtered ? __('site.members.no_match') : __('site.members.empty') }}</p>
        @else
            <ul class="member-grid">
                @foreach ($members as $member)
                    <li class="member" data-member-key="{{ $member->onlineKey() }}" wire:key="m{{ $member->id }}">
                        <span class="avatar tone-{{ $member->id % 4 }}">{{ mb_strtoupper(mb_substr($member->name ?: '?', 0, 2)) }}<span class="presence" aria-hidden="true"></span></span>
                        <span class="member-info">
                            <b>{{ $member->name ?: __('site.comments.member') }}</b>
                            <span class="muted small">
                                @if ($member->province){{ $member->province }} · @endif
                                <span class="status-live">{{ __('site.members.status_online') }}</span>
                                <span class="status-seen">{{ $member->last_seen_at ? __('site.members.last_active', ['time' => $member->last_seen_at->diffForHumans()]) : __('site.members.never_active') }}</span>
                            </span>
                        </span>
                    </li>
                @endforeach
            </ul>

            @if ($members->hasPages())
                <nav class="pagination">
                    @unless ($members->onFirstPage())<button type="button" class="btn ghost" wire:click="previousPage">←</button>@endunless
                    <span class="muted">{{ $members->currentPage() }} / {{ $members->lastPage() }}</span>
                    @if ($members->hasMorePages())<button type="button" class="btn ghost" wire:click="nextPage">→</button>@endif
                </nav>
            @endif
        @endif
    @endguest
</div>
