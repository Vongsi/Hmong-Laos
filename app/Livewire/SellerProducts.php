<?php

namespace App\Livewire;

use App\Livewire\Concerns\ManagesSubmissions;
use App\Shop\Shop;
use App\Support\PlainText;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Term;

/**
 * "Sell with us" for signed-in members: they add products themselves, with photos from their phone.
 * A product arrives as a draft; a moderator checks it in the control panel and publishes it, or writes
 * a "Message to the seller" (see App\Listeners\TrackMemberSubmission). Members can change or withdraw a
 * product until it is published.
 */
class SellerProducts extends Component
{
    use ManagesSubmissions, WithFileUploads;

    const MAX_PHOTOS = 8;

    // Products (new or changed) one member can send per hour.
    const PER_HOUR = 6;

    #[Locked]
    public string $site;

    public string $mode = 'list';

    #[Locked]
    public ?string $editing = null;

    public string $title = '';

    public string $summary = '';

    public string $description = '';

    public string $price = '';

    public string $stock = '';

    public string $sizes = '';

    public array $colours = [];

    public string $category = '';

    public string $province = '';

    public string $maker = '';

    public string $contact = '';

    /** Photos already saved (asset paths), when changing a product. */
    public array $keptPhotos = [];

    /** New photos chosen in this form. */
    public array $photos = [];

    /** What the file picker just added; moved into $photos. */
    public array $upload = [];

    public bool $sent = false;

    public function mount(): void
    {
        $this->site = Site::current()->handle();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->maker = (string) Auth::user()?->name;
        $this->mode = 'form';
    }

    public function edit(string $id): void
    {
        $product = $this->ownDraft($id, ['products']);
        $this->resetForm();
        $this->editing = $product->id();
        $this->title = (string) $product->get('title');
        $this->summary = (string) $product->get('summary');
        $this->description = self::bardText($product, 'description');
        $this->price = (string) $product->get('price');
        $this->stock = $product->get('stock') === null ? '' : (string) $product->get('stock');
        $this->sizes = implode(', ', Shop::sizes($product));
        $this->colours = array_column(Shop::colours($product, $this->site), 'slug');
        $this->category = (string) (((array) $product->get('product_categories'))[0] ?? '');
        $this->province = (string) (((array) $product->get('provinces'))[0] ?? '');
        $this->maker = (string) $product->get('maker');
        $this->contact = (string) $product->get('maker_contact');
        $this->keptPhotos = array_values((array) $product->get('images'));
        $this->mode = 'form';
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->mode = 'list';
    }

