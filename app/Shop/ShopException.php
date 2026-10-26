<?php

namespace App\Shop;

use RuntimeException;

/** A problem the buyer can fix (empty cart, not enough stock). The message is shown to them. */
class ShopException extends RuntimeException {}
