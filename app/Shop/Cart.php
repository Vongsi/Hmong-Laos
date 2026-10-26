<?php

namespace App\Shop;

use Illuminate\Support\Collection;

/**
 * The visitor's cart, kept in their session. Only product ids, sizes and quantities are stored;
 * prices and stock are always read fresh from the product.
 */
class Cart
{
    // Most of one product a buyer can order when it is made to order (no stock count).
    const MAX_QUANTITY = 20;

    private const KEY = 'shop.cart';

    public function add(string $productId, ?string $size, ?string $colour, int $quantity): void
    {
        $lines = $this->raw();
        $key = self::key($productId, $size, $colour);
        $lines[$key] = [
            'product' => $productId,
            'size' => $size,
            'colour' => $colour,
            'quantity' => ($lines[$key]['quantity'] ?? 0) + $quantity,
        ];
        session()->put(self::KEY, $lines);
    }

    /** How many of a product are in the cart, over all its sizes and colours (stock is per product). */
    public function quantityOf(string $productId): int
    {
        return (int) collect($this->raw())->where('product', $productId)->sum('quantity');
    }

    /** Lines are addressed by a short id, so sizes with quotes or spaces never end up in markup. */
    public function setQuantity(string $lineId, int $quantity): void
    {
        $lines = $this->raw();
        foreach ($lines as $key => $line) {
            if (self::lineId($key) === $lineId) {
                if ($quantity < 1) {
                    unset($lines[$key]);
                } else {
                    $lines[$key]['quantity'] = min($quantity, self::MAX_QUANTITY);
                }
            }
        }
        session()->put(self::KEY, $lines);
    }

    public function clear(): void
    {
        session()->forget(self::KEY);
    }

    public function count(): int
    {
        return (int) array_sum(array_column($this->raw(), 'quantity'));
    }

    /**
     * The cart with current product data, in the given site's language. Products that were
     * unpublished or deleted drop out; quantities above what's left are capped.
     *
     * @return Collection<int, object>
     */
    public function lines(string $site): Collection
    {
        return collect($this->raw())->map(function (array $line, string $key) use ($site) {
            $product = Shop::product($line['product']);
            if (! $product) {
                return null;
            }

            $available = Shop::available($product);
            $quantity = min($line['quantity'], $available ?? self::MAX_QUANTITY);
            $price = (int) $product->get('price');
            $local = $product->in($site) ?? $product;
            $colour = collect(Shop::colours($product, $site))->firstWhere('slug', $line['colour'] ?? null);

            return (object) [
                'id' => self::lineId($key),
                'product' => $product,
                'title' => $local->value('title'),
                'url' => $local->url(),
                'image' => Shop::image($product, 160, 160),
                'size' => $line['size'],
                'colour' => $colour['name'] ?? null,
                'requested' => $line['quantity'],
                'quantity' => $quantity,
                'available' => $available,
                'unitPrice' => $price,
                'lineTotal' => $price * $quantity,
            ];
        })->filter()->values();
    }

    /** @return array<string, array{product: string, size: ?string, quantity: int}> */
    private function raw(): array
    {
        return session(self::KEY, []);
    }

    private static function key(string $productId, ?string $size, ?string $colour): string
    {
        return $productId.'|'.($size ?? '').'|'.($colour ?? '');
    }

    private static function lineId(string $key): string
    {
        return substr(md5($key), 0, 12);
    }
}
