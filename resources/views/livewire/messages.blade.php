<div>
    @if (! $me)
        <p class="panel">{{ __('site.messages.join_prompt') }} <a href="{{ $joinUrl }}">{{ __('site.nav.join') }}</a></p>
    @else
        {{-- The list, and the open conversation (or a panel) beside it; on phones one or the other (.chat-open). --}}
        <div class="chat {{ ($conversation || $other || $panel) ? 'chat-open' : '' }}" wire:poll.30s.visible>
            <aside class="chat-list" aria-label="{{ __('site.messages.title') }}">
                <div class="chat-list-top">
                    <button type="button" class="btn green" wire:click="startGroup">+ {{ __('site.messages.new_group') }}</button>
                </div>
                @if ($requests->isNotEmpty())
                    <h2 class="chat-list-head">{{ __('site.messages.requests') }} <span class="chat-unread">{{ $requests->count() }}</span></h2>
                    <ul>
                        @foreach ($requests as $c)
                            @include('livewire.partials.chat-item', ['c' => $c])
                        @endforeach
                    </ul>
                @endif
                @if ($requests->isNotEmpty() && $chats->isNotEmpty())<h2 class="chat-list-head">{{ __('site.messages.chats') }}</h2>@endif
                @if ($chats->isEmpty() && $requests->isEmpty())
                    <p class="muted small chat-empty">{{ __('site.messages.empty') }} <a href="{{ $membersUrl }}">{{ __('site.messages.find_members') }}</a></p>
                @else
                    <ul>
                        @foreach ($chats as $c)
                            @include('livewire.partials.chat-item', ['c' => $c])
                        @endforeach
                    </ul>
                @endif
                @if ($moreChats)
                    <button type="button" class="link small chat-more" wire:click="moreChats">{{ __('site.messages.more_chats') }}</button>
                @endif
            </aside>

            <section class="chat-thread" aria-label="{{ $panel === 'new-group' ? __('site.messages.new_group') : (($conversation || $other) ? $title : __('site.messages.title')) }}">
                @if ($panel === 'new-group')
                    {{-- A new group: its name and who's in it. --}}
                    <header class="chat-head">
                        <button type="button" class="chat-back" wire:click="closeChat" aria-label="{{ __('site.messages.back') }}">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
                        </button>
                        <b class="chat-head-name">{{ __('site.messages.new_group') }}</b>
                    </header>
                    <form class="chat-panel" wire:submit="createGroup">
                        <label class="field">{{ __('site.messages.group_name') }}
                            <input wire:model="groupName" maxlength="{{ \App\Support\Messaging::NAME_LENGTH }}" placeholder="{{ __('site.messages.group_name_ph') }}">
                        </label>
                        @error('groupName')<p class="error small" role="alert">{{ $message }}</p>@enderror
                        <span class="small" style="font-weight:600">{{ __('site.messages.group_people', ['max' => \App\Support\Messaging::GROUP_SIZE - 1]) }}</span>
                        @include('livewire.partials.people-picker')
                        <p class="muted small" style="margin:0">{{ __('site.messages.group_invite_help') }}</p>
                        <div class="chat-panel-actions">
                            <button class="btn green" type="submit" wire:loading.attr="disabled">{{ __('site.messages.create_group') }}</button>
                            <button class="btn red" type="button" wire:click="closeChat">{{ __('site.messages.cancel') }}</button>
                        </div>
                    </form>
                @elseif (! $conversation && ! $other)
                    <p class="chat-placeholder muted">{{ __('site.messages.pick') }}</p>
                @else
                    <header class="chat-head">
                        <button type="button" class="chat-back" wire:click="closeChat" aria-label="{{ __('site.messages.back') }}">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
                        </button>
                        @if ($isGroup)
                            @include('livewire.partials.chat-avatar', ['group' => true, 'photo' => null, 'initials' => '', 'tone' => $conversation->id % 4])
                            <button type="button" class="chat-head-text" wire:click="showPanel('info')" title="{{ __('site.messages.group_info') }}">
                                <b class="chat-head-name">{{ $title }}</b>
                                <span class="muted small">{{ __('site.messages.members_count', ['count' => $members->whereNotNull('joined_at')->count()]) }}</span>
                            </button>
                        @else
                            @include('livewire.partials.chat-avatar', ['group' => false, 'photo' => \App\Support\ProfilePhoto::url($other?->avatar, 96), 'initials' => mb_strtoupper(mb_substr($title, 0, 2)), 'tone' => (int) $other?->id % 4])
                            <span class="chat-head-text"><b class="chat-head-name">{{ $title }}</b></span>
                        @endif
                        @if ($canCall)
                            {{-- Calls (public/js/calls.js; shown only where the browser can make them, html.can-call) --}}
                            <button type="button" class="chat-call" data-call="voice" data-conversation="{{ $conversation->id }}" title="{{ __('site.calls.voice_call') }}" aria-label="{{ __('site.calls.voice_call') }}">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>
                            </button>
                            <button type="button" class="chat-call" data-call="video" data-conversation="{{ $conversation->id }}" title="{{ __('site.calls.video_call') }}" aria-label="{{ __('site.calls.video_call') }}">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.5" y="6" width="13" height="12" rx="2"/><path d="M15.5 10.5l6-3.5v10l-6-3.5"/></svg>
                            </button>
                        @endif
                        @if ($conversation)
                            <details class="chat-menu">
                                <summary aria-label="{{ __('site.messages.menu') }}" title="{{ __('site.messages.menu') }}">
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
                                </summary>
                                <div class="menu">
                                    @if ($isGroup)
                                        <button type="button" wire:click="showPanel('info')">{{ __('site.messages.group_info') }}</button>
                                        <button type="button" wire:click="report" wire:confirm="{{ __('site.messages.report_group_confirm') }}">{{ __('site.messages.report') }}</button>
                                        <button type="button" wire:click="deleteChat" wire:confirm="{{ __('site.messages.delete_confirm') }}">{{ __('site.messages.delete_chat') }}</button>
                                        <button type="button" class="menu-out" wire:click="leave" wire:confirm="{{ __('site.messages.leave_confirm', ['group' => $title]) }}">{{ __('site.messages.leave_group') }}</button>
                                    @else
                                        @if ($iBlocked)
                                            <button type="button" wire:click="unblock">{{ __('site.messages.unblock') }}</button>
                                        @else
                                            <button type="button" wire:click="block" wire:confirm="{{ __('site.messages.block_confirm', ['name' => $title]) }}">{{ __('site.messages.block') }}</button>
                                        @endif
                                        <button type="button" wire:click="report" wire:confirm="{{ __('site.messages.report_confirm', ['name' => $title]) }}">{{ __('site.messages.report') }}</button>
                                        <button type="button" class="menu-out" wire:click="deleteChat" wire:confirm="{{ __('site.messages.delete_confirm') }}">{{ __('site.messages.delete_chat') }}</button>
                                    @endif
                                </div>
                            </details>
                        @endif
                    </header>

                    @if ($isGroup && $panel === 'info')
                        {{-- The group's members; its admin adds, removes and renames. --}}
                        <div class="chat-panel">
                            <h2 class="h-sm" style="margin:0">{{ __('site.messages.group_info') }}</h2>
                            <ul class="group-members">
                                @foreach ($members as $m)
                                    <li wire:key="gm-{{ $m->user_id }}">
                                        @include('livewire.partials.chat-avatar', ['group' => false, 'photo' => \App\Support\ProfilePhoto::url($m->user?->avatar, 96), 'initials' => mb_strtoupper(mb_substr($m->user?->name ?: '?', 0, 2)), 'tone' => $m->user_id % 4])
                                        <span class="group-member-name">{{ $m->user_id === $me->id ? __('site.messages.you_name') : ($m->user?->name ?: __('site.comments.member')) }}</span>
                                        @if ($m->isAdmin())<span class="chat-tag">{{ __('site.messages.admin') }}</span>@elseif (! $m->hasJoined())<span class="chat-tag">{{ __('site.messages.invited') }}</span>@endif
                                        @if ($isAdmin && $m->user_id !== $me->id)
                                            <button type="button" class="link small" style="color:var(--red)" wire:click="removeMember({{ $m->user_id }})" wire:confirm="{{ __('site.messages.remove_confirm', ['name' => $m->user?->name ?: __('site.comments.member')]) }}">{{ __('site.messages.remove') }}</button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            <div class="chat-panel-actions">
                                @if ($isAdmin)
                                    <button type="button" class="btn green" wire:click="showPanel('add')">+ {{ __('site.messages.add_members') }}</button>
                                    <button type="button" class="btn ghost" wire:click="showPanel('rename')">{{ __('site.messages.rename') }}</button>
                                @endif
                                <button type="button" class="btn ghost" wire:click="closePanel">{{ __('site.messages.back_to_chat') }}</button>
                            </div>
                        </div>
                    @elseif ($isGroup && $panel === 'add')
                        <form class="chat-panel" wire:submit="addPicked">
                            <h2 class="h-sm" style="margin:0">{{ __('site.messages.add_members') }}</h2>
                            @include('livewire.partials.people-picker')
                            <p class="muted small" style="margin:0">{{ __('site.messages.group_invite_help') }}</p>
                            <div class="chat-panel-actions">
                                <button class="btn green" type="submit" wire:loading.attr="disabled">{{ __('site.messages.add') }}</button>
                                <button class="btn red" type="button" wire:click="showPanel('info')">{{ __('site.messages.cancel') }}</button>
                            </div>
                        </form>
                    @elseif ($isGroup && $panel === 'rename')
                        <form class="chat-panel" wire:submit="saveRename">
                            <label class="field">{{ __('site.messages.group_name') }}
                                <input wire:model="groupName" maxlength="{{ \App\Support\Messaging::NAME_LENGTH }}">
                            </label>
                            @error('groupName')<p class="error small" role="alert">{{ $message }}</p>@enderror
                            <div class="chat-panel-actions">
                                <button class="btn green" type="submit" wire:loading.attr="disabled">{{ __('site.messages.save') }}</button>
                                <button class="btn red" type="button" wire:click="showPanel('info')">{{ __('site.messages.cancel') }}</button>
                            </div>
                        </form>
                    @else
                        {{-- Opens at the newest message, and goes back there when one is sent or arrives (chat-sent). --}}
                        <div class="chat-messages" wire:key="messages-{{ $conversation?->id ?? 'new-'.$other?->id }}"
                             x-data x-init="$el.scrollTop = $el.scrollHeight"
                             x-on:chat-sent.window="requestAnimationFrame(() => $el.scrollTop = $el.scrollHeight)">
                            @if ($notice)<p class="flash small" role="status">{{ $notice }}</p>@endif
                            @if ($hasEarlier)
                                <button type="button" class="link small chat-earlier" wire:click="loadEarlier">{{ __('site.messages.load_earlier') }}</button>
                            @endif
                            @php $day = null; @endphp
                            @foreach ($items as $m)
                                @if ($m->day !== $day)
                                    @php $day = $m->day; @endphp
                                    <p class="chat-day" wire:key="day-{{ $m->day }}"><span>{{ $this->dayLabel($m->day) }}</span></p>
                                @endif
                                @if ($m->system)
                                    <p class="chat-system {{ $m->call ? 'call-note' : '' }} {{ $m->missed ? 'missed' : '' }}" wire:key="m{{ $m->id }}">
                                        @if ($m->call)<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>@endif
                                        <span>{{ $m->text }}@if ($m->call) · <time @if ($m->laos) title="{{ __('site.time.laos_at', ['time' => $m->laos]) }}" @endif>{{ $m->time }}</time>@endif</span>
                                    </p>
                                @else
                                    <div class="msg {{ $m->mine ? 'mine' : 'theirs' }}" wire:key="m{{ $m->id }}">
                                        @if ($m->canChange)
                                            {{-- The sender's own message, recently: edit it, or delete it for everyone. --}}
                                            <details class="bubble-menu">
                                                <summary aria-label="{{ __('site.messages.message_options') }}" title="{{ __('site.messages.message_options') }}">
                                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
                                                </summary>
                                                <div class="menu">
                                                    @if ($m->body !== null)<button type="button" wire:click="startEdit({{ $m->id }})">{{ __('site.messages.edit') }}</button>@endif
                                                    <button type="button" class="menu-out" wire:click="unsend({{ $m->id }})" wire:confirm="{{ __('site.messages.unsend_confirm') }}">{{ __('site.messages.unsend') }}</button>
                                                </div>
                                            </details>
                                        @endif
                                        <div class="bubble {{ $m->mine ? 'mine' : 'theirs' }} {{ $m->deleted ? 'deleted' : '' }} {{ $m->emoji ? 'emoji-only' : '' }} {{ $editing === $m->id ? 'editing' : '' }}">
                                            @if ($m->from)<span class="bubble-from from-{{ $m->tone }}">{{ $m->from }}</span>@endif
                                            @if ($m->deleted)
                                                <p>{{ __('site.messages.deleted_message') }}</p>
                                            @else
                                                @if ($m->photo)
                                                    <button type="button" class="chat-photo" aria-label="{{ __('site.messages.photo_open') }}"><img src="{{ $m->photo }}" alt="" loading="lazy"></button>
                                                @endif
                                                @if ($m->voice)
                                                    {{-- A voice message's player (public/js/voice.js); Livewire's redraws leave it alone while it plays. --}}
                                                    <div class="voice-msg" wire:ignore wire:key="voice-{{ $m->id }}" data-voice-player data-seconds="{{ $m->voiceSeconds }}">
                                                        <button type="button" class="voice-play" data-voice-toggle aria-label="{{ __('site.messages.voice_play') }}"
                                                                data-t-play="{{ __('site.messages.voice_play') }}" data-t-pause="{{ __('site.messages.voice_pause') }}">
                                                            <svg class="i-play" viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l10.5-6.5z"/></svg>
                                                            <svg class="i-pause" viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg>
                                                        </button>
                                                        <span class="voice-track" data-voice-seek aria-hidden="true"><span class="voice-fill"></span></span>
                                                        <span class="voice-time" data-voice-time>{{ $m->voiceLength }}</span>
                                                        <audio preload="none" src="{{ $m->voice }}"></audio>
                                                    </div>
                                                @endif
                                                @if ($m->body !== null)<p>{!! nl2br(e($m->body)) !!}</p>@endif
                                            @endif
                                            {{-- In the member's own time zone; on hover, Laos time when that's different (App\Support\ViewerTime). --}}
                                            <time @if ($m->laos) title="{{ __('site.time.laos_at', ['time' => $m->laos]) }}" @endif>@if ($m->edited){{ __('site.messages.edited') }} · @endif{{ $m->time }}</time>
                                        </div>
                                    </div>
                                    @if ($seen && $m->id === $seen['id'])<p class="chat-seen" wire:key="seen-{{ $m->id }}">{{ $seen['text'] }}</p>@endif
                                @endif
                            @endforeach
                        </div>

                        @if ($conversation)
                            {{-- "… is typing" (live.js, over the conversation's private channel); names by member id --}}
                            <p class="chat-typing" wire:ignore wire:key="typing-{{ $conversation->id }}" data-live-chat="{{ $conversation->id }}"
                               data-typing="{{ __('site.messages.typing', ['name' => ':name']) }}" data-names='@json($typingNames)'></p>
                        @endif

                        @if ($state === 'request')
                            <div class="chat-request">
                                <p>{{ __('site.messages.request_from', ['name' => $title]) }}</p>
                                <div class="chat-request-actions">
                                    <button type="button" class="btn green" wire:click="accept">{{ __('site.messages.accept') }}</button>
                                    <button type="button" class="btn red" wire:click="decline" wire:confirm="{{ __('site.messages.decline_confirm', ['name' => $title]) }}">{{ __('site.messages.decline') }}</button>
                                </div>
                            </div>
                        @elseif ($state === 'invite')
                            <div class="chat-request">
                                <p>{{ __('site.messages.invite_from', ['name' => $invitedBy ?? __('site.comments.member'), 'group' => $title]) }}</p>
                                <div class="chat-request-actions">
                                    <button type="button" class="btn green" wire:click="accept">{{ __('site.messages.join_group') }}</button>
                                    <button type="button" class="btn red" wire:click="decline" wire:confirm="{{ __('site.messages.decline_invite_confirm', ['group' => $title]) }}">{{ __('site.messages.decline_invite') }}</button>
                                </div>
                            </div>
                        @elseif ($state === 'blocked')
                            <p class="chat-note">{{ __('site.messages.you_blocked', ['name' => $title]) }} <button type="button" class="link" wire:click="unblock">{{ __('site.messages.unblock') }}</button></p>
                        @elseif ($state)
                            <p class="chat-note">{{ __($state, ['name' => $title]) }}</p>
                        @else
                            @if ($hint && ! $editing)<p class="chat-hint muted small">{{ $hint }}</p>@endif
                            @if ($editing)
                                <p class="chat-editing">
                                    <span>{{ __('site.messages.editing') }}</span>
                                    <button type="button" class="link small" wire:click="cancelEdit">{{ __('site.messages.cancel') }}</button>
                                </p>
                            @endif
                            <form class="chat-compose" wire:submit="send">
                                @if ($photo && ! $editing)
                                    <div class="chat-attach">
                                        @if ($photo instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile && $photo->isPreviewable())<img src="{{ $photo->temporaryUrl() }}" alt="">@endif
                                        <button type="button" class="sell-photo-x" wire:click="removePhoto" aria-label="{{ __('site.messages.photo_remove') }}">×</button>
                                    </div>
                                @endif
                                @if (! $editing)
                                    <label class="chat-photo-btn" title="{{ __('site.messages.photo_add') }}">
                                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>
                                        <span class="sr">{{ __('site.messages.photo_add') }}</span>
                                        <input class="sr" type="file" wire:model="photo" accept="image/jpeg,image/png,image/webp">
                                    </label>
                                @endif
                                {{-- Emojis (public/js/site.js): phones also have them on their keyboard. --}}
                                <button type="button" class="chat-emoji-btn" data-emoji-for="[data-chat-input]" aria-haspopup="true" aria-expanded="false"
                                        title="{{ __('site.messages.emoji') }}" aria-label="{{ __('site.messages.emoji') }}"
                                        data-t-recent="{{ __('site.messages.emoji_recent') }}" data-t-smileys="{{ __('site.messages.emoji_smileys') }}"
                                        data-t-people="{{ __('site.messages.emoji_people') }}" data-t-hearts="{{ __('site.messages.emoji_hearts') }}"
                                        data-t-nature="{{ __('site.messages.emoji_nature') }}" data-t-life="{{ __('site.messages.emoji_life') }}">
                                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8.5 14.5a4.5 4.5 0 0 0 7 0"/><path d="M9 9.5h.01M15 9.5h.01"/></svg>
                                </button>
                                <textarea wire:model="body" rows="1" maxlength="{{ \App\Support\Messaging::MAX_LENGTH }}" placeholder="{{ __('site.messages.write') }}" aria-label="{{ __('site.messages.write') }}" data-chat-input></textarea>
                                @if ($canRecord && ! $editing)
                                    {{-- With nothing written, 🎤 stands where Send is (where the browser can record; public/js/voice.js). --}}
                                    <button type="button" class="chat-mic-btn" data-voice-record title="{{ __('site.messages.voice_record') }}" aria-label="{{ __('site.messages.voice_record') }}">
                                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21"/></svg>
                                    </button>
                                @endif
                                <button class="btn green chat-send" type="submit" wire:loading.attr="disabled" wire:target="send,photo">{{ $editing ? __('site.messages.save') : __('site.messages.send') }}</button>
                                @if ($canRecord && ! $editing)
                                    {{-- The recorder, over the box while recording; Livewire's redraws leave it alone. --}}
                                    <div class="voice-rec" wire:ignore wire:key="voice-rec-{{ $conversation?->id ?? 'new-'.$other?->id }}" hidden
                                         data-voice-rec data-max="{{ \App\Support\MessageVoice::MAX_SECONDS }}"
                                         data-t-recording="{{ __('site.messages.voice_recording') }}" data-t-sending="{{ __('site.messages.voice_sending') }}"
                                         data-t-short="{{ __('site.messages.voice_too_short') }}" data-t-mic="{{ __('site.messages.voice_mic') }}"
                                         data-t-silent="{{ __('site.messages.voice_silent') }}" data-t-no-sound="{{ __('site.messages.voice_no_sound') }}"
                                         data-t-error="{{ __('site.messages.voice_error') }}" data-t-limit="{{ __('site.messages.voice_limit', ['minutes' => \App\Support\MessageVoice::MAX_SECONDS / 60]) }}">
                                        <button type="button" class="voice-rec-cancel" data-voice-cancel title="{{ __('site.messages.voice_cancel') }}" aria-label="{{ __('site.messages.voice_cancel') }}">
                                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>
                                        </button>
                                        <span class="voice-rec-dot" aria-hidden="true"></span>
                                        <span class="voice-rec-clock" data-voice-clock>0:00</span>
                                        {{-- How loud the microphone hears it, right now: still bars mean no sound. --}}
                                        <span class="voice-rec-meter" data-voice-meter aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span>
                                        <span class="voice-rec-status" data-voice-status role="status"></span>
                                        <button type="button" class="btn green voice-rec-send" data-voice-send>{{ __('site.messages.send') }}</button>
                                    </div>
                                @endif
                            </form>
                            <p class="muted small chat-uploading" wire:loading wire:target="photo">{{ __('site.messages.uploading') }}</p>
                        @endif
                        @error('body')<p class="error small chat-error" role="alert">{{ $message }}</p>@enderror
                        @error('photo')<p class="error small chat-error" role="alert">{{ $message }}</p>@enderror
                        @error('voice')<p class="error small chat-error" role="alert">{{ $message }}</p>@enderror
                    @endif
                @endif
            </section>
        </div>
    @endif
</div>
