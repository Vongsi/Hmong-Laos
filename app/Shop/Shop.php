<?php

namespace App\Shop;

use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Image;
use Statamic\Facades\Site;
use Statamic\Facades\Term;

/** Small helpers shared by the shop's Livewire components, templates and order code. */
class Shop
{
    public static function kip(int $amount): string
    {
        return number_format($amount).' ₭';
    }

    /** A published product, always its origin entry (price, stock and photos live there). */
    public static function product(?string $id): ?EntryContract
    {
        $entry = $id ? Entry::find($id) : null;

        if (! $entry || $entry->collectionHandle() !== 'products') {
            return null;
        }

        $root = $entry->root();

        return $root->status() === 'published' ? $root : null;
    }

    /** How many can still be sold, or null for made-to-order products with no stock count. */
    public static function available(EntryContract $product): ?int
    {
        $stock = $product->get('stock');

        return $stock === null || $stock === '' ? null : max(0, (int) $stock);
    }

    /** @return list<string> */
    public static function sizes(EntryContract $product): array
    {
        return array_values(array_filter(array_map('trim', (array) $product->get('sizes'))));
    }

    /**
     * The colours a buyer can choose, in the given site's language, from the product's "Colours" rows.
     *
     * @return list<array{slug: string, name: string, swatch: ?string, photo: ?string}>
     */
    public static function colours(EntryContract $product, string $site): array
    {
        return collect((array) $product->get('colour_options'))
            ->map(function ($row) use ($site) {
                $slug = self::first($row['colour'] ?? null);
                $term = $slug ? Term::find('colours::'.$slug) : null;
                if (! $term) {
                    return null;
                }

                return [
                    'slug' => $slug,
                    'name' => ($term->in($site) ?? $term)->title(),
                    'swatch' => $term->in(Site::default()->handle())?->get('swatch') ?? $term->get('swatch'),
                    'photo' => self::first($row['photo'] ?? null),
                ];
            })
            ->filter()->unique('slug')->values()->all();
    }

    /** A field holding one value may store it as "x" or ["x"]. */
    private static function first(mixed $value): ?string
    {
        $value = is_array($value) ? ($value[0] ?? null) : $value;

        return $value === null || $value === '' ? null : (string) $value;
    }

    public static function image(EntryContract $product, int $width, int $height): ?string
    {
        return self::photoUrl(((array) $product->get('images'))[0] ?? null, $width, $height);
    }

    /** A resized, cropped version of a photo in the "assets" container, by its path. */
    public static function photoUrl(?string $path, int $width, int $height): ?string
    {
        $asset = $path ? Asset::find('assets::'.$path) : null;

        return $asset ? Image::manipulate($asset, ['w' => $width, 'h' => $height, 'fit' => 'crop_focal']) : null;
    }

    /** Shop settings for a site; values the site doesn't set itself come from the Hmong site. */
    public static function setting(string $site, string $key): mixed
    {
        return GlobalSet::findByHandle('shop')?->in($site)?->value($key);
    }

    /** @return list<string> */
    public static function paymentMethods(string $site): array
    {
        return array_values(array_filter([
            // BCEL One first: paid in seconds and confirmed automatically (OnePay).
            OnePay::configured() && self::setting($site, 'enable_onepay') === true ? 'onepay' : null,
            self::setting($site, 'enable_transfer') !== false ? 'transfer' : null,
            self::setting($site, 'enable_cash') !== false ? 'cash' : null,
        ]));
    }

    public static function url(string $site, string $path = ''): string
    {
        return rtrim(Site::get($site)->absoluteUrl(), '/').'/'.ltrim($path, '/');
    }

    public static function orderUrl(string $site, EntryContract $order): string
    {
        return self::url($site, 'order').'?'.http_build_query(['n' => $order->get('title'), 't' => $order->get('token')]);
    }

    /**
     * Who delivers an order, from the "Courier" field the team fills in.
     * Anousith has no tracking link per parcel: its public page has a box to type the number into.
     *
     * @return array{name: string, track_url: ?string, own: bool}|null
     */
    public static function courier(EntryContract $order): ?array
    {
        return match ($order->get('courier')) {
            'anousith' => ['name' => 'Anousith Express', 'track_url' => 'https://app.anousith.express/', 'own' => false],
            'other' => ['name' => $order->get('courier_name') ?: __('site.shop.courier_other'), 'track_url' => null, 'own' => false],
            'own' => ['name' => __('site.brand'), 'track_url' => null, 'own' => true],
            default => null,
        };
    }

    /** A wa.me link that opens WhatsApp with a message to the customer. Lao numbers like "020 5555 1234" get +856. */
    public static function whatsappUrl(?string $phone, string $text): ?string
    {
        $digits = WhatsApp::number($phone);

        return $digits ? 'https://wa.me/'.$digits.'?text='.rawurlencode($text) : null;
    }
}
