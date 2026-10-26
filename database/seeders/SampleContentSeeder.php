<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Term;

/**
 * Loads the structure pages plus clearly-marked sample posts, events, interviews and members.
 * Run with: php artisan db:seed --class=SampleContentSeeder
 * Safe to re-run: it skips anything that already exists.
 */
class SampleContentSeeder extends Seeder
{
    private const SITES = ['hm', 'lo', 'en'];

    public function run(): void
    {
        $this->terms();
        $this->globals();
        $this->pages();
        $this->posts();
        $this->events();
        $this->interviews();
        $this->members();
        $this->shop();
        $this->colours();
        $this->products();
    }

    /** Colours products can come in. The swatch is the dot buyers see; the team adds more under Taxonomies. */
    private function colours(): void
    {
        $colours = [
            // slug => [swatch, Hmong, Lao, English]
            'red' => ['#B8263F', 'Liab', 'ສີແດງ', 'Red'],
            'black' => ['#1B1B1F', 'Dub', 'ສີດຳ', 'Black'],
            'white' => ['#F7F7F2', 'Dawb', 'ສີຂາວ', 'White'],
            'blue' => ['#2B59C3', 'Xiav', 'ສີຟ້າ', 'Blue'],
            'green' => ['#2E7A58', 'Ntsuab', 'ສີຂຽວ', 'Green'],
            'yellow' => ['#E3B23C', 'Daj', 'ສີເຫຼືອງ', 'Yellow'],
            'pink' => ['#D2497C', 'Paj yeeb', 'ສີບົວ', 'Pink'],
            'brown' => ['#7A4E2D', 'Xim av', 'ສີນ້ຳຕານ', 'Brown'],
            'silver' => ['#C0C4CC', 'Xim nyiaj', 'ສີເງິນ', 'Silver'],
            'gold' => ['#C9A227', 'Xim kub', 'ສີຄຳ', 'Gold'],
        ];

        foreach ($colours as $slug => [$swatch, $hm, $lo, $en]) {
            if (Term::find("colours::{$slug}")) {
                continue;
            }
            $term = Term::make($slug)->taxonomy('colours');
            foreach (['hm' => $hm, 'lo' => $lo, 'en' => $en] as $site => $title) {
                $term->dataForLocale($site, ['title' => $title, 'swatch' => $swatch]);
            }
            $term->save();
        }
    }

    /** Shop settings to start from; the team replaces the sample bank details in the control panel. */
    private function shop(): void
    {
        $set = GlobalSet::findByHandle('shop');
        if (! $set || $set->in('hm')?->get('payment_instructions')) {
            return;
        }

        $data = [
            'hm' => [
                'notify_email' => 'hello@example.com',
                'delivery_fee' => 30000,
                'enable_transfer' => true,
                'enable_cash' => true,
                'bank_name' => 'BCEL (sample)',
                'account_name' => 'Daim Av Hlub (sample)',
                'account_number' => '000-12-00-00000000-001',
                'pickup_place' => 'Vientiane, near Talat Sao (sample)',
                'payment_instructions' => 'Tom qab xa nyiaj, nias "Kuv them lawm" hauv qab no.',
            ],
            'lo' => [
                'pickup_place' => 'ວຽງຈັນ, ໃກ້ຕະຫຼາດເຊົ້າ (ຕົວຢ່າງ)',
                'payment_instructions' => 'ຫຼັງຈາກໂອນແລ້ວ, ກົດ "ຂ້ອຍຈ່າຍແລ້ວ" ຂ້າງລຸ່ມ.',
            ],
            'en' => [
                'pickup_place' => 'Vientiane, near Talat Sao (sample)',
                'payment_instructions' => 'After you transfer, press "I\'ve paid" below.',
            ],
        ];
        foreach ($data as $site => $values) {
            ($set->in($site) ?? $set->makeLocalization($site))->data($values)->save();
        }
    }

