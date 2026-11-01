<?php

namespace App\Livewire;

use App\Models\User;
use App\Support\Countries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Component;
use Livewire\WithPagination;
use Statamic\Facades\Site;

/**
 * The Members page: how many members there are and how many are online, and for signed-in
 * members everyone listed (new members are, until they hide themselves), as cards or a list, a page at a
 * time, with where they live: a province in Laos, or their country (App\Support\Countries). Live online dots come
 * from public/js/live.js.
 */
class MembersDirectory extends Component
{
    use WithPagination;

    // Without the live connection, members active this recently count as online.
    const ACTIVE_MINUTES = 5;

    const PER_PAGE = 24;

    #[Locked]
    public string $site = '';

    public string $search = '';

    /** Where: '' everywhere, a country code (LAO is all of Laos) or a province name. */
    public string $place = '';

    public bool $listed = false;

    /** grid (cards) or list (rows); kept for the rest of the visit. */
    #[Session(key: 'members-view')]
    public string $view = 'grid';

    public function setView(string $view): void
    {
        $this->view = $view === 'list' ? 'list' : 'grid';
    }

    public function mount(): void
    {
        $this->site = Site::current()->handle();
        $this->listed = (bool) Auth::user()?->show_in_directory;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPlace(): void
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
            ->when($this->place !== '', fn ($query) => $this->wherePlace($query))
            ->orderByDesc('last_seen_at')
            ->orderBy('name')
            ->paginate(self::PER_PAGE, ['id', 'name', 'province', 'country', 'avatar', 'last_seen_at']);

        // Only places where someone listed lives: Laos's provinces, then the other countries by name.
        $provinces = User::where('show_in_directory', true)
            ->whereNotNull('province')
            ->where('province', '!=', '')
            ->distinct()
            ->orderBy('province')
            ->pluck('province');

        $countries = User::where('show_in_directory', true)
            ->whereNotNull('country')
            ->where('country', '!=', Countries::LAOS)
            ->distinct()
            ->pluck('country')
            ->mapWithKeys(fn (string $code) => [$code => Countries::name($code, $this->site) ?? $code])
            ->sort();

        return view('livewire.members-directory', $data + [
            'members' => $members,
            'provinces' => $provinces,
            'countries' => $countries,
            'laos' => Countries::name(Countries::LAOS, $this->site),
            'filtered' => $search !== '' || $this->place !== '',
        ]);
    }

    /** Under each name: their province in Laos, or their country elsewhere. */
    public function placeOf(User $member): ?string
    {
        return $member->country && $member->country !== Countries::LAOS
            ? (Countries::name($member->country, $this->site) ?? $member->country)
            : ($member->province ?: null);
    }

    private function wherePlace(Builder $query): Builder
    {
        if ($this->place === Countries::LAOS) {
            // Members who chose a province before countries were asked count as in Laos too.
            return $query->where(fn ($q) => $q->where('country', Countries::LAOS)->orWhere(fn ($q) => $q->whereNull('country')->whereNotNull('province')));
        }

        return preg_match('/^[A-Z]{3}$/', $this->place)
            ? $query->where('country', $this->place)
            : $query->where('province', $this->place);
    }
}
