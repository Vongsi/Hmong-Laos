<?php

namespace App\Livewire;

use App\Shop\Cart;
use App\Shop\PlaceOrder;
use App\Shop\Shop;
use App\Shop\ShopException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Statamic\Facades\Site;

class Checkout extends Component
{
    #[Locked]
    public string $site;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $delivery = 'pickup';

    public string $address = '';

    public string $payment = '';

    public string $note = '';

    public function mount(): void
    {
        $this->site = Site::current()->handle();
        $this->payment = Shop::paymentMethods($this->site)[0] ?? '';

        if ($user = Auth::user()) {
            $this->name = (string) $user->name;
            $this->email = (string) $user->email;
        }
    }

    /** Short messages in the visitor's language instead of Laravel's English ones. */
    protected function messages(): array
    {
        return [
            '*.required' => __('site.shop.required'),
            'address.required_if' => __('site.shop.required'),
            '*.email' => __('site.shop.invalid'),
            '*.regex' => __('site.shop.invalid'),
            '*.max' => __('site.shop.invalid'),
            '*.in' => __('site.shop.invalid'),
        ];
    }

    public function placeOrder(Cart $cart, PlaceOrder $placeOrder)
    {
        $key = 'checkout:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('order', __('site.shop.too_many'));

            return null;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+()\-\s]{6,40}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'delivery' => ['required', Rule::in(['pickup', 'delivery'])],
            'address' => ['required_if:delivery,delivery', 'nullable', 'string', 'max:500'],
            'payment' => ['required', Rule::in(Shop::paymentMethods($this->site))],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        RateLimiter::hit($key, 600);

        try {
            $order = $placeOrder($cart, [
                'name' => trim($this->name),
                'phone' => trim($this->phone),
                'email' => trim($this->email),
                'delivery' => $this->delivery,
                'address' => trim($this->address),
                'payment' => $this->payment,
                'note' => trim($this->note),
                'user' => Auth::id(),
            ], $this->site);
        } catch (ShopException $e) {
            $this->addError('order', $e->getMessage());

            return null;
        }

        $this->dispatch('cart-updated');

        return $this->redirect(Shop::orderUrl($this->site, $order));
    }

    public function render()
    {
        $lines = app(Cart::class)->lines($this->site);
        $subtotal = $lines->sum('lineTotal');
        $fee = $this->delivery === 'delivery' ? (int) Shop::setting($this->site, 'delivery_fee') : 0;

        return view('livewire.checkout', [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'fee' => $fee,
            'total' => $subtotal + $fee,
            'deliveryFee' => (int) Shop::setting($this->site, 'delivery_fee'),
            'methods' => Shop::paymentMethods($this->site),
            'pickupPlace' => Shop::setting($this->site, 'pickup_place'),
            'cartUrl' => Shop::url($this->site, 'cart'),
            'productsUrl' => Shop::url($this->site, 'products'),
        ]);
    }
}
