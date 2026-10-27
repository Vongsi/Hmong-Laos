<?php

namespace App\Http\Controllers\CP;

use App\Models\User as Member;
use App\Shop\SellerPayouts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Statamic\Facades\User;

/** "Prepare payout" on the dashboard (App\Widgets\SellerPayouts): a payout for everything the seller is owed now. */
class SellerPayoutController
{
    public function prepare(Request $request): RedirectResponse
    {
        abort_unless(User::current()?->can('create '.SellerPayouts::COLLECTION.' entries'), 403);
        $seller = (string) $request->input('seller');
        abort_unless($seller !== '' && Member::find($seller), 404);

        $payout = SellerPayouts::prepare($seller);

        // Nothing owed any more (another team member prepared it meanwhile): back to the dashboard.
        return $payout ? redirect($payout->editUrl()) : redirect(cp_route('dashboard'));
    }
}