    public function updatedUpload(): void
    {
        $this->validate(['upload.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192']], $this->messages());
        $this->photos = array_slice(array_merge($this->photos, $this->upload), 0, self::MAX_PHOTOS - count($this->keptPhotos));
        $this->upload = [];
    }

    public function removeKept(int $index): void
    {
        unset($this->keptPhotos[$index]);
        $this->keptPhotos = array_values($this->keptPhotos);
    }

    public function removeNew(int $index): void
    {
        unset($this->photos[$index]);
        $this->photos = array_values($this->photos);
    }

    public function save(): void
    {
        $user = Auth::user();
        abort_unless($user, 403);
        $product = $this->editing ? $this->ownDraft($this->editing, ['products']) : null;
        // Only photos this product already had can be kept.
        $kept = $product ? array_values(array_intersect($this->keptPhotos, (array) $product->get('images'))) : [];

        $this->validate([
            'title' => ['required', 'string', 'max:120'],
            'summary' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'sizes' => ['nullable', 'string', 'max:200'],
            'colours' => ['array'],
            'colours.*' => [Rule::in($this->options('colours')->keys()->all())],
            'category' => ['nullable', Rule::in($this->options('product_categories')->keys()->all())],
            'province' => ['nullable', Rule::in($this->options('provinces')->keys()->all())],
            'maker' => ['nullable', 'string', 'max:150'],
            'contact' => ['required', 'string', 'max:60'],
            'photos' => ['array', 'max:'.(self::MAX_PHOTOS - count($kept))],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], $this->messages());

        if (PlainText::line($this->title) === '' || PlainText::line($this->contact) === '') {
            $this->addError(PlainText::line($this->title) === '' ? 'title' : 'contact', __('site.shop.required'));

            return;
        }
        if (count($kept) + count($this->photos) === 0) {
            $this->addError('photos', __('site.sell.photo_required'));

            return;
        }
        if (! $this->allowSubmission('sell', self::PER_HOUR)) {
            $this->addError('form', __('site.sell.too_many'));

            return;
        }

        $images = array_merge($kept, self::storePhotos($this->photos, self::photoFolder('products', $user->getAuthIdentifier())));
        $sizes = array_values(array_unique(array_filter(array_map(fn ($size) => PlainText::line($size), explode(',', $this->sizes)))));
        // Keep any colour photo a moderator added for a colour the seller still offers.
        $colourPhotos = $product ? collect(Shop::colours($product, $this->site))->pluck('photo', 'slug') : collect();

        $data = [
            'title' => PlainText::line($this->title),
            'summary' => PlainText::line($this->summary) ?: null,
            'description' => self::bardValue($product, 'description', $this->description),
            'images' => $images,
            'price' => (int) $this->price,
            'stock' => $this->stock === '' ? null : (int) $this->stock,
            'sizes' => $sizes,
            'colour_options' => array_map(fn ($slug) => array_filter(['colour' => $slug, 'photo' => $colourPhotos[$slug] ?? null]), array_values($this->colours)),
            'product_categories' => $this->category ? [$this->category] : [],
            'provinces' => $this->province ? [$this->province] : [],
            'maker' => PlainText::line($this->maker) ?: PlainText::line($user->name),
            'maker_contact' => PlainText::line($this->contact),
            'submitted_by' => [(string) $user->getAuthIdentifier()],
            // Moderators can't look up member accounts, so the name is kept with the product.
            'submitted_by_name' => PlainText::line($user->name),
            'submitted_at' => $product?->get('submitted_at') ?? now()->toIso8601String(),
            // Sending it again answers the moderator's message.
            'review_note' => null,
            'review_note_notified' => null,
        ];

        if ($product) {
            $product->merge($data)->save();
        } else {
            $slug = (Str::slug($data['title']) ?: 'product').'-'.Str::lower(Str::random(5));
            $product = Entry::make()->collection('products')->locale(Site::default()->handle())
                ->slug($slug)->date(now())->published(false)->data($data);
            $product->save();
        }

        self::tellTeam($product, $this->editing ? 'Product changed, ready to review again' : 'New product to review', [
            'Sent by: '.$data['submitted_by_name'],
            'Price: '.Shop::kip($data['price']),
        ]);
        $this->resetForm();
        $this->mode = 'list';
        $this->sent = true;
    }

    public function withdraw(string $id): void
    {
        $product = $this->ownDraft($id, ['products']);
        self::deleteOwnPhotos((array) $product->get('images'), self::photoFolder('products', Auth::id()));
        // With the Lao and English versions made when it was sent.
        self::deleteSubmission($product);
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'title', 'summary', 'description', 'price', 'stock', 'sizes', 'colours', 'category', 'province', 'maker', 'contact', 'keptPhotos', 'photos', 'upload', 'sent');
        $this->resetErrorBag();
    }

    protected function messages(): array
    {
        return [
            '*.required' => __('site.shop.required'),
            '*.integer' => __('site.shop.invalid'),
            '*.min' => __('site.shop.invalid'),
            '*.max' => __('site.shop.invalid'),
            '*.in' => __('site.shop.invalid'),
            'photos.*.image' => __('site.sell.photo_type'),
            'photos.*.mimes' => __('site.sell.photo_type'),
            'photos.*.max' => __('site.sell.photo_size'),
            'upload.*.image' => __('site.sell.photo_type'),
            'upload.*.mimes' => __('site.sell.photo_type'),
            'upload.*.max' => __('site.sell.photo_size'),
        ];
    }

    /** slug => title in the visitor's language, for a taxonomy. */
    private function options(string $taxonomy)
    {
        return Term::query()->where('taxonomy', $taxonomy)->get()
            ->mapWithKeys(fn ($term) => [$term->slug() => ($term->in($this->site) ?? $term)->title()])
            ->sort();
    }

    public function render()
    {
        $mine = $this->mySubmissions(['products'])->map(fn ($p) => (object) [
            'id' => $p->id(),
            'title' => ($p->in($this->site) ?? $p)->value('title'),
            'price' => (int) $p->get('price'),
            'thumb' => Shop::image($p, 160, 160),
            'status' => self::reviewStatus($p),
            'note' => $p->get('review_note'),
            'url' => $p->published() ? ($p->in($this->site) ?? $p)->url() : null,
        ]);

        return view('livewire.seller-products', [
            'user' => Auth::user(),
            'mine' => $mine,
            'colourOptions' => collect(Term::query()->where('taxonomy', 'colours')->get())
                ->map(fn ($t) => ['slug' => $t->slug(), 'name' => ($t->in($this->site) ?? $t)->title(), 'swatch' => $t->in(Site::default()->handle())?->get('swatch')])
                ->sortBy('name')->values(),
            'categoryOptions' => $this->options('product_categories'),
            'provinceOptions' => $this->options('provinces'),
            'keptThumbs' => array_map(fn ($path) => Shop::photoUrl($path, 160, 160), $this->keptPhotos),
            'maxPhotos' => self::MAX_PHOTOS,
            'joinUrl' => Shop::url($this->site, 'join'),
        ]);
    }
}
