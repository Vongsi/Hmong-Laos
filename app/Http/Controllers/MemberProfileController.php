<?php

namespace App\Http\Controllers;

use App\Livewire\MemberProfile;
use Illuminate\Http\Request;
use Statamic\Facades\Site;
use Statamic\View\View;

/**
 * A member's profile page (/members/12, /lo/members/12…): the site's layout around App\Livewire\MemberProfile, as
 * Route::statamic() would render it. A controller rather than Route::statamic() with a data closure, because the
 * page's title is the member's name and closures can't go into the route cache the server builds when it starts.
 */
class MemberProfileController extends Controller
{
    public function __invoke(Request $request, string $member)
    {
        $data = MemberProfile::routeData((int) $member, Site::get($request->route('site')) ?? Site::default());

        return response(app(View::class)->template('member')->layout('layout')->with($data)->render());
    }
}