    /** Sample products, all marked "(sample)". Price, stock and photos are shared by every language. */
    private function products(): void
    {
        $products = [
            ['slug' => 'new-year-outfit', 'price' => 1200000, 'stock' => 5, 'sizes' => ['S', 'M', 'L'], 'category' => 'clothing', 'province' => 'xieng-khouang', 'featured' => true,
                'maker' => 'Mai Xiong, Phonsavan (sample)',
                'hm' => ['Khaub ncaws Hmoob noj peb caug (sample)', 'Tiab, tsho thiab siv sev xaws tes, xim raws li kab lis kev cai.'],
                'lo' => ['ຊຸດມົ້ງບຸນປີໃໝ່ (ຕົວຢ່າງ)', 'ກະໂປ່ງ, ເສື້ອ ແລະ ສາຍແອວ ຫຍິບດ້ວຍມື ສີຕາມປະເພນີ.'],
                'en' => ['Hmong New Year outfit (sample)', 'Hand-sewn skirt, jacket and sash in traditional colours.']],
            ['slug' => 'paj-ntaub-story-cloth', 'price' => 450000, 'stock' => 3, 'sizes' => [], 'category' => 'paj-ntaub', 'province' => 'luang-prabang', 'featured' => true,
                'maker' => 'Pa Vang, Luang Prabang (sample)',
                'hm' => ['Paj ntaub dab neeg (sample)', 'Paj ntaub qhia txog lub neej nyob hauv zos, xaws tes tag nrho.'],
                'lo' => ['ຜ້າປັກເລົ່າເລື່ອງ (ຕົວຢ່າງ)', 'ຜ້າປັກເລົ່າເລື່ອງຊີວິດໃນບ້ານ, ປັກດ້ວຍມືທັງໝົດ.'],
                'en' => ['Paj ntaub story cloth (sample)', 'An embroidered story of village life, stitched entirely by hand.']],
            ['slug' => 'silver-neck-ring', 'price' => 2500000, 'stock' => 2, 'sizes' => [], 'category' => 'silver', 'province' => 'vientiane-capital', 'featured' => false,
                'maker' => 'Tou Lee, Vientiane (sample)',
                'hm' => ['Xauv nyiaj (sample)', 'Xauv nyiaj ntaus tes rau hnub noj peb caug thiab hnub tshoob.'],
                'lo' => ['ສ້ອຍຄໍເງິນມົ້ງ (ຕົວຢ່າງ)', 'ສ້ອຍຄໍເງິນຕີດ້ວຍມື ສຳລັບບຸນປີໃໝ່ ແລະ ງານແຕ່ງ.'],
                'en' => ['Silver neck ring (xauv) (sample)', 'Hand-hammered silver for New Year and weddings.']],
            ['slug' => 'handmade-qeej', 'price' => 3800000, 'stock' => null, 'sizes' => [], 'category' => 'instruments', 'province' => 'xieng-khouang', 'featured' => true,
                'maker' => 'Kou Thao, Nong Het (sample)',
                'hm' => ['Qeej ua tes (sample)', 'Ua raws li xaj, siv sij hawm li ob lub hlis.'],
                'lo' => ['ແຄນມົ້ງເຮັດດ້ວຍມື (ຕົວຢ່າງ)', 'ສັ່ງເຮັດ, ໃຊ້ເວລາປະມານສອງເດືອນ.'],
                'en' => ['Handmade qeej (sample)', 'Made to order; takes about two months.']],
            ['slug' => 'embroidered-baby-carrier', 'price' => 900000, 'stock' => 0, 'sizes' => [], 'category' => 'paj-ntaub', 'province' => 'vientiane-capital', 'featured' => false,
                'maker' => 'Yer Moua, Vientiane (sample)',
                'hm' => ['Nyias (sample)', 'Nyias paj ntaub rau me nyuam yaus.'],
                'lo' => ['ຜ້າອູ້ມລູກປັກລາຍ (ຕົວຢ່າງ)', 'ຜ້າອູ້ມລູກປັກລາຍມົ້ງ.'],
                'en' => ['Embroidered baby carrier (nyias) (sample)', 'A paj ntaub baby carrier.']],
            ['slug' => 'mountain-coffee', 'price' => 85000, 'stock' => 40, 'sizes' => [], 'category' => 'food', 'province' => 'luang-prabang', 'featured' => true,
                'maker' => 'Hmong family farm, Luang Prabang (sample)',
                'hm' => ['Kas fes roob, 500 g (sample)', 'Kas fes cog saum roob, kib tshiab txhua lub lim tiam.'],
                'lo' => ['ກາເຟພູດອຍ 500 ກຣາມ (ຕົວຢ່າງ)', 'ກາເຟປູກເທິງພູ, ຂົ້ວໃໝ່ທຸກອາທິດ.'],
                'en' => ['Mountain coffee, 500 g (sample)', 'Grown in the hills and roasted fresh every week.']],
        ];

        foreach ($products as $i => $p) {
            if (Entry::query()->where('collection', 'products')->where('slug', $p['slug'])->exists()) {
                continue;
            }

            $origin = Entry::make()->collection('products')->locale('hm')->slug($p['slug'])
                ->date(Carbon::now()->subDays($i))
                ->data([
                    'title' => $p['hm'][0],
                    'summary' => $p['hm'][1],
                    'price' => $p['price'],
                    'stock' => $p['stock'],
                    'sizes' => $p['sizes'],
                    'product_categories' => [$p['category']],
                    'provinces' => [$p['province']],
                    'maker' => $p['maker'],
                    'featured' => $p['featured'],
                ]);
            $origin->save();

            // Products propagate, so saving the origin may already have made the other sites' versions.
            foreach (['lo', 'en'] as $site) {
                ($origin->in($site) ?? $origin->makeLocalization($site))->slug($p['slug'])->data([
                    'title' => $p[$site][0],
                    'summary' => $p[$site][1],
                ])->save();
            }
        }
    }

