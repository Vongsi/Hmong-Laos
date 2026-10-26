<?php

namespace App\Livewire;

use App\Shop\Cart;
use App\Shop\Shop;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Statamic\Facades\Site;

/** The cart button in the header; only visible when something is in the cart. */
class CartCount extends Component
{
    #[Locked]
    public string $site;

    public function mount(): void
    {
        $this->site = Site::current()->handle();
    }

    #[On('cart-updated')]
    public function refresh(): void {}

    public function render()
    {
        return view('livewire.cart-count', [
            'count' => app(Cart::class)->count(),
            'url' => Shop::url($this->site, 'cart'),
        ]);
    }
}
