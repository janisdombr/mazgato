<?php

namespace Espo\Custom\Tools\Import;

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\EntityManager;

/**
 * Import customers (and their cars) from a CSV exported by another system.
 * The ONE implementation behind `bin/command import-customers` and the Administration page.
 *
 * Mapping:  target field => source column (header text, case-insensitive) or 1-based column number.
 * Targets:  name, firstName, lastName, phone, email, plate (several plates allowed: , ; / or spaces),
 *           brand, model, regNumber, pvnNumber, legalAddress, birthday, description, isCorporate, allowEmail
 *
 * Rules (the reasons are the point - see docs/IMPORT.md):
 *  - NO DUPLICATES. A row matches an existing customer by phone (last 8 digits), then e-mail, then company reg. number,
 *    and only when it has none of those, by exact normalised name. A match is NEVER overwritten: empty fields of the
 *    existing customer are left alone too (the import adds people and cars, it does not edit your data). The same
 *    file imported twice changes nothing the second time.
 *  - CARS: plate normalised exactly like Vehicle (upper case, letters and digits). A plate already in the system stays
 *    where it is (if it belongs to somebody else: reported as `plate_conflict`, not moved).
 *  - CONSENT: marketing flags are OFF for imported people (another company's mailing list is not consent for ours);
 *    `allowEmail` is switched on only for a row whose mapped allowEmail column says yes.
 *  - DRY RUN writes nothing and answers with the same report.
 *  - Each row is isolated: one bad row is reported, the rest go on. Hard limit 20 000 rows per run.
 */
class CustomerImporter
{
    public const MAX_ROWS = 20000;

    public const TARGETS = ['name', 'firstName', 'lastName', 'phone', 'email', 'plate', 'brand', 'model', 'regNumber', 'pvnNumber',
        'legalAddress', 'birthday', 'description', 'isCorporate', 'allowEmail'];

    /** @var array<string, string> */
    private array $byPhone = [];
    /** @var array<string, string> */
    private array $byEmail = [];
    /** @var array<string, string> */
    private array $byReg = [];
    /** @var array<string, string> */
    private array $byName = [];
    /** @var array<string, array{id: string, customerId: ?string}> */
    private array $plates = [];

    public function __construct(private EntityManager $em)
    {}

    // ------------------------------------------------------------------ parsing

    /** UTF-8 text from whatever the other system exported (BOM stripped; legacy Baltic code pages converted). */
    public static function toUtf8(string $raw, ?string $encoding = null): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        if ($encoding && !in_array(strtolower($encoding), ['utf-8', 'utf8'], true)) {
            return self::convert($raw, $encoding);
        }