    /** Sample members for the Members page. Their passwords are random, so nobody can sign in as them. */
    private function members(): void
    {
        $members = [
            // name, province, minutes since last active (null = never), listed on the Members page
            ['Nou Yang', 'Vientiane Capital', 2, true],
            ['Pa Vang', 'Xieng Khouang', 45, true],
            ['Tou Lee', 'Luang Prabang', 60 * 26, true],
            ['Mai Xiong', 'Vientiane Capital', 60 * 24 * 6, true],
            ['Kou Thao', 'Bolikhamxay', null, true],
            ['Yer Moua', 'Xieng Khouang', 10, false],
        ];

        foreach ($members as $i => [$name, $province, $minutesAgo, $listed]) {
            $email = 'sample-member-'.($i + 1).'@example.com';
            if (User::where('email', $email)->exists()) {
                continue;
            }

            (new User)->forceFill([
                'name' => "{$name} (sample)",
                'email' => $email,
                'password' => Str::random(40),
                'province' => $province,
                'show_in_directory' => $listed,
                'last_seen_at' => $minutesAgo === null ? null : now()->subMinutes($minutesAgo),
            ])->save();
        }
    }

    private function terms(): void
    {
        $sets = [
            'categories' => [
                'announcements' => ['hm' => 'Tshaj tawm', 'lo' => 'ປະກາດ', 'en' => 'Announcements'],
                'community' => ['hm' => 'Community', 'lo' => 'ຊຸມຊົນ', 'en' => 'Community'],
                'culture' => ['hm' => 'Kab lis kev cai', 'lo' => 'ວັດທະນະທຳ', 'en' => 'Culture'],
                'youth' => ['hm' => 'Youth & education', 'lo' => 'ໄວໜຸ່ມ ແລະ ການສຶກສາ', 'en' => 'Youth & education'],
                'family-notices' => ['hm' => 'Family notices', 'lo' => 'ແຈ້ງການຄອບຄົວ', 'en' => 'Family notices'],
            ],
            'topics' => [
                'education' => ['hm' => 'Education', 'lo' => 'ການສຶກສາ', 'en' => 'Education'],
                'career-business' => ['hm' => 'Career & business', 'lo' => 'ອາຊີບ ແລະ ທຸລະກິດ', 'en' => 'Career & business'],
                'lifestyle' => ['hm' => 'Lifestyle', 'lo' => 'ວິຖີຊີວິດ', 'en' => 'Lifestyle'],
                'culture-arts' => ['hm' => 'Culture & arts', 'lo' => 'ວັດທະນະທຳ ແລະ ສິລະປະ', 'en' => 'Culture & arts'],
            ],
            'product_categories' => [
                'clothing' => ['hm' => 'Khaub ncaws', 'lo' => 'ເຄື່ອງນຸ່ງ', 'en' => 'Clothing'],
                'paj-ntaub' => ['hm' => 'Paj ntaub', 'lo' => 'ຜ້າປັກມົ້ງ', 'en' => 'Paj ntaub & embroidery'],
                'silver' => ['hm' => 'Hniav nyiaj hniav kub', 'lo' => 'ເຄື່ອງປະດັບເງິນ', 'en' => 'Silver jewellery'],
                'instruments' => ['hm' => 'Twj paj nruag', 'lo' => 'ເຄື່ອງດົນຕີ', 'en' => 'Instruments'],
                'food' => ['hm' => 'Zaub mov', 'lo' => 'ອາຫານ', 'en' => 'Food'],
                'crafts' => ['hm' => 'Khoom siv tes ua', 'lo' => 'ຫັດຖະກຳ', 'en' => 'Crafts & home'],
            ],
            'provinces' => [
                'vientiane-capital' => ['lo' => 'ນະຄອນຫຼວງວຽງຈັນ', 'en' => 'Vientiane Capital'],
                'vientiane-province' => ['lo' => 'ແຂວງວຽງຈັນ', 'en' => 'Vientiane Province'],
                'xieng-khouang' => ['lo' => 'ຊຽງຂວາງ', 'en' => 'Xieng Khouang'],
                'luang-prabang' => ['lo' => 'ຫຼວງພະບາງ', 'en' => 'Luang Prabang'],
                'houaphanh' => ['lo' => 'ຫົວພັນ', 'en' => 'Houaphanh'],
                'bolikhamxay' => ['lo' => 'ບໍລິຄຳໄຊ', 'en' => 'Bolikhamxay'],
                'xaisomboun' => ['lo' => 'ໄຊສົມບູນ', 'en' => 'Xaisomboun'],
                'oudomxay' => ['lo' => 'ອຸດົມໄຊ', 'en' => 'Oudomxay'],
                'luang-namtha' => ['lo' => 'ຫຼວງນ້ຳທາ', 'en' => 'Luang Namtha'],
                'phongsaly' => ['lo' => 'ຜົ້ງສາລີ', 'en' => 'Phongsaly'],
                'bokeo' => ['lo' => 'ບໍ່ແກ້ວ', 'en' => 'Bokeo'],
                'sayaboury' => ['lo' => 'ໄຊຍະບູລີ', 'en' => 'Sayaboury'],
                'khammouane' => ['lo' => 'ຄຳມ່ວນ', 'en' => 'Khammouane'],
                'savannakhet' => ['lo' => 'ສະຫວັນນະເຂດ', 'en' => 'Savannakhet'],
            ],
        ];

        foreach ($sets as $taxonomy => $terms) {
            foreach ($terms as $slug => $titles) {
                if (Term::find("{$taxonomy}::{$slug}")) {
                    continue;
                }
                $term = Term::make($slug)->taxonomy($taxonomy);
                foreach (self::SITES as $site) {
                    $term->dataForLocale($site, ['title' => $titles[$site] ?? $titles['en']]);
                }
                $term->save();
            }
        }
    }

