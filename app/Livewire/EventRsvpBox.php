<?php

namespace App\Livewire;

use App\Models\EventRsvp;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EventRsvpBox extends Component
{
    #[Locked]
    public string $entryId;

    #[Locked]
    public bool $askVolunteers = false;

    public function mount(string $entryId, $askVolunteers = false): void
    {
        $this->entryId = $entryId;
        $this->askVolunteers = (bool) $askVolunteers;
    }

    public function respond(string $status): void
    {
        if (! Auth::check()) {
            $this->redirect(url('/join'));

            return;
        }
        abort_unless(in_array($status, ['going', 'interested', 'none'], true), 422);

        if ($status === 'none') {
            EventRsvp::where('entry_id', $this->entryId)->where('user_id', Auth::id())->delete();

            return;
        }

        EventRsvp::updateOrCreate(
            ['entry_id' => $this->entryId, 'user_id' => Auth::id()],
            ['status' => $status],
        );
    }

    public function toggleVolunteer(): void
    {
        if (! Auth::check()) {
            $this->redirect(url('/join'));

            return;
        }

        $rsvp = EventRsvp::firstOrCreate(
            ['entry_id' => $this->entryId, 'user_id' => Auth::id()],
            ['status' => 'going'],
        );
        $rsvp->update(['volunteer' => ! $rsvp->volunteer]);
    }

    public function render()
    {
        $counts = EventRsvp::where('entry_id', $this->entryId)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('livewire.event-rsvp-box', [
            'going' => $counts['going'] ?? 0,
            'interested' => $counts['interested'] ?? 0,
            'mine' => Auth::check() ? EventRsvp::where('entry_id', $this->entryId)->where('user_id', Auth::id())->first() : null,
        ]);
    }
}
