<?php

namespace App\Modifiers;

use App\Shop\Shop;
use Statamic\Modifiers\Modifier;

/** {{ price | kip }} → "150,000 ₭" */
class Kip extends Modifier
{
    public function index($value)
    {
        return Shop::kip((int) $value);
    }
}
