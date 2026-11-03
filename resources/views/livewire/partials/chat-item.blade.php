{{-- One conversation in the Messages list (livewire/messages.blade.php). A link too, so it opens in a new tab. --}}
<li wire:key="chat-{{ $c->id }}">
    <a class="chat-item" href="{{ $pageUrl }}?c={{ $c->id }}" wire:click.prevent="openChat({{ $c->id }})" @if ($open === $c->id) aria-current="true" @endif>
        @include('livewire.partials.chat-avatar', ['group' => $c->group, 'photo' => $c->avatar, 'initials' => $c->initials, 'tone' => $c->tone])
        <span class="chat-item-text">
            <span class="chat-item-top"><b>{{ $c->name }}</b><span class="muted small">{{ $c->when }}</span></span>
            <span class="chat-item-preview {{ $c->unread ? 'unread' : '' }}">
                @if ($c->pending)<span class="chat-tag">{{ __('site.messages.waiting') }}</span>@endif
                @if ($c->request && $c->group)<span class="chat-tag">{{ __('site.messages.invited') }}</span>@endif
                <span class="chat-item-line">{{ $c->preview }}</span>
                @if ($c->unread)<span class="chat-unread">{{ $c->unread > 99 ? '99+' : $c->unread }}</span>@endif
            </span>
        </span>
    </a>
</li>
