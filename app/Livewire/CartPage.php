<?php

namespace App\Livewire;

use App\Shop\Cart;
use App\Shop\Shop;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Statamic\Facades\Site;

class CartPage extends Component
{
    #[Locked]
    public string $site;

    public function mount(): void
    {
        $this->site = Site::current()->handle();
    }

    public function setQuantity(string $lineId, $quantity, Cart $cart): void
    {
        $cart->setQuantity($lineId, (int) $quantity);
        $this->dispatch('cart-updated');
    }

    public function remove(string $lineId, Cart $cart): void
    {
        $cart->setQuantity($lineId, 0);
        $this->dispatch('cart-updated');
    }

    public function render()
    {
        $lines = app(Cart::class)->lines($this->site);

        return view('livewire.cart-page', [
            'lines' => $lines,
            'subtotal' => $lines->sum('lineTotal'),
            'checkoutUrl' => Shop::url($this->site, 'checkout'),
            'productsUrl' => Shop::url($this->site, 'products'),
        ]);
    }
}
