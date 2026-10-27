<?php

namespace App\Livewire;

use App\Appearance\Background;
use App\Livewire\Concerns\ManagesSubmissions;
use App\Shop\Shop;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Site;

/**
 * "My posts" or "My products": what the member sent, newest first, with where its review is, laid out like
 * "My orders". On My account the latest few ("overview"); all of them on their own page, a page at a time ("all",
 * /account/posts and /account/products). Adding, changing and withdrawing stay on "Share" and "Sell with us".
 */
class MySubmissions extends Component
{
    use ManagesSubmissions, WithPagination;

    const FIRST = 5;

    const PER_PAGE = 10;

    /** posts (news, events, stories) or products */
    #[Locked]
    public string $kind = 'posts';

    /** overview (My account) or all (its own page) */
    #[Locked]
    public string $mode = 'overview';

    public function render()
    {
        $site = Site::current()->handle();
        $posts = $this->kind === 'posts';
        $mine = $this->mySubmissions($posts ? array_column(MemberPosts::TYPES, 'collection') : ['products']);
        $page = null;
        if ($this->mode === 'all') {
            $current = max(1, $this->getPage());
            $page = new LengthAwarePaginator($mine->forPage($current, self::PER_PAGE)->values(), $mine->count(), self::PER_PAGE, $current);
        }
        $pageUrl = Shop::url($site, $posts ? 'share' : 'sell');

        return view('livewire.my-submissions', [
            'all' => $this->mode === 'all',
            'group' => $posts ? 'contribute' : 'sell',
            'count' => $mine->count(),
            'page' => $page,
            'rows' => collect($page ? $page->items() : $mine->take(self::FIRST))->map(fn (EntryContract $entry) => $this->row($entry, $posts, $site, $pageUrl))->all(),
            'pageUrl' => $pageUrl,
            'allUrl' => Shop::url($site, 'account/'.($posts ? 'posts' : 'products')),
        ]);
    }

    /** One row, in the shape of partials/account-row. */
    private function row(EntryContract $entry, bool $posts, string $site, string $pageUrl): array
    {
        $status = self::reviewStatus($entry);
        $shown = $posts ? $entry : ($entry->in($site) ?? $entry);
        $sent = $entry->get('submitted_at') ? Carbon::parse($entry->get('submitted_at'))->setTimezone(Background::TIMEZONE)->translatedFormat('j M Y') : null;
        $type = $posts ? collect(MemberPosts::TYPES)->search(fn ($config) => $config['collection'] === $entry->collectionHandle()) : null;

        return [
            'title' => $shown->value('title'),
            'line' => $posts ? __('site.contribute.type_'.$type).' · '.Site::get($entry->locale())?->name() : Shop::kip((int) $entry->get('price')),
            'meta' => $sent ? __('site.auth.sent_on', ['date' => $sent]) : '',
            'badge' => __('site.'.($posts ? 'contribute' : 'sell').'.status_'.$status),
            'tone' => ['live' => 'green', 'waiting' => 'blue', 'changes' => 'red'][$status],
            // The team asked for changes: say so, and lead to where they are made.
            'action' => $status === 'changes' ? __('site.auth.fix_cta') : null,
            'note' => null,
            'url' => $status === 'live' ? ($shown->url() ?? $pageUrl) : $pageUrl,
        ];
    }
}