    private function globals(): void
    {
        $set = GlobalSet::findByHandle('settings');
        if (! $set) {
            return;
        }
        $set->sites(self::SITES)->save();
        foreach (self::SITES as $site) {
            $vars = $set->in($site) ?? $set->makeLocalization($site);
            $vars->data([
                'contact_email' => 'hello@example.com',
                'contact_phone' => '+856 20 5555 0123',
                'facebook_url' => '',
            ])->save();
        }
    }

    private function pages(): void
    {
        $pages = [
            'home' => ['template' => 'home', 'title' => ['hm' => 'Tsev', 'lo' => 'ໜ້າຫຼັກ', 'en' => 'Home']],
            'xov-xwm' => ['template' => 'posts/index', 'title' => ['hm' => 'Xov Xwm', 'lo' => 'ຂ່າວສານ', 'en' => 'Feed']],
            'events' => ['template' => 'events/index', 'title' => ['hm' => 'Kev tshwm sim', 'lo' => 'ກິດຈະກຳ', 'en' => 'Events']],
            'inspire' => ['template' => 'interviews/index', 'title' => ['hm' => 'Tshoov Siab', 'lo' => 'ແຮງບັນດານໃຈ', 'en' => 'Inspire']],
            'about' => [
                'template' => 'about',
                'title' => ['hm' => 'About us', 'lo' => 'ກ່ຽວກັບພວກເຮົາ', 'en' => 'About us'],
                'intro' => [
                    'en' => 'Daim Av Hlub (The Land of Love) is a volunteer-run community website. We share news, events and culture so that Hmong families in every province of Laos, and relatives abroad, can stay connected and keep our traditions visible for the next generation.',
                    'lo' => 'Daim Av Hlub (ດິນແດນແຫ່ງຄວາມຮັກ) ເປັນເວັບໄຊຊຸມຊົນທີ່ດຳເນີນງານໂດຍອາສາສະໝັກ. ພວກເຮົາແບ່ງປັນຂ່າວສານ, ກິດຈະກຳ ແລະ ວັດທະນະທຳ ເພື່ອໃຫ້ຄອບຄົວຊາວມົ້ງທຸກແຂວງໃນລາວ ແລະ ຍາດພີ່ນ້ອງຢູ່ຕ່າງປະເທດ ເຊື່ອມຕໍ່ກັນ ແລະ ຮັກສາປະເພນີໄວ້ໃຫ້ຄົນລຸ້ນຫຼັງ.',
                ],
                'content' => [
                    'en' => "## What we do\n\n- **Share news.** Announcements from community leaders, family notices and everyday stories, in Hmong, Lao and English.\n- **Bring people together.** A calendar of festivals, workshops, sports and meetings, with RSVP so organisers know who is coming.\n- **Keep culture alive.** Paj ntaub, qeej, kwv txhiaj, recipes and elders' stories, kept in one place.\n\n## The team\n\nVongsi (founder), with volunteer editors, moderators and culture advisors.",
                    'lo' => "## ສິ່ງທີ່ພວກເຮົາເຮັດ\n\n- **ແບ່ງປັນຂ່າວສານ.** ປະກາດຈາກຜູ້ນຳຊຸມຊົນ, ແຈ້ງການຄອບຄົວ ແລະ ເລື່ອງລາວໃນຊີວິດປະຈຳວັນ.\n- **ນຳຄົນມາພົບກັນ.** ປະຕິທິນງານບຸນ, ການຝຶກອົບຮົມ, ກິລາ ແລະ ກອງປະຊຸມ.\n- **ຮັກສາວັດທະນະທຳ.** ປັກແສ່ວ, ແຄນມົ້ງ, ເພງພື້ນເມືອງ, ອາຫານ ແລະ ເລື່ອງເລົ່າຂອງຜູ້ເຖົ້າ.\n\n## ທີມງານ\n\nVongsi (ຜູ້ກໍ່ຕັ້ງ) ພ້ອມດ້ວຍບັນນາທິການ, ຜູ້ດູແລ ແລະ ທີ່ປຶກສາດ້ານວັດທະນະທຳ ທີ່ເປັນອາສາສະໝັກ.",
                ],
            ],
            'rules' => [
                'template' => 'default',
                'title' => ['hm' => 'Community rules', 'lo' => 'ກົດລະບຽບຊຸມຊົນ', 'en' => 'Community rules'],
                'intro' => [
                    'en' => 'These rules apply to posts, comments, events and messages. Volunteer moderators use them to decide what stays up.',
                    'lo' => 'ກົດລະບຽບເຫຼົ່ານີ້ໃຊ້ກັບໂພສ, ຄຳເຫັນ, ກິດຈະກຳ ແລະ ຂໍ້ຄວາມ. ຜູ້ດູແລທີ່ເປັນອາສາສະໝັກໃຊ້ມັນໃນການຕັດສິນ.',
                ],
                'content' => [
                    'en' => "1. **Respect everyone.** Speak to others the way you would speak to elders at a family gathering. Respect every clan, family, village and religion.\n2. **No hate or personal attacks.** No insults, threats, bullying or name-calling.\n3. **Keep it about the community.** Political debate and campaigning are not allowed.\n4. **Only post what is true.** Moderators remove rumours, scams and false information.\n5. **Protect privacy.** Ask before posting photos of other people, especially children. Never share someone's phone number or address.\n6. **Honour sacred traditions.** Funerals, spiritual ceremonies and family notices deserve care.\n7. **Selling needs a label.** Mark sales posts as \"For sale\".\n\n## If a rule is broken\n\nUse the Report button on any comment. A moderator will review it. Comments reported by three members are hidden until reviewed. Repeated or serious cases can lead to an account being paused or closed.",
                    'lo' => "1. **ເຄົາລົບທຸກຄົນ.** ເຄົາລົບທຸກແຊ່, ທຸກຄອບຄົວ, ທຸກບ້ານ ແລະ ທຸກສາສະໜາ.\n2. **ບໍ່ມີຄວາມກຽດຊັງ ຫຼື ການໂຈມຕີບຸກຄົນ.**\n3. **ເນັ້ນເລື່ອງຊຸມຊົນ.** ບໍ່ອະນຸຍາດການໂຕ້ວາທີທາງການເມືອງ.\n4. **ໂພສແຕ່ຄວາມຈິງ.** ຜູ້ດູແລຈະລຶບຂ່າວລື ແລະ ການຫຼອກລວງ.\n5. **ປົກປ້ອງຄວາມເປັນສ່ວນຕົວ.** ຂໍອະນຸຍາດກ່ອນໂພສຮູບຄົນອື່ນ ໂດຍສະເພາະເດັກນ້ອຍ.\n6. **ເຄົາລົບປະເພນີອັນສັກສິດ.**\n7. **ການຂາຍຕ້ອງມີປ້າຍ.**",
                ],
            ],
            'join' => ['template' => 'join', 'title' => ['hm' => 'Koom nrog', 'lo' => 'ເຂົ້າຮ່ວມ', 'en' => 'Join']],
            'sitemap' => ['template' => 'sitemap', 'title' => ['hm' => 'Site map', 'lo' => 'ແຜນຜັງເວັບໄຊ', 'en' => 'Site map']],
            'members' => ['template' => 'members', 'title' => ['hm' => 'Cov tswv cuab', 'lo' => 'ສະມາຊິກ', 'en' => 'Members']],
            'products' => ['template' => 'products/index', 'title' => ['hm' => 'Khoom muag', 'lo' => 'ສິນຄ້າ', 'en' => 'Products']],
            'cart' => ['template' => 'shop/cart', 'title' => ['hm' => 'Tawb khoom', 'lo' => 'ກະຕ່າ', 'en' => 'Cart']],
            'checkout' => ['template' => 'shop/checkout', 'title' => ['hm' => 'Xaj khoom', 'lo' => 'ສັ່ງຊື້', 'en' => 'Checkout']],
            'order' => ['template' => 'shop/order', 'title' => ['hm' => 'Daim ntawv xaj', 'lo' => 'ຄຳສັ່ງຊື້', 'en' => 'Your order']],
            'sell' => ['template' => 'shop/sell', 'title' => ['hm' => 'Muag nrog peb', 'lo' => 'ຂາຍກັບພວກເຮົາ', 'en' => 'Sell with us']],
            'share' => ['template' => 'share/index', 'title' => ['hm' => 'Sib qhia', 'lo' => 'ແບ່ງປັນ', 'en' => 'Share']],
            'account' => ['template' => 'account', 'title' => ['hm' => 'Kuv tus account', 'lo' => 'ບັນຊີຂອງຂ້ອຍ', 'en' => 'My account']],
            'forgot-password' => ['template' => 'auth/forgot_password', 'title' => ['hm' => 'Tsis nco qab lo lus zais', 'lo' => 'ລືມລະຫັດຜ່ານ', 'en' => 'Forgot your password']],
            'reset-password' => ['template' => 'auth/reset_password', 'title' => ['hm' => 'Teeb lo lus zais tshiab', 'lo' => 'ຕັ້ງລະຫັດຜ່ານໃໝ່', 'en' => 'Choose a new password']],
        ];

        // Structured collections need a tree per site before entries can be saved.
        $structure = Collection::findByHandle('pages')->structure();
        foreach (self::SITES as $site) {
            if (! $structure->in($site)) {
                $structure->makeTree($site)->tree([])->save();
            }
        }

        $ids = [];
        foreach ($pages as $slug => $page) {
            $origin = Entry::query()->where('collection', 'pages')->where('site', 'hm')->where('slug', $slug)->first();
            if (! $origin) {
                $origin = Entry::make()->collection('pages')->locale('hm')->slug($slug)->data([
                    'title' => $page['title']['hm'],
                    'template' => $page['template'],
                    'intro' => $page['intro']['en'] ?? null,
                    'content' => isset($page['content']['en']) ? Str::markdown($page['content']['en']) : null,
                ]);
                $origin->save();

                // Pages propagate, so saving the origin may already have made the other sites' versions.
                foreach (['lo', 'en'] as $site) {
                    ($origin->in($site) ?? $origin->makeLocalization($site))->slug($slug)->data(array_filter([
                        'title' => $page['title'][$site],
                        'intro' => $page['intro'][$site] ?? null,
                        'content' => isset($page['content'][$site]) ? Str::markdown($page['content'][$site]) : null,
                    ]))->save();
                }
            }
            $ids[] = $origin->id();
        }

        foreach (self::SITES as $site) {
            $tree = $structure->in($site);
            $localIds = collect($ids)->map(fn ($id) => $site === 'hm' ? $id : Entry::find($id)->in($site)?->id())->filter()->values();
            $tree->tree($localIds->map(fn ($id) => ['entry' => $id])->all())->save();
        }

        // Re-save so every localization picks up its URI from the new trees.
        Entry::query()->where('collection', 'pages')->get()->each->save();
    }

