<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The Members page: how many members there are and how many are online, and for signed-in
 * members the list of everyone who chose to be listed. Live online dots come from public/js/live.js.
 */
class MembersDirectory extends Component
{
    use WithPagination;

    // Without the live connection, members active this recently count as online.
    const ACTIVE_MINUTES = 5;

    const PER_PAGE = 24;

    public string $search = '';

    public string $province = '';

    public bool $listed = false;

    public function mount(): void
    {
        $this->listed = (bool) Auth::user()?->show_in_directory;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedProvince(): void
    {
        $this->resetPage();
    }

    public function updatedListed(bool $value): void
    {
        abort_unless(Auth::check(), 403);
        User::whereKey(Auth::id())->update(['show_in_directory' => $value]);
    }

    public function render()
    {
        $data = [
            'total' => User::count(),
            'activeNow' => User::where('last_seen_at', '>=', now()->subMinutes(self::ACTIVE_MINUTES))->count(),
        ];

        if (! Auth::check()) {
            return view('livewire.members-directory', $data);
        }

        $search = addcslashes(mb_substr(trim($this->search), 0, 50), '%_\\');

        $members = User::where('show_in_directory', true)
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->when($this->province !== '', fn ($query) => $query->where('province', $this->province))
            ->orderByDesc('last_seen_at')
            ->orderBy('name')
            ->paginate(self::PER_PAGE, ['id', 'name', 'province', 'last_seen_at']);

        $provinces = User::where('show_in_directory', true)
            ->whereNotNull('province')
            ->where('province', '!=', '')
            ->distinct()
            ->orderBy('province')
            ->pluck('province');

        return view('livewire.members-directory', $data + [
            'members' => $members,
            'provinces' => $provinces,
            'filtered' => $search !== '' || $this->province !== '',
        ]);
    }
}
