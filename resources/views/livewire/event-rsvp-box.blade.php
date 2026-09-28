<div class="stack rsvp">
    <div class="row-wrap">
        <button type="button" class="btn red" wire:click="respond('{{ $mine?->status === 'going' ? 'none' : 'going' }}')">
            {{ $mine?->status === 'going' ? __('site.events.you_are_going') : __('site.events.going') }}
        </button>
        <button type="button" class="btn ghost" wire:click="respond('{{ $mine?->status === 'interested' ? 'none' : 'interested' }}')" aria-pressed="{{ $mine?->status === 'interested' ? 'true' : 'false' }}">
            {{ __('site.events.interested') }}
        </button>
    </div>
    <p class="muted small">{{ __('site.events.counts', ['going' => $going, 'interested' => $interested]) }}</p>
    @if ($askVolunteers)
        <button type="button" class="btn ghost" wire:click="toggleVolunteer">
            {{ $mine?->volunteer ? __('site.events.volunteering') : __('site.events.volunteer') }}
        </button>
    @endif
</div>