    private function posts(): void
    {
        $posts = [
            ['slug' => 'teaching-qeej-to-the-next-generation', 'days' => 5, 'category' => 'culture', 'province' => 'vientiane-capital',
                'en' => ['Teaching qeej to the next generation (sample)', 'Every Sunday, eleven teenagers learn the reed pipe from two elders. New students are welcome.',
                    "The qeej speaks. Each phrase carries words that listeners who know the instrument can follow, and it guides the soul at funerals. Fewer young people in the city learn it now, so a group of families started a weekly class.\n\nLessons are free. If you have an old qeej at home that nobody plays, the class would be glad to repair it and give it to a student."],
                'lo' => ['ສອນແຄນມົ້ງໃຫ້ຄົນລຸ້ນໃໝ່ (ຕົວຢ່າງ)', 'ທຸກວັນອາທິດ ໄວລຸ້ນ 11 ຄົນຮຽນເປົ່າແຄນນຳຜູ້ເຖົ້າສອງທ່ານ. ຍິນດີຕ້ອນຮັບນັກຮຽນໃໝ່.', 'ການຮຽນບໍ່ເສຍຄ່າ. ຖ້າທ່ານມີແຄນເກົ່າຢູ່ເຮືອນທີ່ບໍ່ມີໃຜໃຊ້ ຫ້ອງຮຽນຍິນດີສ້ອມແປງ ແລະ ມອບໃຫ້ນັກຮຽນ.']],
            ['slug' => 'village-cleanup-in-phonsavan', 'days' => 1, 'category' => 'community', 'province' => 'xieng-khouang',
                'en' => ['Village cleanup in Phonsavan: 60 volunteers turned out (sample)', 'Thank you to every family that came out on Saturday morning.', 'The next cleanup is planned for November. Bring gloves and a bag.'],
                'lo' => ['ອະນາໄມບ້ານທີ່ໂພນສະຫວັນ: ອາສາສະໝັກ 60 ຄົນ (ຕົວຢ່າງ)', 'ຂອບໃຈທຸກຄອບຄົວທີ່ມາຮ່ວມໃນເຊົ້າວັນເສົາ.', 'ການອະນາໄມຄັ້ງຕໍ່ໄປແມ່ນເດືອນພະຈິກ.']],
            ['slug' => 'new-year-stall-registration-open', 'days' => 2, 'category' => 'announcements', 'province' => 'vientiane-capital',
                'en' => ['Stall registration for New Year is open (sample)', 'Food and paj ntaub sellers can register stalls until 15 November.', 'Contact the New Year committee to register. Priority goes to local families.'],
                'lo' => ['ເປີດລົງທະບຽນຮ້ານສຳລັບງານບຸນປີໃໝ່ (ຕົວຢ່າງ)', 'ຜູ້ຂາຍອາຫານ ແລະ ຜ້າປັກລົງທະບຽນໄດ້ຮອດວັນທີ 15 ພະຈິກ.', 'ຕິດຕໍ່ຄະນະກຳມະການງານບຸນປີໃໝ່ເພື່ອລົງທະບຽນ.']],
            ['slug' => 'congratulations-graduates', 'days' => 3, 'category' => 'youth', 'province' => 'luang-prabang',
                'en' => ["Congratulations to this year's graduates (sample)", 'Send us your graduation photos and we will add them to the album.', 'Twenty-three Hmong students graduated this year. We are proud of every one of you.'],
                'lo' => ['ຂໍສະແດງຄວາມຍິນດີກັບນັກຮຽນທີ່ຈົບປີນີ້ (ຕົວຢ່າງ)', 'ສົ່ງຮູບຮັບປະລິນຍາຂອງທ່ານມາໃຫ້ພວກເຮົາ.', 'ປີນີ້ມີນັກສຶກສາຊາວມົ້ງຈົບ 23 ຄົນ.']],
        ];

        foreach ($posts as $p) {
            if (Entry::query()->where('collection', 'posts')->where('slug', $p['slug'])->exists()) {
                continue;
            }
            $date = Carbon::now()->subDays($p['days']);
            $entry = Entry::make()->collection('posts')->locale('hm')->slug($p['slug'])->date($date)->data([
                'title' => $p['en'][0], 'excerpt' => $p['en'][1], 'content' => $p['en'][2],
                'categories' => [$p['category']], 'provinces' => [$p['province']],
            ]);
            $entry->save();
            $entry->makeLocalization('lo')->date($date)->data(['title' => $p['lo'][0], 'excerpt' => $p['lo'][1], 'content' => $p['lo'][2]])->save();
            $entry->makeLocalization('en')->date($date)->data(['title' => $p['en'][0], 'excerpt' => $p['en'][1], 'content' => $p['en'][2]])->save();
        }
    }

