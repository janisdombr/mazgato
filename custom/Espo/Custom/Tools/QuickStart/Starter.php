<?php

namespace Espo\Custom\Tools\QuickStart;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\ORM\EntityManager;

/**
 * The starter set ("example data"): roles, works with base rates, base services and add-ons with retail / corporate
 * prices and durations, the ServiceWork presets, a small rate matrix (WorkRate) and a few placeholder employees.
 *
 * ONE definition shared by the wizard button (QuickStart/action/loadExample), `bin/command quickstart-seed`
 * (setup.sh with SEED_STARTER=1) and scripts/tenant-provision --seed starter (which calls the CLI).
 *
 * Idempotent by NAME: an entity that exists already (same name, in the installation language) is skipped and
 * NEVER edited; the lines (ServiceWork / WorkRate) of a skipped service are not touched either, so a line the admin
 * deleted does not come back. Names are stored in the installation language and every name is also written to the
 * Translation table (lv/ru/en), so a user who switches language sees it translated.
 */
class Starter
{
    public const MARKER = 'quickStartLoaded';
    public const LANGS = ['lv_LV', 'ru_RU', 'en_US', 'lt_LT'];

    /**
     * Names in [lv, ru, en, lt] order, everything else is language-free.
     * works: key => [names, role key, baseRate]; services: [category, names, retail, corporate, minutes, [work keys]]
     */
    private const ROLES = [
        'wash' => [['Mazgātājs', 'Мойщик', 'Washer', 'Plovėjas']],
        'salon' => [['Salona meistars', 'Мастер салона', 'Interior master', 'Salono meistras']],
        'detail' => [['Pulētājs', 'Детейлер', 'Detailer', 'Poliruotojas']],
    ];

    private const WORKS = [
        'foam' => [['Priekšmazgāšana ar putām', 'Пена, предмойка', 'Foam pre-wash', 'Išankstinis plovimas putomis'], 'wash', 1.5],
        'ext' => [['Virsbūves mazgāšana', 'Мойка кузова', 'Exterior wash', 'Kėbulo plovimas'], 'wash', 5],
        'wheels' => [['Riteņu un loku mazgāšana', 'Мойка дисков и арок', 'Wheels and arches', 'Ratų ir arkų plovimas'], 'wash', 2],
        'dry' => [['Žāvēšana', 'Сушка', 'Drying', 'Džiovinimas'], 'wash', 3],
        'vac' => [['Salona putekļsūkšana', 'Пылесос салона', 'Interior vacuuming', 'Salono siurbimas'], 'salon', 3],
        'int' => [['Salona tīrīšana', 'Чистка салона', 'Interior cleaning', 'Salono valymas'], 'salon', 6],
        'glass' => [['Stiklu tīrīšana iekšpusē', 'Стёкла изнутри', 'Windows inside', 'Langų valymas iš vidaus'], 'salon', 2],
        'plastic' => [['Plastmasas kopšana', 'Уход за пластиком', 'Plastics dressing', 'Plastiko priežiūra'], 'salon', 2],
        'wax' => [['Vaskošana un pulēšana', 'Воск и полировка', 'Wax and polish', 'Vaškavimas ir poliravimas'], 'detail', 8],
        'engine' => [['Motora nodalījuma mazgāšana', 'Мойка моторного отсека', 'Engine bay wash', 'Variklio skyriaus plovimas'], 'detail', 6],
    ];

    private const SERVICES = [
        ['Base', ['Ekspress mazgāšana', 'Экспресс-мойка', 'Express wash', 'Greitasis plovimas'], 10, 8, 20, ['foam', 'ext', 'dry']],
        ['Base', ['Mazgāšana S', 'Мойка S', 'Wash S', 'Plovimas S'], 15, 12, 30, ['foam', 'ext', 'wheels', 'dry']],
        ['Base', ['Mazgāšana M', 'Мойка M', 'Wash M', 'Plovimas M'], 20, 16, 40, ['foam', 'ext', 'wheels', 'dry']],
        ['Base', ['Mazgāšana L', 'Мойка L', 'Wash L', 'Plovimas L'], 25, 20, 50, ['foam', 'ext', 'wheels', 'dry']],
        ['Base', ['Mazgāšana SUV/busiņš', 'Мойка внедорожник/бус', 'Wash SUV/van', 'Plovimas visureigiui / mikroautobusui'], 30, 24, 60, ['foam', 'ext', 'wheels', 'dry']],
        ['Base', ['Komplekss M', 'Комплекс M', 'Complex M', 'Kompleksas M'], 35, 28, 75, ['foam', 'ext', 'wheels', 'dry', 'vac', 'glass']],
        ['Add-on', ['Salona putekļsūkšana', 'Пылесос салона', 'Interior vacuuming', 'Salono siurbimas'], 5, 4, 15, ['vac']],
        ['Add-on', ['Pilna salona tīrīšana', 'Полная чистка салона', 'Full interior cleaning', 'Pilnas salono valymas'], 15, 12, 40, ['int']],
        ['Add-on', ['Stikli iekšpusē', 'Стёкла изнутри', 'Windows inside', 'Langai iš vidaus'], 3, 2.5, 10, ['glass']],
        ['Add-on', ['Plastmasas kopšana', 'Уход за пластиком', 'Plastics dressing', 'Plastiko priežiūra'], 4, 3, 10, ['plastic']],
        ['Add-on', ['Vaskošana', 'Нанесение воска', 'Wax', 'Vaškavimas'], 12, 10, 30, ['wax']],
        ['Add-on', ['Motora mazgāšana', 'Мойка двигателя', 'Engine wash', 'Variklio plovimas'], 15, 12, 30, ['engine']],
        ['Add-on', ['Disku kopšana', 'Уход за дисками', 'Wheel care', 'Ratlankių priežiūra'], 4, 3, 10, ['wheels']],
        ['Add-on', ['Dzīvnieku spalvu tīrīšana', 'Чистка от шерсти', 'Pet hair removal', 'Gyvūnų plaukų valymas'], 10, 8, 30, ['vac', 'int']],
    ];

