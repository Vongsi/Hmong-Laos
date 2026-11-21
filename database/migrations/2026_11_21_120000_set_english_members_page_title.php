<?php

use Illuminate\Database\Migrations\Migration;
use Statamic\Facades\Entry;

/**
 * The English Members page had no title of its own, so it showed the Hmong one ("Cov tswv cuab") in its heading and
 * the browser tab. This gives it "Members", as database/seeders/SampleContentSeeder.php does for new sites, but only
 * where it still has no title, so a title the team has set in the control panel stays.
 */
return new class extends Migration
{
    private const TITLE = 'Members';

    public function up(): void
    {
        $page = $this->englishPage();
        if ($page && $page->get('title') === null) {
            $page->set('title', self::TITLE)->save();
        }
    }

    public function down(): void
    {
        $page = $this->englishPage();
        if ($page && $page->get('title') === self::TITLE) {
            $page->remove('title')->save();
        }
    }

    private function englishPage()
    {
        return Entry::query()->where('collection', 'pages')->where('slug', 'members')->where('site', 'en')->first();
    }
};