    private function events(): void
    {
        $year = Carbon::now()->month >= 12 ? Carbon::now()->year + 1 : Carbon::now()->year;
        $events = [
            ['slug' => 'hmong-new-year', 'start' => "{$year}-12-12 08:00", 'end' => "{$year}-12-14 21:00", 'province' => 'vientiane-capital', 'volunteers' => true,
                'en' => ["Hmong New Year {$year} (sample)", 'Pov pob, qeej, paj ntaub market and traditional dress contest.', 'Three days to close the harvest year together. Come in your best traditional dress and bring the family.'],
                'lo' => ["ງານບຸນປີໃໝ່ມົ້ງ {$year} (ຕົວຢ່າງ)", 'ຖິ້ມໝາກຂ່າງ, ເປົ່າແຄນ, ຕະຫຼາດຜ້າປັກ ແລະ ປະກວດຊຸດພື້ນເມືອງ.', 'ສາມມື້ແຫ່ງການສະເຫຼີມສະຫຼອງການສິ້ນສຸດລະດູເກັບກ່ຽວ.'],
                'schedule' => [['when' => 'Day 1', 'what' => 'Opening ceremony and blessing by elders'], ['when' => 'Day 2', 'what' => 'Pov pob all day, traditional dress contest'], ['when' => 'Day 3', 'what' => 'Kwv txhiaj competition, sports finals, closing']]],
            ['slug' => 'paj-ntaub-for-beginners', 'start' => Carbon::now()->addDays(20)->format('Y-m-d').' 09:00', 'end' => null, 'province' => 'vientiane-capital', 'volunteers' => false,
                'en' => ['Paj ntaub for beginners (sample)', 'A half-day embroidery workshop with experienced makers.', 'Materials are provided. Bring your own needle if you have one.'],
                'lo' => ['ຮຽນປັກແສ່ວສຳລັບຜູ້ເລີ່ມຕົ້ນ (ຕົວຢ່າງ)', 'ຝຶກອົບຮົມປັກແສ່ວເຄິ່ງມື້ ກັບຜູ້ມີປະສົບການ.', 'ມີອຸປະກອນໃຫ້.'], 'schedule' => []],
            ['slug' => 'scholarship-info-night-phonsavan', 'start' => Carbon::now()->addDays(35)->format('Y-m-d').' 18:30', 'end' => null, 'province' => 'xieng-khouang', 'volunteers' => false,
                'en' => ['University scholarship info night (sample)', 'Students and parents learn how to apply for scholarships.', 'Former scholarship students share their experience and answer questions.'],
                'lo' => ['ຄືນແນະນຳທຶນການສຶກສາ (ຕົວຢ່າງ)', 'ນັກຮຽນ ແລະ ຜູ້ປົກຄອງຮຽນຮູ້ວິທີສະໝັກທຶນ.', 'ອະດີດນັກທຶນມາແບ່ງປັນປະສົບການ.'], 'schedule' => []],
        ];

        foreach ($events as $e) {
            if (Entry::query()->where('collection', 'events')->where('slug', $e['slug'])->exists()) {
                continue;
            }
            // Times above are Laos time; Statamic saves dates in UTC.
            $utc = fn ($time) => $time ? Carbon::parse($time, 'Asia/Vientiane')->utc()->format('Y-m-d H:i') : null;
            $shared = ['start_date' => $utc($e['start']), 'end_date' => $utc($e['end']), 'provinces' => [$e['province']], 'rsvp_enabled' => true, 'volunteers_needed' => $e['volunteers'], 'contact_phone' => '+856 20 5555 0123'];
            $entry = Entry::make()->collection('events')->locale('hm')->slug($e['slug'])->data(array_filter($shared + [
                'title' => $e['en'][0], 'summary' => $e['en'][1], 'content' => $e['en'][2], 'schedule' => $e['schedule'],
            ], fn ($v) => $v !== null && $v !== []));
            $entry->save();
            $entry->makeLocalization('lo')->data(['title' => $e['lo'][0], 'summary' => $e['lo'][1], 'content' => $e['lo'][2]])->save();
            $entry->makeLocalization('en')->data(['title' => $e['en'][0], 'summary' => $e['en'][1], 'content' => $e['en'][2]])->save();
        }
    }

