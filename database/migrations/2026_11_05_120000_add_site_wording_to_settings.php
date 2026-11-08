<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The slogan, home page wording and Inspire intro moved from the language files to Globals → Site settings, so the
 * team can change them. This puts the first wording there; anything already saved is left as it is.
 */
return new class extends Migration
{
    private const WORDING = [
        'hm' => [
            'slogan' => 'Sib qhia tej yam zoo rau peb txhua tus',
            'home_title' => 'Xov xwm tseeb thiab dab neeg txhawb siab rau Hmoob thoob ntiaj teb',
            'home_text' => 'Xov xwm, kev tshwm sim thiab lub neej txhua hnub ntawm cov tsev neeg Hmoob thoob ntiaj teb, uas peb pab neeg xyuas ua ntej muab tso rau. Kawm los ntawm cov neeg uas ua tau zoo, thiab nrhiav tej khoom uas tsev neeg Hmoob ua.',
            'inspire_intro' => 'Kev xam phaj nrog cov neeg uas tau tsim lawv txoj hauj lwm, kev lag luam thiab tsev neeg, thiab tej lus lawv xav qhia rau tiam tom ntej.',
        ],
        'lo' => [
            'slogan' => 'ແບ່ງປັນສິ່ງດີໆ ເພື່ອພວກເຮົາທຸກຄົນ',
            'home_title' => 'ຂ່າວສານທີ່ເຊື່ອຖືໄດ້ ແລະ ເລື່ອງລາວສ້າງແຮງບັນດານໃຈ ສຳລັບຊາວມົ້ງທົ່ວໂລກ',
            'home_text' => 'ຂ່າວສານ, ກິດຈະກຳ ແລະ ຊີວິດປະຈຳວັນຂອງຄອບຄົວຊາວມົ້ງທົ່ວໂລກ ທີ່ທີມງານກວດເບິ່ງກ່ອນສະແດງ. ຮຽນຮູ້ຈາກຄົນທີ່ສ້າງສິ່ງດີໆ ແລະ ຊອກຫາສິນຄ້າທີ່ຄອບຄົວຊາວມົ້ງເຮັດເອງ.',
            'inspire_intro' => 'ບົດສຳພາດຄົນທີ່ສ້າງອາຊີບ, ທຸລະກິດ ແລະ ຄອບຄົວ ພ້ອມຄຳແນະນຳສຳລັບຄົນລຸ້ນໃໝ່.',
        ],
        'en' => [
            'slogan' => 'Sharing good things, for all of us',
            'home_title' => 'Trusted news and inspiring stories for Hmong everywhere',
            'home_text' => 'News, events and everyday life from Hmong families around the world, checked by our team before they appear. Learn from people who have built something good, and find products made by Hmong families.',
            'inspire_intro' => 'Interviews with people who have built careers, businesses and families, and what they would tell the next generation.',
        ],
    ];

    public function up(): void
    {
        foreach (self::WORDING as $site => $wording) {
            $row = DB::table('global_set_variables')->where('handle', 'settings')->where('locale', $site)->first();

            if (! $row) {
                DB::table('global_set_variables')->insert([
                    'handle' => 'settings',
                    'locale' => $site,
                    'data' => json_encode($wording, JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                continue;
            }

            $data = (array) json_decode($row->data, true);
            foreach ($wording as $key => $text) {
                if (blank($data[$key] ?? null)) {
                    $data[$key] = $text;
                }
            }
            DB::table('global_set_variables')->where('id', $row->id)->update([
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('global_set_variables')->where('handle', 'settings')->get() as $row) {
            $data = array_diff_key((array) json_decode($row->data, true), array_flip(['slogan', 'home_title', 'home_text', 'inspire_intro']));
            DB::table('global_set_variables')->where('id', $row->id)->update(['data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
        }
    }
};