        return mb_check_encoding($raw, 'UTF-8') ? $raw : self::convert($raw, 'CP1257');
    }

    /** Legacy code pages (mbstring does not know Windows-1257) through iconv; an unknown name is a clear 400. */
    private static function convert(string $raw, string $encoding): string
    {
        $name = strtoupper(trim($encoding));
        $name = preg_replace('/^WINDOWS-/', 'CP', $name) ?? $name;

        if (!preg_match('/^[A-Z0-9._-]{3,20}$/', $name)) {
            throw new BadRequest('bad_encoding');
        }

        $out = @iconv($name, 'UTF-8//IGNORE', $raw);

        if ($out === false) {
            throw new BadRequest('bad_encoding');
        }

        return $out;
    }

    public static function detectDelimiter(string $text): string
    {
        $first = strtok($text, "\n") ?: '';
        $best = ';';
        $max = -1;

        foreach ([';', ',', "\t", '|'] as $d) {
            $n = substr_count($first, $d);

            if ($n > $max) {
                $max = $n;
                $best = $d;
            }
        }

        return $best;
    }

    /** @return array{header: list<string>, rows: list<list<string>>} */
    public static function parse(string $text, ?string $delimiter = null): array
    {
        $delimiter = $delimiter ?: self::detectDelimiter($text);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $text);
        rewind($fh);

        $header = null;
        $rows = [];

        while (($r = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
            if ($r === [null] || (count($r) === 1 && trim((string) $r[0]) === '')) {
                continue;
            }

            $r = array_map(fn($c) => trim((string) $c), $r);

            if ($header === null) {
                $header = $r;

                continue;
            }

            $rows[] = $r;

            if (count($rows) > self::MAX_ROWS) {
                fclose($fh);

                throw new BadRequest('too_many_rows');
            }
        }

        fclose($fh);

        if ($header === null) {
            throw new BadRequest('empty_file');
        }

        return ['header' => $header, 'rows' => $rows];
    }

    /**
     * Resolve "target => header or number" into target => column index. Unknown targets / columns are errors,
     * not silently ignored (a typo would import nothing and look like success).
     *
     * @param array<string, string> $map
     * @param list<string> $header
     * @return array<string, int>
     */
    public static function resolveMap(array $map, array $header): array
    {
        $lower = array_map(fn($h) => mb_strtolower(trim($h)), $header);
        $out = [];

        foreach ($map as $target => $col) {
            if (!in_array($target, self::TARGETS, true)) {
                throw new BadRequest('unknown_target:' . $target);
            }

            $col = trim((string) $col);

            if ($col === '') {
                continue;
            }

            if (ctype_digit($col) && (int) $col >= 1 && (int) $col <= count($header)) {
                $out[$target] = (int) $col - 1;

                continue;
            }

            $i = array_search(mb_strtolower($col), $lower, true);

            if ($i === false) {
                throw new BadRequest('unknown_column:' . $col);
            }

            $out[$target] = (int) $i;
        }

        if (!isset($out['name']) && !(isset($out['firstName']) || isset($out['lastName'])) && !isset($out['plate'])) {
            throw new BadRequest('map_needs_name_or_plate');
        }

        return $out;
    }

    /** Header synonyms (Latvian / Russian / English exports) -> target field. Only exact header matches; the operator confirms. */
    private const SYNONYMS = [
        'name' => ['name', 'full name', 'customer', 'client', 'klients', 'vārds uzvārds', 'vards uzvards', 'nosaukums', 'имя', 'клиент', 'фио', 'название'],
        'firstName' => ['first name', 'firstname', 'vārds', 'vards', 'имя'],
        'lastName' => ['last name', 'lastname', 'surname', 'uzvārds', 'uzvards', 'фамилия'],
        'phone' => ['phone', 'telephone', 'mobile', 'tel', 'tālrunis', 'talrunis', 'telefons', 'mob', 'телефон', 'моб'],
        'email' => ['email', 'e-mail', 'e-pasts', 'epasts', 'pasts', 'почта', 'эл. почта'],
        'plate' => ['plate', 'plates', 'plate number', 'reg nr', 'reg. nr.', 'numurs', 'auto numurs', 'valsts nr', 'valsts numurs', 'car number', 'номер', 'госномер', 'номер авто', 'гос. номер'],
        'brand' => ['brand', 'make', 'marka', 'марка'],
        'model' => ['model', 'modelis', 'модель'],
        'regNumber' => ['reg_number', 'reg number', 'registration number', 'company code', 'reģ. nr.', 'reģ nr', 'regnr', 'рег. номер', 'инн'],
        'pvnNumber' => ['pvn_number', 'pvn number', 'vat', 'vat number', 'pvn nr', 'pvn reģ. nr.', 'ндс'],
        'legalAddress' => ['legal_address', 'address', 'adrese', 'juridiskā adrese', 'адрес'],
        'birthday' => ['birthday', 'birth date', 'dzimšanas diena', 'dzimsanas diena', 'дата рождения'],
        'description' => ['notes', 'note', 'comment', 'comments', 'piezīmes', 'piezimes', 'komentārs', 'заметки', 'комментарий'],
        'allowEmail' => ['marketing_email', 'newsletter', 'consent', 'piekrišana', 'согласие'],
    ];

    /**
     * A first guess of the mapping from the header row (exact synonym match, first column wins) so the operator only
     * has to confirm it. @param list<string> $header @return array<string, string> target => header text
     */
    public static function suggestMap(array $header): array
    {
        $out = [];

        foreach (self::SYNONYMS as $target => $names) {
            foreach ($header as $h) {
                if (in_array(mb_strtolower(trim($h)), $names, true) && !in_array($h, $out, true)) {
                    $out[$target] = $h;

                    break;
                }
            }
        }

        // "Vārds" alone with no surname column means the full name
        if (isset($out['firstName']) && !isset($out['lastName']) && !isset($out['name'])) {
            $out['name'] = $out['firstName'];
            unset($out['firstName']);
        }

        return $out;
    }

    /**
     * "name:Vārds,phone:Tel" -> array (the CLI cannot carry "=" inside an option value: the command-line parser
     * splits on it, so ":" is the separator; "=" is still understood where it survives, e.g. in tests).
     * A header that itself contains a comma is wrapped in double quotes.
     *
     * @return array<string, string>
     */
    public static function parseMapString(string $s): array
    {
        $out = [];

        foreach (str_getcsv($s, ',', '"', '\\') as $pair) {
            $i = strcspn($pair, ':=');

            if ($i >= strlen($pair)) {
                continue;
            }

            $out[trim(substr($pair, 0, $i))] = trim(substr($pair, $i + 1));
        }

        return $out;
    }

    // ------------------------------------------------------------------ normalisation

    public static function phoneKey(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($d) >= 8 ? substr($d, -8) : $d;
    }

    public static function plateKey(string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($plate)) ?? '');
    }

    /** @return list<string> */
    public static function splitPlates(string $cell): array
    {
        $out = [];

        foreach (preg_split('/[,;\/\n|]+/', $cell) ?: [] as $p) {
            $p = trim($p);

            if ($p !== '') {
                $out[] = $p;
            }
        }

        return $out;
    }

    public static function nameKey(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? '');
    }

    private static function truthy(string $v): bool
    {
        return in_array(mb_strtolower(trim($v)), ['1', 'y', 'yes', 'true', 'jā', 'ja', 'да', 'x', '+'], true);
    }

    // ------------------------------------------------------------------ index of what is already there

    private function loadIndexes(): void
    {
        $this->byPhone = $this->byEmail = $this->byReg = $this->byName = $this->plates = [];

        foreach ($this->em->getRDBRepository('Customer')->where(['deleted' => false])->select(['id', 'name', 'phone', 'email', 'regNumber'])->find() as $c) {
            $id = $c->getId();

            if ($c->get('phone') && ($k = self::phoneKey((string) $c->get('phone'))) !== '') {
                $this->byPhone[$k] ??= $id;
            }

            if ($c->get('email')) {
                $this->byEmail[mb_strtolower(trim((string) $c->get('email')))] ??= $id;
            }

            if ($c->get('regNumber')) {
                $this->byReg[preg_replace('/\s+/', '', (string) $c->get('regNumber'))] ??= $id;
            }

            if ($c->get('name')) {
                $this->byName[self::nameKey((string) $c->get('name'))] ??= $id;
            }
        }

        foreach ($this->em->getRDBRepository('Vehicle')->where(['deleted' => false])->select(['id', 'name', 'customerId'])->find() as $v) {
            $this->plates[(string) $v->get('name')] = ['id' => $v->getId(), 'customerId' => $v->get('customerId')];
        }
    }

    // ------------------------------------------------------------------ run

    /**
     * @param array{header: list<string>, rows: list<list<string>>} $table
     * @param array<string, string> $map
     * @return array<string, mixed> the report
     */
    public function run(array $table, array $map, bool $dryRun = true): array
    {
        $cols = self::resolveMap($map, $table['header']);
        $this->loadIndexes();

        $rep = [
            'dryRun' => $dryRun, 'rows' => count($table['rows']), 'customersCreated' => 0, 'customersMatched' => 0,
            'vehiclesCreated' => 0, 'vehiclesExisting' => 0, 'skipped' => 0,
            'reasons' => [], 'problems' => [], 'sample' => [],
        ];

        foreach ($table['rows'] as $i => $r) {
            $line = $i + 2; // 1 = header
            $get = fn(string $t): string => isset($cols[$t]) ? trim((string) ($r[$cols[$t]] ?? '')) : '';

            $name = $get('name') ?: trim($get('firstName') . ' ' . $get('lastName'));
            $phone = $get('phone');
            $email = $get('email');
            $reg = preg_replace('/\s+/', '', $get('regNumber')) ?? '';
            $plates = self::splitPlates($get('plate'));

            if ($name === '' && $phone === '' && $email === '' && !$plates) {
                $this->problem($rep, $line, 'empty_row', true);

                continue;
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->problem($rep, $line, 'invalid_email', false);
                $email = '';
            }

            // ---- find an existing customer, strongest key first
            $existing = null;
            $weak = false;

            if ($phone !== '' && ($k = self::phoneKey($phone)) !== '' && isset($this->byPhone[$k])) {
                $existing = $this->byPhone[$k];
            } elseif ($email !== '' && isset($this->byEmail[mb_strtolower($email)])) {
                $existing = $this->byEmail[mb_strtolower($email)];
            } elseif ($reg !== '' && isset($this->byReg[$reg])) {
                $existing = $this->byReg[$reg];
            } elseif ($phone === '' && $email === '' && $reg === '' && $name !== '') {
                $weak = true; // a bare name is the only key: exact, normalised match only

                if (isset($this->byName[self::nameKey($name)])) {
                    $existing = $this->byName[self::nameKey($name)];
                }
            }

            // a car-only row (no person) belongs to whoever already owns the plate, else it needs a person
            if ($name === '' && $existing === null && $phone === '' && $email === '') {
                foreach ($plates as $p) {
                    $pk = self::plateKey($p);

                    if (isset($this->plates[$pk]) && $this->plates[$pk]['customerId']) {
                        $existing = $this->plates[$pk]['customerId'];

                        break;
                    }
                }

                if ($existing === null) {
                    $this->problem($rep, $line, 'no_customer_for_plate', true);

                    continue;
                }
            }

            if ($existing === null && $name === '') {
                $name = $phone !== '' ? $phone : $email; // a phone-only row still deserves a card
            }

            $customerId = $existing;

            if ($existing !== null) {
                $rep['customersMatched']++;
            } else {
                $new = $this->newCustomerData($name, $phone, $email, $reg, $get);

                if ($weak) {
                    $this->problem($rep, $line, 'name_only_key', false);
                }

                if ($dryRun) {
                    $customerId = 'dry:' . $i;
                } else {
                    try {
                        $c = $this->em->createEntity('Customer', $new, ['skipHooks' => false]);
                        $customerId = $c->getId();
                    } catch (\Throwable $e) {
                        $this->problem($rep, $line, 'customer_failed', true, $e->getMessage());

                        continue;
                    }
                }

                $rep['customersCreated']++;
                $this->remember($customerId, $name, $phone, $email, $reg);

                if (count($rep['sample']) < 10) {
                    $rep['sample'][] = ['line' => $line, 'name' => $name, 'phone' => $phone, 'email' => $email, 'plates' => $plates];
                }
            }

            // ---- cars
            foreach ($plates as $p) {
                $pk = self::plateKey($p);

                // only ASCII letters and digits (plus the usual separators) are a plate the rest of the system can match
                if (strlen($pk) < 2 || strlen($pk) > 14 || !preg_match('/^[A-Za-z0-9\s\-.]+$/', $p)) {
                    $this->problem($rep, $line, 'invalid_plate', false, $p);

                    continue;
                }

                if (isset($this->plates[$pk])) {
                    $owner = $this->plates[$pk]['customerId'];

                    if ($owner && $owner !== $customerId && !str_starts_with((string) $customerId, 'dry:')) {
                        $this->problem($rep, $line, 'plate_conflict', false, $pk);
                    } else {
                        $rep['vehiclesExisting']++;
                    }

                    continue;
                }

                $veh = ['name' => $pk, 'customerId' => str_starts_with((string) $customerId, 'dry:') ? null : $customerId];

                if ($get('brand') !== '') {
                    $veh['brand'] = $get('brand');
                }

                if ($get('model') !== '') {
                    $veh['model'] = $get('model');
                }

                if (!$dryRun) {
                    try {
                        $v = $this->em->createEntity('Vehicle', $veh);
                        $this->plates[$pk] = ['id' => $v->getId(), 'customerId' => $customerId];
                    } catch (\Throwable $e) {
                        $this->problem($rep, $line, 'vehicle_failed', false, $pk);

                        continue;
                    }
                } else {
                    $this->plates[$pk] = ['id' => 'dry', 'customerId' => $customerId];
                }

                $rep['vehiclesCreated']++;
            }
        }

        $rep['problems'] = array_slice($rep['problems'], 0, 200);

        return $rep;
    }

    /** @return array<string, mixed> */
    private function newCustomerData(string $name, string $phone, string $email, string $reg, callable $get): array
    {
        $d = ['name' => $name];

        if ($phone !== '') {
            $d['phone'] = $phone;
        }

        if ($email !== '') {
            $d['email'] = $email;
        }

        if ($reg !== '') {
            $d['regNumber'] = $reg;
        }

        if ($get('pvnNumber') !== '') {
            $d['pvnNumber'] = $get('pvnNumber');
        }

        if ($get('legalAddress') !== '') {
            $d['legalAddress'] = $get('legalAddress');
        }

        if ($get('description') !== '') {
            $d['description'] = $get('description');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $get('birthday')) || preg_match('/^(\d{2})[.\/](\d{2})[.\/](\d{4})$/', $get('birthday'), $m)) {
            $d['birthday'] = isset($m[3]) ? ($m[3] . '-' . $m[2] . '-' . $m[1]) : $get('birthday');
        }

        $corporate = $get('isCorporate') !== '' ? self::truthy($get('isCorporate')) : ($reg !== '');
        $d['isCorporate'] = $corporate;

        // consent is never inherited from another system's list
        $d['allowEmail'] = $get('allowEmail') !== '' && self::truthy($get('allowEmail')) && $email !== '';
        $d['allowWhatsapp'] = false;
        $d['allowPhone'] = false;

        return $d;
    }

    private function remember(string $id, string $name, string $phone, string $email, string $reg): void
    {
        if ($phone !== '' && ($k = self::phoneKey($phone)) !== '') {
            $this->byPhone[$k] ??= $id;
        }

        if ($email !== '') {
            $this->byEmail[mb_strtolower($email)] ??= $id;
        }

        if ($reg !== '') {
            $this->byReg[$reg] ??= $id;
        }

        if ($name !== '') {
            $this->byName[self::nameKey($name)] ??= $id;
        }
    }

    /** @param array<string, mixed> $rep */
    private function problem(array &$rep, int $line, string $reason, bool $skipped, string $detail = ''): void
    {
        $rep['reasons'][$reason] = ($rep['reasons'][$reason] ?? 0) + 1;

        if ($skipped) {
            $rep['skipped']++;
        }

        if (count($rep['problems']) < 200) {
            $rep['problems'][] = ['line' => $line, 'reason' => $reason, 'detail' => $detail];
        }
    }
}
