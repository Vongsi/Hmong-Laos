<div class="cmt {{ $isReply ? 'reply' : '' }}" wire:key="c{{ $comment->id }}">
    <div class="avatar">{{ mb_strtoupper(mb_substr($comment->user->name ?? '?', 0, 2)) }}</div>
    <div>
        <div class="cmt-bubble">
            <b>{{ $comment->user->name ?? __('site.comments.member') }}</b>
            <p>{{ $comment->body }}</p>
        </div>
        <div class="cmt-meta">
            <span>{{ $comment->created_at->diffForHumans() }}</span>
            @auth
                @unless ($isReply)
                    <button type="button" wire:click="startReply({{ $comment->id }})">{{ __('site.comments.reply') }}</button>
                @endunless
                @if (auth()->id() == $comment->user_id)
                    <button type="button" wire:click="delete({{ $comment->id }})" wire:confirm="{{ __('site.comments.confirm_delete') }}">{{ __('site.comments.delete') }}</button>
                @else
                    <button type="button" wire:click="report({{ $comment->id }})">{{ __('site.comments.report') }}</button>
                @endif
            @endauth
        </div>
    </div>
</div>
