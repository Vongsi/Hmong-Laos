<div class="profile stack" style="gap:20px">
    <a class="link small profile-back" href="{{ $membersUrl }}">← {{ __('site.members.back') }}</a>

    @if (! $member)
        <h1 style="font-size:clamp(28px,4.5vw,40px)">{{ __('site.members.profile') }}</h1>
        <p class="panel">{{ __('site.members.profile_join') }} <a href="{{ $joinUrl }}">{{ __('site.nav.join') }}</a></p>
    @else
        {{-- "member" and data-member-key: public/js/live.js shows the green dot and "Online now" while they have the site open. --}}
        <div class="member profile-head {{ $isMe ? 'member-me' : '' }}" data-member-key="{{ $member->onlineKey() }}">
            <span class="avatar tone-{{ $member->id % 4 }}">
                @if ($photo)
                    <img src="{{ $photo }}" alt="" width="112" height="112">
                @else
                    {{ mb_strtoupper(mb_substr($name, 0, 2)) }}
                @endif
                <span class="presence" aria-hidden="true"></span>
            </span>
            <div class="profile-info">
                <h1 class="profile-name">{{ $name }}@if ($isMe)<span class="member-you">{{ __('site.members.you') }}</span>@endif</h1>
                @if ($place)
                    <p class="profile-place">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>
                        {{ $place }}
                    </p>
                @endif
                <p class="muted small">
                    <span class="status-live">{{ __('site.members.status_online') }}</span>
                    <span class="status-seen">{{ $member->last_seen_at ? __('site.members.last_active', ['time' => $member->last_seen_at->diffForHumans()]) : __('site.members.never_active') }}</span>
                </p>
            </div>
            <div class="profile-actions">
                @if ($canMessage)
                    <a class="btn green" href="{{ $messagesUrl }}">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.9A8 8 0 1 1 21 12z"/></svg>
                        {{ __('site.messages.message') }}
                    </a>
                @endif
                @if ($isMe)
                    <a class="btn ghost" href="{{ $accountUrl }}">{{ __('site.members.edit_profile') }}</a>
                @endif
            </div>
        </div>

        @if ($isMe)
            <p class="note">{{ $member->show_in_directory ? __('site.members.own_profile') : __('site.members.own_profile_hidden') }}</p>
        @endif

        <section class="stack" style="gap:14px">
            <h2 class="h-sm">{{ __('site.members.posts') }} <span class="muted">({{ $postCount }})</span></h2>
            @if (! $posts)
                <p class="muted">{{ __('site.members.no_posts') }}</p>
            @else
                <div class="grid3">
                    @foreach ($posts as $post)
                        <a class="card" href="{{ $post['url'] }}" wire:key="p{{ $post['id'] }}">
                            @if ($post['image'])
                                <img class="cover" src="{{ $post['image'] }}" alt="" loading="lazy">
                            @else
                                <div class="ph b"></div>
                            @endif
                            @if ($post['category'])
                                <span class="tag {{ $post['category']['slug'] }}">{{ $post['category']['title'] }}</span>
                            @endif
                            <h3>{{ $post['title'] }}</h3>
                            @if ($post['date'])<p class="muted">{{ $post['date'] }}</p>@endif
                        </a>
                    @endforeach
                </div>
                @if ($postCount > count($posts))
                    <button type="button" class="btn ghost profile-more" wire:click="more">{{ __('site.members.more_posts') }}</button>
                @endif
            @endif
        </section>
    @endif
</div>
