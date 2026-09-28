<section class="stack comments-box" id="comments">
    <h2 class="h-sm">{{ __('site.comments.title') }} ({{ $count }})</h2>

    @if (session('comments.status'))
        <p class="note" role="status">{{ session('comments.status') }}</p>
    @endif

    @auth
        <form class="cmt-form" wire:submit="post">
            <div class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name ?? '?', 0, 2)) }}</div>
            <div>
                @if ($replyTo)
                    <p class="muted small">{{ __('site.comments.replying') }} <button type="button" class="link" wire:click="cancelReply">{{ __('site.comments.cancel') }}</button></p>
                @endif
                <label for="cmt-body" class="muted small">{{ __('site.comments.label') }}</label>
                <textarea id="cmt-body" wire:model="body" rows="3" maxlength="2000"></textarea>
                @error('body') <p class="error small">{{ $message }}</p> @enderror
                <div class="row">
                    <span class="muted small">{{ __('site.comments.be_kind') }}</span>
                    <button class="btn" type="submit" wire:loading.attr="disabled">{{ __('site.comments.post') }}</button>
                </div>
            </div>
        </form>
    @else
        <p class="panel">{{ __('site.comments.join_prompt') }} <a href="{{ url('/join') }}">{{ __('site.nav.join') }}</a></p>
    @endauth

    <div class="comments">
        @foreach ($comments as $comment)
            @include('livewire.partials.comment', ['comment' => $comment, 'isReply' => false])
            @foreach ($comment->replies as $reply)
                @include('livewire.partials.comment', ['comment' => $reply, 'isReply' => true])
            @endforeach
        @endforeach
    </div>
</section>
