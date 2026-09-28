<?php

namespace App\Livewire;

use App\Models\Like;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

class LikeButton extends Component
{
    #[Locked]
    public string $entryId;

    public function mount(string $entryId): void
    {
        $this->entryId = $entryId;
    }

    public function toggle(): void
    {
        if (! Auth::check()) {
            $this->redirect(url('/join'));

            return;
        }

        $like = Like::where('entry_id', $this->entryId)->where('user_id', Auth::id())->first();
        $like ? $like->delete() : Like::create(['entry_id' => $this->entryId, 'user_id' => Auth::id()]);
    }

    public function render()
    {
        return view('livewire.like-button', [
            'count' => Like::where('entry_id', $this->entryId)->count(),
            'liked' => Auth::check() && Like::where('entry_id', $this->entryId)->where('user_id', Auth::id())->exists(),
        ]);
    }
}
