<?php

namespace App\Livewire;

use App\Livewire\Concerns\ManagesSubmissions;
use App\Shop\Shop;
use App\Support\PlainText;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Statamic\Contracts\Entries\Entry as EntryContract;
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

    /** The photos in the seller's order, the first being the main one: "k:<path>" saved, "n:<file name>" new. */
    public array $order = [];

    /** Colour slug => that colour's photo already saved (asset path), when changing a product. */
    public array $colourPhotos = [];

    /** Colour slug => a new photo chosen for that colour. */
    public array $colourUploads = [];

    public bool $sent = false;

    public function mount(): void
    {
        $this->site = Site::current()->handle();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->maker = (string) Auth::user()?->name;
        // The mobile number from My account, if they gave one.
        $this->contact = (string) Auth::user()?->phone;
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
        $this->order = $this->photoOrder();
        $this->colourPhotos = collect(Shop::colours($product, $this->site))->pluck('photo', 'slug')->filter()->all();
        $this->mode = 'form';
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->mode = 'list';
    }

    public function updatedUpload(): void
    {
        try {
            $this->validate(['upload.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192']], $this->messages());
        } finally {
            // Refused or not, the picker starts empty again, so one photo that's too big doesn't block the next ones.
            $chosen = $this->upload;
            $this->upload = [];
        }
        $this->photos = array_slice(array_merge($this->photos, $chosen), 0, self::MAX_PHOTOS - count($this->keptPhotos));
        $this->order = $this->photoOrder();
    }

    public function removePhoto(string $key): void
    {
        $this->keptPhotos = array_values(array_filter($this->keptPhotos, fn ($path) => 'k:'.$path !== $key));
        $this->photos = array_values(array_filter($this->newPhotos(), fn ($file) => 'n:'.$file->getFilename() !== $key));
        $this->order = $this->photoOrder();
    }

    /** Moves a photo one place earlier (-1) or later (1); the first one is the main photo. */
    public function movePhoto(string $key, int $step): void
    {
        $order = $this->photoOrder();
        $from = array_search($key, $order, true);
        $to = $from === false ? null : $from + ($step < 0 ? -1 : 1);
        if ($to === null || ! isset($order[$to])) {
            return;
        }
        [$order[$from], $order[$to]] = [$order[$to], $order[$from]];
        $this->order = $order;
    }

    public function updatedColourUploads($value, string $slug): void
    {
        try {
            $this->validate(['colourUploads.'.$slug => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192']], $this->messages());
        } catch (ValidationException $e) {
            unset($this->colourUploads[$slug]);

            throw $e;
        }
    }

    public function removeColourPhoto(string $slug): void
    {
        unset($this->colourUploads[$slug], $this->colourPhotos[$slug]);
    }

    /** The new photos that really are uploads. */
    private function newPhotos(): array
    {
        return array_values(array_filter($this->photos, fn ($file) => $file instanceof TemporaryUploadedFile));
    }

    /** Every photo's key in the seller's order: the order they chose, then any photo not in it yet. */
    private function photoOrder(): array
    {
        $keys = array_merge(
            array_map(fn ($path) => 'k:'.$path, $this->keptPhotos),
            array_map(fn ($file) => 'n:'.$file->getFilename(), $this->newPhotos()),
        );

        return array_values(array_unique(array_merge(array_intersect($this->order, $keys), $keys)));
    }

    /** A product's photos and its colours' photos (asset paths). */
    private static function allPhotos(EntryContract $product): array
    {
        return array_merge((array) $product->get('images'), collect((array) $product->get('colour_options'))->pluck('photo')->flatten()->filter()->all());
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
            'colourUploads' => ['array'],
            'colourUploads.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
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

        $folder = self::photoFolder('products', $user->getAuthIdentifier());
        $before = $product ? self::allPhotos($product) : [];
        // The photos in the order the seller put them in.
        $new = collect($this->newPhotos())->keyBy(fn ($file) => $file->getFilename());
        $images = [];
        foreach ($this->photoOrder() as $key) {
            $name = substr($key, 2);
            if (str_starts_with($key, 'k:')) {
                array_push($images, ...array_intersect([$name], $kept));
            } elseif ($new->has($name)) {
                array_push($images, ...self::storePhotos([$new[$name]], $folder));
            }
        }
        // Each colour's photo: a new one, or the one it had (the seller's or one the team added), unless removed.
        $hadColourPhotos = $product ? collect(Shop::colours($product, $this->site))->pluck('photo', 'slug') : collect();
        $colourOptions = array_map(function ($slug) use ($hadColourPhotos, $folder) {
            $upload = $this->colourUploads[$slug] ?? null;
            $had = $hadColourPhotos[$slug] ?? null;
            $photo = $upload instanceof TemporaryUploadedFile
                ? (self::storePhotos([$upload], $folder)[0] ?? null)
                : ($had !== null && ($this->colourPhotos[$slug] ?? null) === $had ? $had : null);

            return array_filter(['colour' => $slug, 'photo' => $photo]);
        }, array_values($this->colours));
        $sizes = array_values(array_unique(array_filter(array_map(fn ($size) => PlainText::line($size), explode(',', $this->sizes)))));

        $data = [
            'title' => PlainText::line($this->title),
            'summary' => PlainText::line($this->summary) ?: null,
            'description' => self::bardValue($product, 'description', $this->description),
            'images' => $images,
            'price' => (int) $this->price,
            'stock' => $this->stock === '' ? null : (int) $this->stock,
            'sizes' => $sizes,
            'colour_options' => $colourOptions,
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
        // Photos the seller removed or replaced (only their own uploads; never one the team added).
        self::deleteOwnPhotos(array_diff($before, self::allPhotos($product)), $folder);

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
        self::deleteOwnPhotos(self::allPhotos($product), self::photoFolder('products', Auth::id()));
        // With the Lao and English versions made when it was sent.
        self::deleteSubmission($product);
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'title', 'summary', 'description', 'price', 'stock', 'sizes', 'colours', 'category', 'province', 'maker', 'contact', 'keptPhotos', 'photos', 'upload', 'order', 'colourPhotos', 'colourUploads', 'sent');
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
            'colourUploads.*.image' => __('site.sell.photo_type'),
            'colourUploads.*.mimes' => __('site.sell.photo_type'),
            'colourUploads.*.max' => __('site.sell.photo_size'),
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

        $colourOptions = collect(Term::query()->where('taxonomy', 'colours')->get())
            ->map(fn ($t) => ['slug' => $t->slug(), 'name' => ($t->in($this->site) ?? $t)->title(), 'swatch' => $t->in(Site::default()->handle())?->get('swatch')])
            ->sortBy('name')->values();
        $preview = fn ($file) => $file instanceof TemporaryUploadedFile && $file->isPreviewable() ? $file->temporaryUrl() : null;
        $new = collect($this->newPhotos())->keyBy(fn ($file) => $file->getFilename());

        return view('livewire.seller-products', [
            'user' => Auth::user(),
            'mine' => $mine,
            'colourOptions' => $colourOptions,
            'categoryOptions' => $this->options('product_categories'),
            'provinceOptions' => $this->options('provinces'),
            // The photos in the seller's order, with a small preview of each.
            'tiles' => array_map(fn ($key) => ['key' => $key, 'src' => str_starts_with($key, 'k:')
                ? Shop::photoUrl(substr($key, 2), 160, 160)
                : $preview($new[substr($key, 2)] ?? null)], $this->photoOrder()),
            // A photo slot for each colour the seller ticked.
            'colourRows' => $colourOptions->whereIn('slug', $this->colours)->map(fn ($c) => $c + ['src' => isset($this->colourUploads[$c['slug']])
                ? $preview($this->colourUploads[$c['slug']])
                : (isset($this->colourPhotos[$c['slug']]) ? Shop::photoUrl($this->colourPhotos[$c['slug']], 160, 160) : null)])->values(),
            'maxPhotos' => self::MAX_PHOTOS,
            'joinUrl' => Shop::url($this->site, 'join'),
        ]);
    }
}
