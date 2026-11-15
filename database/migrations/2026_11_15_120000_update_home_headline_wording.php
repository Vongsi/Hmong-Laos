<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The home page headline in Site settings now says "for everyone" instead of "for Hmong everywhere". This changes the
 * saved headline only where it is still the first wording, so anything the team has written stays as it is.
 */
return new class extends Migration
{
    /** Per site: the first wording, then the new one. */
    private const HEADLINES = [
        'hm' => [
            'Xov xwm tseeb thiab dab neeg txhawb siab rau Hmoob thoob ntiaj teb',
            'Xov xwm tseeb thiab dab neeg txhawb siab rau txhua tus',
        ],
        'lo' => [
            'ຂ່າວສານທີ່ເຊື່ອຖືໄດ້ ແລະ ເລື່ອງລາວສ້າງແຮງບັນດານໃຈ ສຳລັບຊາວມົ້ງທົ່ວໂລກ',
            'ຂ່າວສານທີ່ເຊື່ອຖືໄດ້ ແລະ ເລື່ອງລາວສ້າງແຮງບັນດານໃຈ ສຳລັບທຸກຄົນ',
        ],
        'en' => [
            'Trusted news and inspiring stories for Hmong everywhere',
            'Trusted news and inspiring stories for everyone',
        ],
    ];

    public function up(): void
    {
        $this->replace(0, 1);
    }

    public function down(): void
    {
        $this->replace(1, 0);
    }

    private function replace(int $from, int $to): void
    {
        foreach (self::HEADLINES as $site => $wording) {
            $row = DB::table('global_set_variables')->where('handle', 'settings')->where('locale', $site)->first();
            if (! $row) {
                continue;
            }

            $data = (array) json_decode($row->data, true);
            if (($data['home_title'] ?? null) !== $wording[$from]) {
                continue;
            }

            $data['home_title'] = $wording[$to];
            DB::table('global_set_variables')->where('id', $row->id)->update([
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }
};
