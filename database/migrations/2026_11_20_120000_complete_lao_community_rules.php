<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;
use Statamic\Facades\Entry;

/**
 * The Lao Community rules page was a shortened translation: it left out "If a rule is broken" (how to report, what
 * moderators do) and part of most rules. This gives it the same text as the English. It changes the page only where
 * it is still the first, short text (database/seeders/SampleContentSeeder.php), so anything the team has written in the
 * control panel stays as it is.
 */
return new class extends Migration
{
    private const FIRST = "1. **ເຄົາລົບທຸກຄົນ.** ເຄົາລົບທຸກແຊ່, ທຸກຄອບຄົວ, ທຸກບ້ານ ແລະ ທຸກສາສະໜາ.\n2. **ບໍ່ມີຄວາມກຽດຊັງ ຫຼື ການໂຈມຕີບຸກຄົນ.**\n3. **ເນັ້ນເລື່ອງຊຸມຊົນ.** ບໍ່ອະນຸຍາດການໂຕ້ວາທີທາງການເມືອງ.\n4. **ໂພສແຕ່ຄວາມຈິງ.** ຜູ້ດູແລຈະລຶບຂ່າວລື ແລະ ການຫຼອກລວງ.\n5. **ປົກປ້ອງຄວາມເປັນສ່ວນຕົວ.** ຂໍອະນຸຍາດກ່ອນໂພສຮູບຄົນອື່ນ ໂດຍສະເພາະເດັກນ້ອຍ.\n6. **ເຄົາລົບປະເພນີອັນສັກສິດ.**\n7. **ການຂາຍຕ້ອງມີປ້າຍ.**";

    private const COMPLETE = "1. **ເຄົາລົບທຸກຄົນ.** ເວົ້າກັບຄົນອື່ນຄືກັບທີ່ທ່ານເວົ້າກັບຜູ້ເຖົ້າຜູ້ແກ່ໃນງານຊຸມນຸມຄອບຄົວ. ເຄົາລົບທຸກແຊ່, ທຸກຄອບຄົວ, ທຸກບ້ານ ແລະ ທຸກສາສະໜາ.\n2. **ບໍ່ມີຄວາມກຽດຊັງ ຫຼື ການໂຈມຕີບຸກຄົນ.** ບໍ່ດ່າ, ບໍ່ຂົ່ມຂູ່, ບໍ່ຂົ່ມເຫັງ ແລະ ບໍ່ຕັ້ງສະຍານາມເຍາະເຍີ້ຍຄົນອື່ນ.\n3. **ເນັ້ນເລື່ອງຊຸມຊົນ.** ບໍ່ອະນຸຍາດການໂຕ້ວາທີ ແລະ ການໂຄສະນາຫາສຽງທາງການເມືອງ.\n4. **ໂພສແຕ່ຄວາມຈິງ.** ຜູ້ດູແລຈະລຶບຂ່າວລື, ການຫຼອກລວງ ແລະ ຂໍ້ມູນທີ່ບໍ່ຖືກຕ້ອງ.\n5. **ປົກປ້ອງຄວາມເປັນສ່ວນຕົວ.** ຂໍອະນຸຍາດກ່ອນໂພສຮູບຄົນອື່ນ ໂດຍສະເພາະເດັກນ້ອຍ. ຢ່າແບ່ງປັນເບີໂທ ຫຼື ທີ່ຢູ່ຂອງຄົນອື່ນ.\n6. **ເຄົາລົບປະເພນີອັນສັກສິດ.** ງານສົບ, ພິທີກຳທາງຈິດວິນຍານ ແລະ ແຈ້ງການຄອບຄົວ ຄວນໄດ້ຮັບການເອົາໃຈໃສ່ເປັນພິເສດ.\n7. **ການຂາຍຕ້ອງມີປ້າຍ.** ໝາຍໂພສຂາຍເຄື່ອງວ່າ \"ຂາຍ\".\n\n## ຖ້າມີການລະເມີດກົດລະບຽບ\n\nກົດປຸ່ມ \"ລາຍງານ\" ຢູ່ຄຳເຫັນໃດກໍໄດ້. ຜູ້ດູແລຈະກວດເບິ່ງ. ຄຳເຫັນທີ່ສະມາຊິກລາຍງານຄົບສາມຄົນ ຈະຖືກເຊື່ອງໄວ້ຈົນກວ່າຈະກວດເບິ່ງແລ້ວ. ກໍລະນີທີ່ເກີດຊ້ຳ ຫຼື ຮ້າຍແຮງ ອາດເຮັດໃຫ້ບັນຊີຖືກໂຈະ ຫຼື ປິດ.";

    public function up(): void
    {
        $this->replace(self::FIRST, self::COMPLETE);
    }

    public function down(): void
    {
        $this->replace(self::COMPLETE, self::FIRST);
    }

    /** The page's text is HTML made from this Markdown, as the seeder saved it. */
    private function replace(string $from, string $to): void
    {
        $page = Entry::query()->where('collection', 'pages')->where('slug', 'rules')->where('site', 'lo')->first();
        if (! $page || trim((string) $page->get('content')) !== trim(Str::markdown($from))) {
            return;
        }

        $page->set('content', Str::markdown($to))->save();
    }
};
