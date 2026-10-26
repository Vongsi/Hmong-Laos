<?php

namespace App\Livewire;

use App\Shop\Cart;
use App\Shop\Shop;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Statamic\Facades\Site;

class AddToCart extends Component
{
    #[Locked]
    public string $productId;

    #[Locked]
    public string $site;

    public ?string $size = null;

    public ?string $colour = null;

    public int $quantity = 1;

    public bool $added = false;

    public function mount(string $productId): void
    {
        $this->productId = $productId;
        $this->site = Site::current()->handle();
    }

    public function add(Cart $cart): void
    {
        $product = Shop::product($this->productId);
        abort_unless($product, 404);

        $sizes = Shop::sizes($product);
        $size = $sizes ? $this->size : null;
        $colours = array_column(Shop::colours($product, $this->site), 'slug');
        $colour = $colours ? $this->colour : null;
        $available = Shop::available($product);
        // Stock is per product: what's already in the cart in any size or colour counts too.
        $canAdd = ($available ?? Cart::MAX_QUANTITY) - $cart->quantityOf($product->id());

        $this->added = false;
        $this->validate([
            'colour' => $colours ? ['required', Rule::in($colours)] : ['nullable'],
            'size' => $sizes ? ['required', Rule::in($sizes)] : ['nullable'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.max(1, $canAdd)],
        ], [
            'colour.required' => __('site.shop.choose_colour'),
            'colour.in' => __('site.shop.choose_colour'),
            'size.required' => __('site.shop.choose_size'),
            'size.in' => __('site.shop.choose_size'),
            'quantity.max' => __('site.shop.only_left', ['count' => max(0, $canAdd)]),
        ]);

        if ($canAdd < 1) {
            $this->addError('quantity', __('site.shop.only_left', ['count' => 0]));

            return;
        }

        $cart->add($product->id(), $size, $colour, $this->quantity);
        $this->quantity = 1;
        $this->added = true;
        $this->dispatch('cart-updated');
    }

    public function render()
    {
        $product = Shop::product($this->productId);

        return view('livewire.add-to-cart', [
            'sizes' => $product ? Shop::sizes($product) : [],
            'colours' => $product ? Shop::colours($product, $this->site) : [],
            'available' => $product ? Shop::available($product) : 0,
            'cartUrl' => Shop::url($this->site, 'cart'),
        ]);
    }
}