    private function interviews(): void
    {
        $interviews = [
            ['slug' => 'dr-nou-yang', 'topic' => 'education', 'province' => 'vientiane-capital', 'featured' => true, 'days' => 4,
                'person' => 'Dr. Nou Yang', 'role' => 'Doctor, Vientiane · grew up in Xieng Khouang',
                'en' => ['"My mother sold vegetables so I could study medicine" (sample)', 'A made-up sample interview showing the layout.'],
                'lo' => ['"ແມ່ຂາຍຜັກເພື່ອໃຫ້ຂ້ອຍໄດ້ຮຽນແພດ" (ຕົວຢ່າງ)', 'ບົດສຳພາດຕົວຢ່າງ ເພື່ອສະແດງຮູບແບບ.'],
                'qa' => [
                    ['type' => 'question_answer', 'question' => 'Where did you grow up?', 'answer' => 'In a small village in the mountains. My mother sold vegetables at the market every morning to pay for my books.'],
                    ['type' => 'question_answer', 'question' => 'When did you decide to become a doctor?', 'answer' => "When I was twelve, my grandmother was very sick and the nearest clinic was a day's walk. Nobody there spoke Hmong."],
                    ['type' => 'pull_quote', 'quote' => 'Nobody there spoke Hmong. I thought someone from our community should be in that room.'],
                    ['type' => 'question_answer', 'question' => 'What would you tell young Hmong people today?', 'answer' => "Study hard, but don't do it alone. And keep speaking Hmong; it is a gift, not a weakness."],
                ]],
            ['slug' => 'tou-lee-coffee', 'topic' => 'career-business', 'province' => 'luang-prabang', 'featured' => false, 'days' => 12,
                'person' => 'Tou Lee', 'role' => 'Café owner, Luang Prabang',
                'en' => ['From market stall to three coffee shops (sample)', 'A made-up sample interview.'],
                'lo' => ['ຈາກແຜງຕະຫຼາດ ສູ່ຮ້ານກາເຟສາມແຫ່ງ (ຕົວຢ່າງ)', 'ບົດສຳພາດຕົວຢ່າງ.'],
                'qa' => [['type' => 'question_answer', 'question' => 'How did you start?', 'answer' => 'With one table at the morning market and coffee from my family farm.']]],
            ['slug' => 'mai-xiong-design', 'topic' => 'culture-arts', 'province' => 'vientiane-capital', 'featured' => false, 'days' => 20,
                'person' => 'Mai Xiong', 'role' => 'Fashion designer',
                'en' => ['Designing modern clothes with paj ntaub (sample)', 'A made-up sample interview.'],
                'lo' => ['ອອກແບບເສື້ອຜ້າທັນສະໄໝດ້ວຍລາຍປັກ (ຕົວຢ່າງ)', 'ບົດສຳພາດຕົວຢ່າງ.'],
                'qa' => [['type' => 'question_answer', 'question' => 'Where do your patterns come from?', 'answer' => 'From my grandmother. Every pattern has a meaning, and I try to keep it.']]],
        ];

        foreach ($interviews as $i) {
            if (Entry::query()->where('collection', 'interviews')->where('slug', $i['slug'])->exists()) {
                continue;
            }
            $date = Carbon::now()->subDays($i['days']);
            $entry = Entry::make()->collection('interviews')->locale('hm')->slug($i['slug'])->date($date)->data([
                'title' => $i['en'][0], 'intro' => $i['en'][1], 'person_name' => $i['person'], 'person_role' => $i['role'],
                'qa' => $i['qa'], 'topics' => [$i['topic']], 'provinces' => [$i['province']], 'featured' => $i['featured'],
            ]);
            $entry->save();
            $entry->makeLocalization('lo')->date($date)->data(['title' => $i['lo'][0], 'intro' => $i['lo'][1]])->save();
            $entry->makeLocalization('en')->date($date)->data(['title' => $i['en'][0], 'intro' => $i['en'][1]])->save();
        }
    }
}