    /** Rate matrix: work key, service index (see SERVICES), rate. Larger cars pay the washer more. */
    private const RATES = [
        ['ext', 2, 6], ['ext', 3, 7], ['ext', 4, 8], ['ext', 5, 6], ['dry', 3, 4], ['dry', 4, 4],
        ['wheels', 4, 2.5],
    ];

    /** employees: names, role key */
    private const EMPLOYEES = [
        ['wash', 'Darbinieks 1', 'Сотрудник 1', 'Employee 1', 'Darbuotojas 1'],
        ['wash', 'Darbinieks 2', 'Сотрудник 2', 'Employee 2', 'Darbuotojas 2'],
        ['salon', 'Darbinieks 3', 'Сотрудник 3', 'Employee 3', 'Darbuotojas 3'],
        ['detail', 'Darbinieks 4', 'Сотрудник 4', 'Employee 4', 'Darbuotojas 4'],
    ];

    private const NOTE = ['Sākuma cena - pārbaudiet', 'Стартовая цена - проверьте', 'Starter price - review', 'Pradinė kaina - patikrinkite'];

    /** @var string[] */
    private array $createdTranslations = [];

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private ConfigWriter $writer
    ) {}

    public static function normaliseLang(?string $lang): string
    {
        return in_array($lang, self::LANGS, true) ? $lang : 'lv_LV';
    }

    public function loadedAt(): ?string
    {
        $v = $this->config->get(self::MARKER);

        return is_string($v) && $v !== '' ? $v : null;
    }

    public function resetMarker(): void
    {
        $this->writer->set(self::MARKER, null);
        $this->writer->save();
    }

    /**
     * @param string $prefix  test hook: prepended to every NAME (so a test creates and removes its own copy); with a prefix the marker is not written
     * @return array{created: array<string,int>, skipped: array<string,int>, ids: array<string,list<string>>, lang: string}
     */
    public function load(?string $lang = null, string $prefix = ''): array
    {
        $lang = self::normaliseLang($lang ?? (string) $this->config->get('language'));
        $i = array_search($lang, self::LANGS, true);
        $em = $this->entityManager;
        $res = ['created' => [], 'skipped' => [], 'ids' => [], 'lang' => $lang];
        $bump = function (string $kind, bool $created, ?string $id = null) use (&$res) {
            $k = $created ? 'created' : 'skipped';
            $res[$k][$kind] = ($res[$k][$kind] ?? 0) + 1;

            if ($created && $id) {
                $res['ids'][$kind][] = $id;
            }
        };
        $name = fn (array $names): string => $prefix . $names[$i];

        $this->createdTranslations = [];
        $tx = $em->getTransactionManager();
        $tx->start();

        try {
            $roleIds = [];

            foreach (array_keys(self::ROLES) as $n => $key) {
                $nm = $name(self::ROLES[$key][0]);
                $e = $em->getRDBRepository('WorkRole')->where(['name' => $nm])->findOne();
                $new = !$e;

                if ($new) {
                    $e = $em->getNewEntity('WorkRole');
                    $e->set(['name' => $nm, 'sortOrder' => ($n + 1) * 10, 'isActive' => true]);
                    $em->saveEntity($e);
                    $this->translate(self::ROLES[$key][0], $prefix, $i);
                }

                $roleIds[$key] = $e->getId();
                $bump('roles', $new, $new ? $e->getId() : null);
            }

            $workIds = [];
            $workNew = [];

            foreach (array_keys(self::WORKS) as $n => $key) {
                [$names, $role, $rate] = self::WORKS[$key];
                $nm = $name($names);
                $e = $em->getRDBRepository('Work')->where(['name' => $nm])->findOne();
                $new = !$e;

                if ($new) {
                    $e = $em->getNewEntity('Work');
                    $e->set(['name' => $nm, 'defaultRoleId' => $roleIds[$role], 'sortOrder' => ($n + 1) * 10, 'isActive' => true,
                        'baseRate' => $rate, 'baseRateCurrency' => 'EUR']);
                    $em->saveEntity($e);
                    $this->translate($names, $prefix, $i);
                }

                $workIds[$key] = $e->getId();
                $workNew[$key] = $new;
                $bump('works', $new, $new ? $e->getId() : null);
            }

            $serviceIds = [];
            $serviceNew = [];

            foreach (self::SERVICES as $n => [$cat, $names, $retail, $corp, $mins, $wk]) {
                $nm = $name($names);
                $e = $em->getRDBRepository('WashService')->where(['name' => $nm, 'category' => $cat])->findOne();
                $new = !$e;

                if ($new) {
                    $e = $em->getNewEntity('WashService');
                    $e->set(['name' => $nm, 'category' => $cat, 'sortOrder' => ($n + 1) * 10, 'priceRetail' => $retail,
                        'priceRetailCurrency' => 'EUR', 'priceCorporate' => $corp, 'priceCorporateCurrency' => 'EUR',
                        'durationMinutes' => $mins, 'isActive' => true, 'description' => self::NOTE[$i]]);
                    $em->saveEntity($e);
                    $this->translate($names, $prefix, $i);

                    foreach ($wk as $j => $w) {
                        $l = $em->getNewEntity('ServiceWork');
                        $l->set(['serviceId' => $e->getId(), 'workId' => $workIds[$w], 'sortOrder' => $j]);
                        $em->saveEntity($l);
                        $res['ids']['serviceWorks'][] = $l->getId();
                    }
                }

                $serviceIds[$n] = $e->getId();
                $serviceNew[$n] = $new;
                $bump('services', $new, $new ? $e->getId() : null);
            }

            foreach (self::RATES as [$w, $s, $rate]) {
                if (empty($serviceNew[$s])) {
                    continue; // never touch the rates of a service that already existed
                }

                $exists = $em->getRDBRepository('WorkRate')
                    ->where(['workId' => $workIds[$w], 'serviceId' => $serviceIds[$s], 'employeeId' => null])->count() > 0;

                if ($exists) {
                    continue;
                }

                $r = $em->getNewEntity('WorkRate');
                $r->set(['workId' => $workIds[$w], 'serviceId' => $serviceIds[$s], 'rate' => $rate, 'rateCurrency' => 'EUR']);
                $em->saveEntity($r);
                $bump('rates', true, $r->getId());
            }

            $firstOfRole = [];

            foreach (self::EMPLOYEES as $row) {
                $role = array_shift($row);
                $nm = $prefix . $row[$i];
                $e = $em->getRDBRepository('Employee')->where(['name' => $nm])->findOne();
                $new = !$e;

                if ($new) {
                    $e = $em->getNewEntity('Employee');
                    $e->set(['name' => $nm, 'status' => 'Hired', 'workRoleId' => $roleIds[$role], 'language' => $lang]);
                    $em->saveEntity($e);
                    $this->translate($row, $prefix, $i);
                }

                $firstOfRole[$role] ??= $e->getId();
                $bump('employees', $new, $new ? $e->getId() : null);
            }

            // a freshly created role gets the first example employee of that role as its Quick Intake pre-selection
            foreach ($roleIds as $key => $rid) {
                if (!in_array($rid, $res['ids']['roles'] ?? [], true) || empty($firstOfRole[$key])) {
                    continue;
                }

                $role = $em->getEntityById('WorkRole', $rid);
                $role->set('defaultEmployeeId', $firstOfRole[$key]);
                $em->saveEntity($role);
            }

            $res['ids']['translations'] = $this->createdTranslations;
            $tx->commit();
        } catch (\Throwable $ex) {
            $tx->rollback();

            throw $ex;
        }

        if ($prefix === '' && !$this->loadedAt()) {
            $this->writer->set(self::MARKER, gmdate('Y-m-d H:i:s') . ' ' . $lang);
            $this->writer->save();
        }

        return $res;
    }

    /** @param string[] $names lv, ru, en, lt */
    private function translate(array $names, string $prefix, int $i): void
    {
        $em = $this->entityManager;
        $key = $prefix . $names[$i]; // the key is the name as STORED (the language the set was loaded in)

        if ($em->getRDBRepository('Translation')->where(['name' => $key])->count() > 0) {
            return;
        }

        $t = $em->getNewEntity('Translation');
        $t->set(['name' => $key, 'textLv' => $prefix . $names[0], 'textRu' => $prefix . $names[1], 'textEn' => $prefix . $names[2], 'textLt' => $prefix . $names[3]]);
        $em->saveEntity($t);
        $this->createdTranslations[] = $t->getId();
    }
}
