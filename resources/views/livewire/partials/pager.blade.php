{{-- Previous / next for a Livewire list (WithPagination), as on the Members page. --}}
@if ($page && $page->hasPages())
    <nav class="pagination" aria-label="{{ __('site.auth.pages') }}">
        @unless ($page->onFirstPage())<button type="button" class="btn ghost" wire:click="previousPage" aria-label="{{ __('site.auth.previous_page') }}">←</button>@endunless
        <span class="muted">{{ $page->currentPage() }} / {{ $page->lastPage() }}</span>
        @if ($page->hasMorePages())<button type="button" class="btn ghost" wire:click="nextPage" aria-label="{{ __('site.auth.next_page') }}">→</button>@endif
    </nav>
@endif
