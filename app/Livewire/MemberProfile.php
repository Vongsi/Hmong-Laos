<?php

namespace App\Livewire;

use App\Models\User;
use App\Shop\Shop;
use App\Support\Messaging;
use App\Support\ProfilePhoto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Term;
use Statamic\Sites\Site as StatamicSite;

/**
 * A member's profile (/members/12, /lo/members/12…; routes/web.php), opened from their card on the Members page.
 *
 * It shows only what members agree to show other members when they are in the members list ("Show me in the members
 * list": name, photo, province or country, and when they are online), plus the news posts they wrote, which already
 * carry their name on the site. Never their clan, district, phone or email, which My account promises only they and
 * the team see. Like the Members page it is for signed-in members; guests are asked to join, for any number, so they
 * can't tell who has an account. Members who aren't in the list, or who blocked the viewer, are "not found".
 * Everyone can see their own profile, to check what others see.
 */
class MemberProfile extends Component
{
    /** News posts shown at first, and how many more each "Show more" adds. */
    const POSTS = 6;

    #[Locked]
    public int $memberId = 0;

    #[Locked]
    public string $site = '';

    public int $shown = self::POSTS;

    public function mount(int $memberId): void
    {
        $this->memberId = $memberId;
        $this->site = Site::current()->handle();
    }

    /** The member whose profile this viewer may open, or null. */
    public static function visible(int $id, ?User $viewer): ?User
    {
        if (! $viewer) {
            return null;
        }
        if ((int) $viewer->id === $id) {
            return $viewer;
        }
        $member = User::where('show_in_directory', true)->find($id);

        return $member && ! Messaging::hasBlocked($member->id, $viewer->id) ? $member : null;
    }

    /**
     * The page's data for its route: the member's name as its title, and the same profile in the other languages for
     * the language menu. Signed-in members get "not found" for a profile they can't open.
     */
    public static function routeData(int $id, StatamicSite $site): array
    {
        $viewer = Auth::user();
        $member = self::visible($id, $viewer);
        abort_if($viewer && ! $member, 404);

        return [
            'title' => $member?->name ?: trans('site.members.profile', [], $site->lang()),
            'member_id' => $id,
            'locale_path' => 'members/'.$id,
            'noindex' => true,
        ];
    }

    public function more(): void
    {
        $this->shown += self::POSTS;
    }

    public function render()
    {
        $base = rtrim((string) Site::get($this->site)->url(), '/');
        $viewer = Auth::user();
        $data = ['member' => null, 'membersUrl' => $base.'/members', 'joinUrl' => $base.'/join'];
        if (! $viewer) {
            return view('livewire.member-profile', $data);
        }

        $member = self::visible($this->memberId, $viewer);
        abort_unless($member, 404);
        $isMe = (int) $member->id === (int) $viewer->id;
        $posts = $this->posts($member);

        return view('livewire.member-profile', [
            'member' => $member,
            'name' => $member->name ?: __('site.comments.member'),
            'isMe' => $isMe,
            'place' => $member->placeName($this->site),
            // Twice the size shown, for sharp phone screens.
            'photo' => $member->avatar ? ProfilePhoto::url($member->avatar, 224) : null,
            'canMessage' => ! $isMe && Messaging::cannotStart($viewer, $member) === null,
            'messagesUrl' => route('messages.'.$this->site).'?to='.$member->id,
            'accountUrl' => $base.'/account',
            'postCount' => $posts->count(),
            'posts' => $posts->take($this->shown)->map(fn (EntryContract $post) => $this->card($post))->all(),
        ] + $data);
    }

    /**
     * Their published news posts, newest first: in this language when the team translated one, otherwise as they
     * wrote it. Only news posts say who wrote them on the site, so events, stories and products sent by a member
     * aren't listed here.
     *
     * @return Collection<int, EntryContract>
     */
    private function posts(User $member): Collection
    {
        return Entry::query()->where('collection', 'posts')->where('author', (string) $member->id)->get()
            ->map(fn (EntryContract $post) => $post->root())
            ->unique(fn (EntryContract $post) => $post->id())
            ->map(function (EntryContract $post) {
                $here = $post->in($this->site);

                return $here && $here->status() === 'published' ? $here : $post;
            })
            ->filter(fn (EntryContract $post) => $post->status() === 'published')
            ->sortByDesc(fn (EntryContract $post) => $post->date()?->timestamp ?? 0)
            ->values();
    }

    /** A post as the home page's cards show it. */
    private function card(EntryContract $post): array
    {
        $image = $post->value('image');
        $category = collect((array) $post->value('categories'))->first();
        $term = $category ? Term::find('categories::'.$category)?->in($this->site) : null;

        return [
            'id' => $post->id(),
            'url' => $post->url(),
            'title' => (string) $post->value('title'),
            'date' => $post->date()?->format('j M Y'),
            'image' => Shop::photoUrl(is_array($image) ? ($image[0] ?? null) : $image, 600, 375),
            'category' => $term ? ['slug' => $term->slug(), 'title' => $term->title()] : null,
        ];
    }
}
