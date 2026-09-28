<?php

namespace App\Livewire;

use App\Models\Comment;
use App\Models\CommentReport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Comments extends Component
{
    // Hides a comment from the page once this many members report it.
    const HIDE_AFTER_REPORTS = 3;

    #[Locked]
    public string $entryId;

    #[Validate('required|string|min:2|max:2000')]
    public string $body = '';

    #[Locked]
    public ?int $replyTo = null;

    public function mount(string $entryId): void
    {
        $this->entryId = $entryId;
    }

    public function startReply(int $commentId): void
    {
        $this->replyTo = Comment::where('entry_id', $this->entryId)->whereNull('parent_id')->findOrFail($commentId)->id;
    }

    public function cancelReply(): void
    {
        $this->replyTo = null;
    }

    public function post(): void
    {
        abort_unless(Auth::check(), 403);
        $this->validate();

        $key = 'comment:'.Auth::id();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('body', __('site.comments.too_fast'));

            return;
        }
        RateLimiter::hit($key, 60);

        Comment::create([
            'entry_id' => $this->entryId,
            'user_id' => Auth::id(),
            'parent_id' => $this->replyTo,
            'body' => trim($this->body),
        ]);

        $this->reset('body', 'replyTo');
    }

    public function report(int $commentId): void
    {
        abort_unless(Auth::check(), 403);
        $comment = Comment::where('entry_id', $this->entryId)->findOrFail($commentId);

        CommentReport::firstOrCreate(['comment_id' => $comment->id, 'user_id' => Auth::id()]);

        if ($comment->reports()->count() >= self::HIDE_AFTER_REPORTS) {
            $comment->update(['status' => 'hidden']);
        }

        session()->flash('comments.status', __('site.comments.reported'));
    }

    public function delete(int $commentId): void
    {
        $comment = Comment::where('entry_id', $this->entryId)->findOrFail($commentId);
        abort_unless(Auth::id() === $comment->user_id || (bool) Auth::user()?->super, 403);
        $comment->delete();
    }

    public function render()
    {
        $comments = Comment::with(['user', 'replies.user'])
            ->where('entry_id', $this->entryId)
            ->whereNull('parent_id')
            ->where('status', 'published')
            ->latest()
            ->get();

        return view('livewire.comments', [
            'comments' => $comments,
            'count' => Comment::where('entry_id', $this->entryId)->where('status', 'published')->count(),
        ]);
    }
}
