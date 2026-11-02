{{-- Pages for a Livewire list (WithPagination), on the Members page and My account's lists: previous, the page
     numbers near the current one (with the first and last), next. --}}
@if ($page && $page->hasPages())
    @php
        $current = $page->currentPage();
        $last = $page->lastPage();
        $numbers = collect([1, $current - 1, $current, $current + 1, $last])->filter(fn ($n) => $n >= 1 && $n <= $last)->unique()->sort()->values();
    @endphp
    <nav class="pagination" aria-label="{{ __('site.auth.pages') }}">
        @unless ($page->onFirstPage())<button type="button" class="btn ghost" wire:click="previousPage" aria-label="{{ __('site.auth.previous_page') }}">←</button>@endunless
        @foreach ($numbers as $i => $number)
            @if ($i > 0 && $number - $numbers[$i - 1] > 1)<span class="muted" aria-hidden="true">…</span>@endif
            @if ($number === $current)
                <span class="btn page-current" aria-current="page">{{ $number }}</span>
            @else
                <button type="button" class="btn ghost" wire:click="gotoPage({{ $number }})">{{ $number }}</button>
            @endif
        @endforeach
        @if ($page->hasMorePages())<button type="button" class="btn ghost" wire:click="nextPage" aria-label="{{ __('site.auth.next_page') }}">→</button>@endif
    </nav>
@endif
