<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Mail\EmailSender;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Custom\Tools\Accounting\SalesJournal;
use Espo\Custom\Tools\Subscriptions\Subscriptions;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Custom\Tools\Kiosk\DeviceGuard;
use Espo\Custom\Tools\Shifts\ClockService;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\Tools\Pdf\Service as PdfService;

use stdClass;

/**
 * Fast car-wash intake endpoint. A single entry point shared by the Quick Intake
 * dashlet, and (later) by WhatsApp/Telegram/website hooks and ANPR cameras.
 *
 *  POST /api/v1/CarWash/action/intake      { plate }               -> lookup
 *  POST /api/v1/CarWash/action/createOrder { plate, ... }          -> create customer+vehicle+order
 */
class CarWash
{
    /** Name of the editable Template record used by the monthly invoice. */
    private const MONTHLY_INVOICE_TEMPLATE = 'Rēķins par mēnesi';

    public function __construct(
        private EntityManager $entityManager,
        private PdfService $pdfService,
        private EmailSender $emailSender,
        private Log $log,
        private SelectBuilderFactory $selectBuilderFactory,
        private Config $config,
        private User $user,
        private ClockService $clockService,
        private SalesJournal $salesJournal,
        private Subscriptions $subscriptions,
        private Acl $acl,
        private \Espo\Custom\Tools\Loyalty\Loyalty $loyalty,
        private \Espo\Custom\Tools\Loyalty\FreeWash $freeWash,
        private \Espo\Custom\Tools\Loyalty\LoyaltyConfig $loyaltyCfg,
        private \Espo\Custom\Tools\Loyalty\Promo $promo,
        private \Espo\Custom\Tools\Loyalty\Points $loyaltyPoints,
        private \Espo\Custom\Tools\Loyalty\Referral $referral,
        DeviceGuard $deviceGuard
    ) {
        // A tablet's API key is a device, not an operator: it gets the Kiosk endpoints only.
        $deviceGuard->denyDevice($user);
    }

    // ------------------------------------------------------------------ ACL
    //
    // This controller writes through the EntityManager, which does NOT apply roles. Every action
    // therefore asks the ACL itself: the rule is "the same right the entity API would demand for the
    // same effect". Administrators always pass (Acl does that); a tablet's key never gets here
    // (DeviceGuard). A new action added here MUST start with one of these calls (tests/acl-api.sh).

    /** @throws Forbidden */
    private function requireScope(string $scope, string $action = 'read'): void
    {
        if (!$this->acl->checkScope($scope, $action)) {
            throw new Forbidden("No '$action' access to $scope.");
        }
    }

    /** Reports that cover every record need level "all" — "own"/"team" would show other people's data. */
    private function requireAll(string $scope, string $action = 'read'): void
    {
        if ($this->user->isAdmin()) {
            return;
        }

        if ($this->acl->getLevel($scope, $action) !== 'all') {
            throw new Forbidden("Needs '$action: all' on $scope.");
        }
    }

    /**
     * Revenue over all orders (statistics, supply-usage report, accounting export, order totals): the
     * administrator, or a role with the "Revenue reports and statistics" switch AND read: all on orders.
     * The counter role reads every order to run the board but has no switch, so it sees no revenue overview.
     *
     * @throws Forbidden
     */
    private function requireRevenueReports(): void
    {
        $this->requireAll('WashOrder', 'read');

        if (!$this->user->isAdmin() && !$this->acl->checkScope('RevenueReports')) {
            throw new Forbidden("Needs the 'Revenue reports and statistics' right.");
        }
    }

    /** Wages, labour cost and margin: administrator only, whatever the role says. @throws Forbidden */
    private function requireAdminOnly(string $what): void
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden("Only an administrator may see $what.");
        }
    }

    /** @throws Forbidden */
    private function requireEntity(\Espo\ORM\Entity $entity, string $action): void
    {
        if (!$this->acl->checkEntity($entity, $action)) {
            throw new Forbidden("No '$action' access to this " . $entity->getEntityType() . '.');
        }
    }

    /** Landing pages the Site-content editor may read/write (whitelist — no path traversal). */
    private const SITE_PAGES = [
        'index.html',
        'ru/index.html',
        'en/index.html',
        'privatuma-politika.html',
        'ru/privatuma-politika.html',
        'en/privatuma-politika.html',
    ];

    private const LANDING_DIR = '/var/www/landing';

    /** Snapshots of every page live here — inside the app's data volume, so they persist and
     *  are NOT web-served (unlike anything under LANDING_DIR). */
    private const VERSIONS_DIR = '/var/www/html/data/site-versions';

    private const MAX_VERSIONS = 40;

    private function pageSlug(string $page): string
    {
        return str_replace('/', '_', $page);
    }

    private function versionLabel(string $v): string
    {
        // 20260804-153012 -> 2026-08-04 15:30:12
        if (preg_match('/^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})$/', $v, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
        }

        return $v;
    }

    /** Snapshot the content about to be overwritten; prune to the last MAX_VERSIONS. */
    private function snapshotVersion(string $page, string $content): ?string
    {
        $dir = self::VERSIONS_DIR . '/' . $this->pageSlug($page);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        $version = date('Ymd-His');
        @file_put_contents($dir . '/' . $version . '.html', $content);

        $files = glob($dir . '/*.html') ?: [];

        if (count($files) > self::MAX_VERSIONS) {
            sort($files); // names sort chronologically, so oldest first
            foreach (array_slice($files, 0, count($files) - self::MAX_VERSIONS) as $old) {
                @unlink($old);
            }
        }

        return $version;
    }

    /** Resolve a whitelisted page to an absolute path, or 400. Admins only. */
    private function sitePagePath(string $page): string
    {
        if (!$this->user->isAdmin()) {
            throw new Forbidden('Admin only.');
        }

        if (!in_array($page, self::SITE_PAGES, true)) {
            throw new BadRequest('Unknown page.');
        }

        return self::LANDING_DIR . '/' . $page;
    }

    /** GET the raw HTML of a landing page for the editor. */
    public function postActionGetSitePage(Request $request): stdClass
    {
        $data = $request->getParsedBody();
        $page = trim((string) ($data->page ?? ''));
        $path = $this->sitePagePath($page);

        return (object) [
            'page' => $page,
            'html' => is_file($path) ? (string) file_get_contents($path) : '',
            'exists' => is_file($path),
            'pages' => self::SITE_PAGES,
        ];
    }

    /** Overwrite a landing page with the edited HTML. */
    public function postActionSaveSitePage(Request $request): stdClass
    {
        $data = $request->getParsedBody();
        $page = trim((string) ($data->page ?? ''));
        $html = (string) ($data->html ?? '');
        $path = $this->sitePagePath($page);

        if (!is_dir(dirname($path))) {
            throw new Error('Landing directory is not mounted.');
        }

        // Snapshot the current content before overwriting, so a broken edit can be rolled back.
        $snapshot = null;

        if (is_file($path)) {
            $current = (string) file_get_contents($path);

            if ($current !== $html) {
                $snapshot = $this->snapshotVersion($page, $current);
            }
        }

        $written = @file_put_contents($path, $html);

        if ($written === false) {
            throw new Error('Could not write the page (check volume permissions).');
        }

        return (object) ['page' => $page, 'bytes' => $written, 'ok' => true, 'snapshot' => $snapshot];
    }

    /** List saved versions of a page, newest first. */
    public function postActionListSiteVersions(Request $request): stdClass
    {
        $data = $request->getParsedBody();
        $page = trim((string) ($data->page ?? ''));
        $this->sitePagePath($page); // admin + whitelist guard

        $dir = self::VERSIONS_DIR . '/' . $this->pageSlug($page);
        $list = [];

        foreach (glob($dir . '/*.html') ?: [] as $f) {
            $v = basename($f, '.html');
            $list[] = (object) [
                'version' => $v,
                'label' => $this->versionLabel($v),
                'bytes' => (int) filesize($f),
            ];
        }

        usort($list, fn ($a, $b) => strcmp($b->version, $a->version));

        return (object) ['page' => $page, 'versions' => $list];
    }

    /** Return the HTML of one saved version (for preview / restore into the editor). */
    public function postActionGetSiteVersion(Request $request): stdClass
    {
        $data = $request->getParsedBody();
        $page = trim((string) ($data->page ?? ''));
        $version = trim((string) ($data->version ?? ''));
        $this->sitePagePath($page); // admin + whitelist guard

        if (!preg_match('/^\d{8}-\d{6}$/', $version)) {
            throw new BadRequest('Bad version.');
        }

        $file = self::VERSIONS_DIR . '/' . $this->pageSlug($page) . '/' . $version . '.html';

        if (!is_file($file)) {
            throw new NotFound('Version not found.');
        }

        return (object) ['page' => $page, 'version' => $version, 'html' => (string) file_get_contents($file)];
    }

    /** Uppercase, strip dashes/spaces/other separators: "LV-1234" -> "LV1234". */
    private function normalizePlate(string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($plate)));
    }

    /**
     * Look up a plate: return the customer and their last order (to prefill), or
     * signal that this is a new vehicle.
     *
     * @throws BadRequest
     */
    public function postActionIntake(Request $request): stdClass
    {
        $this->requireScope('Vehicle', 'read');

        $data = $request->getParsedBody();

        $plate = $this->normalizePlate((string) ($data->plate ?? ''));

        if ($plate === '') {
            throw new BadRequest("Field 'plate' is required.");
        }

        $result = (object) [
            'plate' => $plate,
            'found' => false,
        ];

        $vehicle = $this->entityManager
            ->getRDBRepository('Vehicle')
            ->where(['name' => $plate])
            ->findOne();

        if (!$vehicle || !$this->acl->checkEntityRead($vehicle)) {
            return $result;
        }

        $result->found = true;
        $result->vehicleId = $vehicle->getId();
        $result->vehicleName = $vehicle->get('name');
        $result->plateTemporary = (bool) $vehicle->get('plateTemporary');
        $result->vehicleBrand = $vehicle->get('brand');
        $result->vehicleModel = $vehicle->get('model');

        $customerId = $vehicle->get('customerId');

        if ($customerId) {
            $customer = $this->entityManager->getEntityById('Customer', $customerId);

            // A role that cannot read this customer still learns the car exists, not whose it is.
            if ($customer && $this->acl->checkEntityRead($customer)) {
                $result->customerId = $customer->getId();
                $result->customerName = $customer->get('name');
                $result->isCorporate = (bool) $customer->get('isCorporate');
                $info = $this->buildCustomerInfo($customer);
                $result->customer = $info->customer;
                $result->orders = $info->orders;
                $result->stats = $info->stats;
                // "By subscription" hint for the intake form: what can pay a wash today, and what is left.
                $result->subscriptions = $this->acl->checkScope('Subscription', 'read')
                    ? $this->subscriptions->usableFor($customer->getId())
                    : [];
                // "Wash #10 is free" / "washes to the next free one" (module loyalty; only when the scheme is on)
                if ($this->loyaltyCfg->enabled() && $this->acl->checkScope('WashOrder', 'read')) {
                    $result->loyalty = (object) $this->freeWash->info($customer);
                }
            }
        }

        $lastOrder = $this->acl->checkScope('WashOrder', 'read') ? $this->entityManager
            ->getRDBRepository('WashOrder')
            ->where(['vehicleId' => $vehicle->getId()])
            ->order('orderDateTime', 'DESC')
            ->findOne() : null;

        if ($lastOrder && !$this->acl->checkEntityRead($lastOrder)) {
            $lastOrder = null;
        }

        if ($lastOrder) {
            // Preselect the driver actually last seen with this car. The most recent order
            // may have had none (walk-in), so fall back to the latest order that did — a
            // company's regular driver should still be offered by default.
            $driverId = $lastOrder->get('driverId');

            if (!$driverId) {
                $lastWithDriver = $this->entityManager
                    ->getRDBRepository('WashOrder')
                    ->where(['vehicleId' => $vehicle->getId(), 'driverId!=' => null])
                    ->order('orderDateTime', 'DESC')
                    ->findOne();

                if ($lastWithDriver) {
                    $driverId = $lastWithDriver->get('driverId');
                }
            }

            $result->lastOrder = (object) [
                'driverId' => $driverId,
                'serviceId' => $lastOrder->get('serviceId'),
                'serviceName' => $lastOrder->get('serviceName'),
                'finalPrice' => $lastOrder->get('finalPrice'),
                'paymentMethod' => $lastOrder->get('paymentMethod'),
            ];
        }

        return $result;
    }

    /**
     * Customer card + order history + stats. Used by the intake response and by the
     * Customer Lookup dashlet.
     *
     * @throws BadRequest
     * @throws NotFound
     */
    public function postActionCustomerInfo(Request $request): stdClass
    {
        $this->requireScope('Customer', 'read');

        $data = $request->getParsedBody();

        $customer = null;

        $plate = $this->normalizePlate((string) ($data->plate ?? ''));
        $customerId = (string) ($data->customerId ?? '');

        if ($customerId !== '') {
            $customer = $this->entityManager->getEntityById('Customer', $customerId);
        } elseif ($plate !== '') {
            $this->requireScope('Vehicle', 'read');
            $vehicle = $this->entityManager->getRDBRepository('Vehicle')->where(['name' => $plate])->findOne();

            if ($vehicle && $vehicle->get('customerId')) {
                $customer = $this->entityManager->getEntityById('Customer', $vehicle->get('customerId'));
            }
        } else {
            throw new BadRequest("Provide 'plate' or 'customerId'.");
        }

        if (!$customer || !$this->acl->checkEntityRead($customer)) {
            return (object) ['found' => false];
        }

        $info = $this->buildCustomerInfo($customer);
        $info->found = true;

        return $info;
    }

    private function buildCustomerInfo(\Espo\ORM\Entity $customer): stdClass
    {
        $orders = $this->acl->checkScope('WashOrder', 'read') ? $this->entityManager
            ->getRDBRepository('WashOrder')
            ->where(['customerId' => $customer->getId()])
            ->order('orderDateTime', 'DESC')
            ->limit(0, 40)
            ->find() : [];

        $list = [];
        $totalSpent = 0.0;
        $completed = 0;

        foreach ($orders as $o) {
            if (!$this->acl->checkEntityRead($o)) {
                continue; // read: own / team — only the orders this user may see
            }

            // Every service on the order, base wash first, then the add-ons — that is what
            // makes "when did this car last get wax?" answerable from the history table.
            $services = [];

            if ($o->get('serviceName')) {
                $services[] = $o->get('serviceName');
            }

            $addons = $this->entityManager
                ->getRDBRepository('WashOrder')
                ->getRelation($o, 'addons')
                ->find();

            foreach ($addons as $addon) {
                $services[] = $addon->get('name');
            }

            $list[] = (object) [
                'id' => $o->getId(),
                'name' => $o->get('name'),
                'orderDateTime' => $o->get('orderDateTime'),
                'status' => $o->get('status'),
                'serviceName' => $o->get('serviceName'),
                'services' => $services,
                'vehicleName' => $o->get('vehicleName'),
                'finalPrice' => $o->get('finalPrice'),
            ];

            if ($o->get('status') === 'Completed') {
                $completed++;
                $totalSpent += (float) $o->get('finalPrice');
            }
        }

        return (object) [
            'customer' => (object) [
                'id' => $customer->getId(),
                'name' => $customer->get('name'),
                'phone' => $customer->get('phone'),
                'email' => $customer->get('email'),
                'isCorporate' => (bool) $customer->get('isCorporate'),
                'hasContract' => (bool) $customer->get('hasContract'),
                'regNumber' => $customer->get('regNumber'),
                'pvnNumber' => $customer->get('pvnNumber'),
                // Notes are shown on the intake card — the operator must see them before
                // taking the car, not after opening the profile.
                'description' => $customer->get('description'),
            ],
            'orders' => $list,
            'subscriptions' => $this->acl->checkScope('Subscription', 'read')
                ? $this->subscriptions->allFor($customer->getId())
                : [],
            'stats' => (object) [
                'totalOrders' => count($list),
                'completedOrders' => $completed,
                'totalSpent' => round($totalSpent, 2),
            ],
        ];
    }

    /**
     * Create an order in one call. Creates the customer (from the order data) and
     * the vehicle if they don't exist yet — minimal steps for the operator.
     *
     * @throws BadRequest
     */
    public function postActionCreateOrder(Request $request): stdClass
    {
        $this->requireScope('WashOrder', 'create');

        $data = $request->getParsedBody();

        // No plate = a car without registration: the server mints a temporary number (docs/DATA_RULES.md).
        $plate = $this->normalizePlate((string) ($data->plate ?? ''));

        $em = $this->entityManager;

        // Money in the body is validated BEFORE anything is created (no half-made customer / vehicle on a 400).
        $this->validateOrderMoney($data);

        $vehicle = $plate !== '' ? $em->getRDBRepository('Vehicle')->where(['name' => $plate])->findOne() : null;

        // Resolve or create the customer.
        $customerId = ($data->customerId ?? null) ?: ($vehicle ? $vehicle->get('customerId') : null);
        $customer = $customerId ? $em->getEntityById('Customer', $customerId) : null;

        if ($vehicle) {
            $this->requireEntity($vehicle, 'read');
        }

        if ($customer) {
            $this->requireEntity($customer, 'read');
        } else {
            // The payer is mandatory: an existing customer or at least a name (400 before anything is created).
            \Espo\Custom\Tools\Data\Parties::requireName((string) ($data->customerName ?? ''));
            $this->requireScope('Customer', 'create');
        }

        // A promo code typed at the counter is checked BEFORE anything is created (a wrong code is a 400 `promo_<reason>`, not a silent
        // full price): module on, code known and usable for this customer and amount. The order is re-checked when it is built.
        $promoCode = \Espo\Custom\Tools\Loyalty\Promo::normalize((string) ($data->promoCode ?? ''));
        $skipLoyalty = !empty($data->skipLoyalty);

        if ($promoCode !== '' && !$skipLoyalty) {
            if (!$this->loyaltyCfg->enabled()) {
                throw new Forbidden('module_disabled');
            }

            $why = $this->promo->problem(
                $this->promo->find($promoCode),
                $customer ? $customer->getId() : null,
                $this->listAmountOf($data, $customer, (bool) ($customer ? $customer->get('isCorporate') : ($data->isCorporate ?? false)))
            );

            if ($why !== null) {
                throw new BadRequest('promo_' . $why);
            }
        }

        // Points to use and a referral code are checked the same way: a wrong request is a 400 (`points_<reason>` / `referral_<reason>`) before
        // anything is created. The order is re-checked when it is built (a race for the same points leaves a note on the order, never a negative balance).
        $redeemPoints = 0;

        if (isset($data->redeemPoints) && $data->redeemPoints !== '' && $data->redeemPoints !== null) {
            \Espo\Custom\Tools\Validation\Numbers::assertNumber('redeemPoints', $data->redeemPoints, 0.0, 100000000.0);
            $redeemPoints = (int) $data->redeemPoints;
        }

        if ($redeemPoints > 0) {
            if (!$this->loyaltyCfg->enabled()) {
                throw new Forbidden('module_disabled');
            }

            $corp = (bool) ($customer ? $customer->get('isCorporate') : ($data->isCorporate ?? false));
            $list = $this->listAmountOf($data, $customer, $corp);
            $base = $this->listAmountOf((object) ['serviceId' => $data->serviceId ?? null], $customer, $corp);
            $pv = $this->loyalty->preview($promoCode, $customer ? $customer->getId() : null, $list, $base, $skipLoyalty, $redeemPoints);

            if ($pv['points']['reason'] !== null) {
                throw new BadRequest($pv['points']['reason']);
            }
        }

        $referralCode = \Espo\Custom\Tools\Loyalty\Referral::normalize((string) ($data->referralCode ?? ''));

        if ($referralCode !== '') {
            if (!$this->loyaltyCfg->enabled()) {
                throw new Forbidden('module_disabled');
            }

            $why = $this->referral->problem($this->referral->findByCode($referralCode), $customer, [
                'phone' => $data->phone ?? null,
                'email' => $data->email ?? null,
                'plates' => $plate !== '' ? [$plate] : [],
                'vehicleCustomerId' => $vehicle ? $vehicle->get('customerId') : null,
            ]);

            if ($why !== null) {
                throw new BadRequest('referral_' . $why);
            }
        }

        if (!$customer) {
            $customer = $em->createEntity('Customer', [
                'name' => trim((string) $data->customerName),
                'isCorporate' => (bool) ($data->isCorporate ?? false),
                'hasContract' => (bool) ($data->hasContract ?? false),
                'phone' => $data->phone ?? null,
                'email' => $data->email ?? null,
            ]);
        }

        // Resolve or create the vehicle. Make/model come from the intake form's
        // autocomplete (client/custom/lib/car-models.json) but are plain text — any
        // value the operator typed is accepted as-is.
        if (!$vehicle) {
            $this->requireScope('Vehicle', 'create');
            $carData = [
                'brand' => trim((string) ($data->brand ?? '')) ?: null,
                'model' => trim((string) ($data->model ?? '')) ?: null,
            ];
            $vehicle = $plate === ''
                ? $this->parties()->createTemporaryVehicle($customer->getId(), $carData)
                : $em->createEntity('Vehicle', array_merge($carData, ['name' => $plate, 'customerId' => $customer->getId()]));
        } elseif (!$vehicle->get('customerId')) {
            $vehicle->set('customerId', $customer->getId());
            $em->saveEntity($vehicle);
        }

        // A driver typed straight into the intake form: register them against this
        // customer, so next time they are simply picked from the list.
        $driverId = $data->driverId ?? null;
        $driverName = trim((string) ($data->driverName ?? ''));

        if (!$driverId && $driverName !== '') {
            $this->requireScope('Driver', 'create');
            $driver = $em->createEntity('Driver', [
                'name' => $driverName,
                'phone' => $data->driverPhone ?? null,
                'customerId' => $customer->getId(),
            ]);

            $driverId = $driver->getId();
        }

        // Role → employee for this order. The works generated from the services are
        // handed to the employee assigned to each work's role (Hooks/WashOrder/GenerateWorks).
        $roleMap = [];

        foreach (($data->roleAssignments ?? []) as $assignment) {
            $roleId = $assignment->roleId ?? null;
            $employeeId = $assignment->employeeId ?? null;

            if ($roleId && $employeeId) {
                $roleMap[$roleId] = $employeeId;
            }
        }

        // Per-work overrides: hand one specific work to someone other than its role's performer.
        $workMap = [];

        foreach (($data->workAssignments ?? []) as $assignment) {
            $serviceId = $assignment->serviceId ?? null;
            $workId = $assignment->workId ?? null;
            $employeeId = $assignment->employeeId ?? null;

            if ($serviceId && $workId && $employeeId) {
                $workMap[$serviceId . ':' . $workId] = $employeeId;
            }
        }

        $generateOptions = array_filter([
            'roleMap' => $roleMap,
            'workMap' => $workMap,
            // the lines carry their charged prices only at the end of this method: the loyalty discount is applied there
            'skipApplyLoyalty' => true,
        ]);

        // Add-ons on this order. The `addons` link still drives the bill-of-materials,
        // pricing and history. The per-line PRICE the operator actually charged is kept
        // separately as OrderAddon rows (created below) so the receipt prints each add-on
        // with its amount, not just the catalogue value.
        $addonIds = $data->addonIds ?? [];
        $addonPrices = (isset($data->addonPrices) && is_object($data->addonPrices)) ? $data->addonPrices : null;

        $orderData = [
            'name' => trim((string) ($data->number ?? '')) ?: ('ORD-' . date('ymd-His')),
            'status' => $data->status ?? 'In Progress',
            'orderDateTime' => date('Y-m-d H:i:s'),
            'customerId' => $customer->getId(),
            'vehicleId' => $vehicle->getId(),
            'driverId' => $driverId,
            'serviceId' => $data->serviceId ?? null,
            'addonsIds' => $addonIds,
            'washerId' => $data->washerId ?? null,
            'dryerId' => $data->dryerId ?? null,
            'salonCleanerId' => $data->salonId ?? null,
            'hasContract' => (bool) $customer->get('hasContract'),
            'isCorporate' => (bool) $customer->get('isCorporate'),
        ];

        // Only when the intake form actually chose one — otherwise SetDefaults decides
        // (a contract customer defaults to Invoice, everyone else to Cash). The option
        // flag tells SetDefaults this is an explicit choice, not the enum default.
        if (!empty($data->paymentMethod)) {
            $orderData['paymentMethod'] = $data->paymentMethod;
            $generateOptions['explicitPaymentMethod'] = true;
        }

        if ($promoCode !== '') {
            $orderData['promoCode'] = $promoCode;
        }

        if ($skipLoyalty) {
            $orderData['skipLoyalty'] = true;
        }

        if ($redeemPoints > 0) {
            $orderData['redeemPoints'] = $redeemPoints;
        }

        if ($referralCode !== '') {
            $orderData['referralCode'] = $referralCode;
        }

        // Pay this wash from one of the customer's subscriptions (chosen by the operator in the intake hint).
        // ValidateSubscription refuses it (409 subscription_<reason>) when it cannot pay; the wash is spent on Completed.
        if (!empty($data->subscriptionId)) {
            $orderData['subscriptionId'] = (string) $data->subscriptionId;
        }

        // Whether printed documents break out PVN. Sent by the intake form (the checkbox
        // shows up for the Invoice method); absent = the field's default (on).
        if (isset($data->pvnEnabled)) {
            $orderData['pvnEnabled'] = (bool) $data->pvnEnabled;
        }

        $order = $em->createEntity('WashOrder', $orderData, $generateOptions);

        // Priced add-on lines for the printed receipt. Price falls back to the
        // catalogue retail price when the caller did not send an explicit amount.
        // The base wash is line 0 (created by SyncOrderAddons); add-ons start at 10 so the
        // printed document always opens with the base wash.
        $sort = 10;
        $chargedAddons = 0.0;
        $isCorporate = (bool) $customer->get('isCorporate');

        foreach ($addonIds as $addonId) {
            $service = $em->getEntityById('WashService', $addonId);

            $explicit = $addonPrices && isset($addonPrices->$addonId);

            // The SyncOrderAddons hook has already created a catalogue-priced line
            // (corporate-aware) for every linked add-on. Overwrite it ONLY with an
            // explicitly charged price — falling back to retail here once clobbered the
            // corporate price the hook had set.
            $existing = $em->getRDBRepository('OrderAddon')
                ->where(['washOrderId' => $order->getId(), 'serviceId' => $addonId, 'deleted' => false])
                ->findOne();

            if ($existing) {
                $price = $explicit ? (float) $addonPrices->$addonId : (float) $existing->get('price');
                $chargedAddons += $price;

                $existing->set(['price' => $price, 'sortOrder' => $sort++]);
                $em->saveEntity($existing);

                continue;
            }

            $price = $explicit
                ? (float) $addonPrices->$addonId
                : ($service
                    ? (float) ($isCorporate
                        ? ($service->get('priceCorporate') ?? $service->get('priceRetail'))
                        : $service->get('priceRetail'))
                    : 0.0);

            $chargedAddons += $price;

            $em->createEntity('OrderAddon', [
                'name' => $service ? $service->get('name') : null,
                'price' => $price,
                'washOrderId' => $order->getId(),
                'serviceId' => $addonId,
                'sortOrder' => $sort++,
            ]);
        }

        // ONE truth about the price: the intake form's total is what the customer pays,
        // and the lines must sum to it. The add-on lines already carry their charged
        // prices, so whatever remains of the total — including a hand-edited discount or
        // surcharge — lands on the base line. The OrderAddon hook then re-derives
        // finalPrice (and the printable HTML) from the lines, so the list, the order page
        // and the receipt can never disagree.
        $explicitTotal = isset($data->finalPrice) && $data->finalPrice !== '';

        if ($explicitTotal) {
            $lines = $em->getRDBRepository('OrderAddon')
                ->where(['washOrderId' => $order->getId(), 'deleted' => false])
                ->order('sortOrder')
                ->find();

            $byId = [];
            $prices = [];
            $baseKey = null;

            foreach ($lines as $line) {
                $byId[$line->getId()] = $line;
                $prices[$line->getId()] = (float) $line->get('price');

                if ($line->get('isBase')) {
                    $baseKey = $line->getId();
                }
            }

            // Above the lines: the difference goes on the base line. Below them (a discount, down to 0):
            // every line is scaled in proportion, exact to the cent (Distributor).
            foreach (\Espo\Custom\Tools\OrderPrice\Distributor::fit($prices, $baseKey, (float) $data->finalPrice) as $id => $price) {
                if (abs($price - $prices[$id]) > 0.005) {
                    $byId[$id]->set('price', $price);
                    $em->saveEntity($byId[$id]);
                }
            }
        }

        // Loyalty (promo code / every N-th wash free): now that the lines hold what was charged, the discount is taken off them
        // through the same Distributor path as a typed total.
        $loyalty = null;

        if ($this->loyaltyCfg->enabled()) {
            $order = $em->getEntityById('WashOrder', $order->getId()) ?? $order;
            $this->loyalty->applyToNew($order);
            $order = $em->getEntityById('WashOrder', $order->getId()) ?? $order;

            $loyalty = (object) [
                'kind' => (string) $order->get('loyaltyKind'),
                'discount' => round((float) $order->get('loyaltyDiscount'), 2),
                'note' => (string) $order->get('loyaltyNote'),
                'finalPrice' => round((float) $order->get('finalPrice'), 2),
                'pointsRedeemed' => (int) $order->get('pointsRedeemed'),
                'pointsDiscount' => round((float) $order->get('pointsDiscount'), 2),
                'pointsNote' => (string) $order->get('pointsNote'),
                'referralNote' => (string) $order->get('referralNote'),
            ];
        }

        return (object) [
            'loyalty' => $loyalty,
            'orderId' => $order->getId(),
            'orderName' => $order->get('name'),
            'customerId' => $customer->getId(),
            'customerName' => $customer->get('name'),
            'vehicleId' => $vehicle->getId(),
            'vehiclePlate' => (string) $vehicle->get('name'),
            'plateTemporary' => (bool) $vehicle->get('plateTemporary'),
            'isCorporate' => (bool) $customer->get('isCorporate'),
            'hasContract' => (bool) $customer->get('hasContract'),
        ];
    }

    /**
     * The order lines' total BEFORE any loyalty discount, as createOrder will build it: the typed total if there is one, else the
     * base wash plus the ticked add-ons at their charged (or catalogue) prices. Only used to check a promo code's minimum up front.
     */
    private function listAmountOf(stdClass $data, ?\Espo\ORM\Entity $customer, bool $corporate): float
    {
        if (isset($data->finalPrice) && $data->finalPrice !== '' && is_numeric($data->finalPrice)) {
            return (float) $data->finalPrice;
        }

        $price = function (?string $id) use ($corporate): float {
            $svc = $id ? $this->entityManager->getEntityById('WashService', $id) : null;

            return $svc ? (float) ($corporate ? ($svc->get('priceCorporate') ?? $svc->get('priceRetail')) : $svc->get('priceRetail')) : 0.0;
        };

        $sum = $price($data->serviceId ?? null);
        $prices = (isset($data->addonPrices) && is_object($data->addonPrices)) ? $data->addonPrices : null;

        foreach (($data->addonIds ?? []) as $addonId) {
            $sum += ($prices && isset($prices->$addonId)) ? (float) $prices->$addonId : $price((string) $addonId);
        }

        return round($sum, 2);
    }

    private function parties(): \Espo\Custom\Tools\Data\Parties
    {
        return new \Espo\Custom\Tools\Data\Parties($this->entityManager);
    }

    /**
     * "Set the real plate" on a car that carries a temporary number (docs/DATA_RULES.md). The car is RENAMED, so every order,
     * photo and event keeps pointing at it; the flag is cleared. If the real plate already belongs to another car: 409
     * `plate_exists` with that car's id - repeat with `merge:true` to move this car's orders to it and drop this one.
     * Needs edit on that Vehicle (merge: also edit on the other one and on its orders).
     *
     * @throws BadRequest
     */
    public function postActionSetRealPlate(Request $request): stdClass
    {
        $data = $request->getParsedBody();
        $vehicle = $this->entityManager->getEntityById('Vehicle', trim((string) ($data->vehicleId ?? '')));

        if (!$vehicle) {
            throw new NotFound('Vehicle not found.');
        }

        $this->requireEntity($vehicle, 'edit');

        return (new \Espo\Custom\Tools\Data\PlateRename($this->entityManager, $this->acl))
            ->run($vehicle, (string) ($data->plate ?? ''), !empty($data->merge));
    }

    /**
     * `addonPrices` values and `finalPrice` must be finite numbers >= 0 (400 naming the field). A total below the
     * add-ons is a discount (down to 0) and is spread over the lines by Distributor.
     *
     * @throws BadRequest
     */
    private function validateOrderMoney(stdClass $data): void
    {
        $prices = (isset($data->addonPrices) && is_object($data->addonPrices)) ? (array) $data->addonPrices : [];

        foreach ($prices as $addonId => $price) {
            \Espo\Custom\Tools\Validation\Numbers::assertNumber("addonPrices[$addonId]", $price);
        }

        if (isset($data->finalPrice) && $data->finalPrice !== '') {
            \Espo\Custom\Tools\Validation\Numbers::assertNumber('finalPrice', $data->finalPrice);
        }
    }

    /**
     * Create a reservation (a WashOrder in the "Reserved" status). The single entry
     * point for inbound channels — email, website form, WhatsApp, Telegram. Matches
     * a known customer by plate or phone; otherwise records the contact so staff can
     * confirm. Returns the created reservation.
     *
     * @throws BadRequest
     */
    public function postActionReserve(Request $request): stdClass
    {
        $this->requireScope('WashOrder', 'create');

        $data = $request->getParsedBody();

        $em = $this->entityManager;

        $plate = $this->normalizePlate((string) ($data->plate ?? ''));
        $phone = trim((string) ($data->phone ?? ''));
        $source = (string) ($data->source ?? '');

        // A booking without data is rubbish: who (customer with a name), which car (no plate = a temporary number is minted), and when.
        if (trim((string) ($data->orderDateTime ?? '')) === '') {
            throw new BadRequest("reserve_missing_time: provide the arrival time 'orderDateTime' (UTC, Y-m-d H:i:s).");
        }

        // Match a known vehicle/customer.
        $vehicle = $plate !== ''
            ? $em->getRDBRepository('Vehicle')->where(['name' => $plate])->findOne()
            : null;

        $customer = null;

        if ($vehicle && $vehicle->get('customerId')) {
            $customer = $em->getEntityById('Customer', $vehicle->get('customerId'));
        }

        if (!$customer && trim((string) ($data->customerId ?? '')) !== '') {
            $customer = $em->getEntityById('Customer', trim((string) $data->customerId));
        }

        if (!$customer && $phone !== '') {
            $customer = $em->getRDBRepository('Customer')->where(['phone' => $phone])->findOne();
        }

        if (!$customer && trim((string) ($data->customerName ?? '')) === '') {
            throw new BadRequest($phone === ''
                ? "customer_name_required: provide 'customerId' or 'customerName' (or a plate of a known car). reserve_missing_customer"
                : "customer_name_required: a phone alone does not identify the payer - provide 'customerName'.");
        }

        if ($vehicle) {
            $this->requireEntity($vehicle, 'read');
        }

        if ($customer) {
            $this->requireEntity($customer, 'read');
        } else {
            $this->requireScope('Customer', 'create');
        }

        if (!$customer) {
            $customer = $em->createEntity('Customer', [
                'name' => trim((string) $data->customerName),
                'phone' => $phone ?: null,
            ]);
        }

        if (!$vehicle) {
            $this->requireScope('Vehicle', 'create');
            $vehicle = $plate === ''
                ? $this->parties()->createTemporaryVehicle($customer->getId())
                : $em->createEntity('Vehicle', ['name' => $plate, 'customerId' => $customer->getId()]);
        }

        $order = $em->createEntity('WashOrder', [
            'name' => trim((string) ($data->number ?? '')) ?: ('RES-' . date('ymd-His')),
            'status' => 'Reserved',
            'source' => $source,
            'orderDateTime' => trim((string) $data->orderDateTime),
            'customerId' => $customer->getId(),
            'vehicleId' => $vehicle->getId(),
            'serviceId' => $data->serviceId ?? null,
            'finalPrice' => $data->finalPrice ?? 0,
            'reservationNote' => $data->note ?? null,
            'isCorporate' => (bool) $customer->get('isCorporate'),
        ]);

        return (object) [
            'reservationId' => $order->getId(),
            'reservationName' => $order->get('name'),
            'status' => $order->get('status'),
            'customerId' => $customer->getId(),
            'customerName' => $customer->get('name'),
            'matched' => $plate !== '',
            'vehicleId' => $vehicle->getId(),
            'vehiclePlate' => (string) $vehicle->get('name'),
            'plateTemporary' => (bool) $vehicle->get('plateTemporary'),
        ];
    }

    /**
     * Generate the invoice (rēķins) PDF for an order and email it to the customer.
     * Requires outbound SMTP to be configured (Administration → Outbound Emails) and
     * an active WashOrder template named "Rēķins".
     *
     * @throws BadRequest
     * @throws NotFound
     * @throws Error
     */
    public function postActionEmailInvoice(Request $request): stdClass
    {
        $this->requireScope('WashOrder', 'edit');

        $data = $request->getParsedBody();

        $orderId = trim((string) ($data->orderId ?? ''));

        if ($orderId === '') {
            throw new BadRequest("Field 'orderId' is required.");
        }

        $em = $this->entityManager;

        $order = $em->getEntityById('WashOrder', $orderId);

        if (!$order) {
            throw new NotFound("Order not found.");
        }

        $this->requireEntity($order, 'edit');

        $customer = $order->get('customerId')
            ? $em->getEntityById('Customer', $order->get('customerId'))
            : null;

        $to = trim((string) ($data->email ?? ($customer ? $customer->get('email') : '')));

        if ($to === '') {
            throw new BadRequest("No recipient email (order's customer has no email).");
        }

        $template = $em->getRDBRepository('Template')
            ->where(['name' => 'Rēķins', 'entityType' => 'WashOrder'])
            ->findOne();

        if (!$template) {
            throw new Error("Invoice template 'Rēķins' not found.");
        }

        if (!$this->emailSender->hasSystemSmtp()) {
            throw new Error("Outbound SMTP is not configured (Administration → Outbound Emails).");
        }

        $sender = $this->emailSender->create();

        $pdf = $this->pdfService->generate('WashOrder', $orderId, $template->getId());

        $attachment = $em->createEntity('Attachment', [
            'name' => 'Rekins-' . $order->get('name') . '.pdf',
            'type' => 'application/pdf',
            'role' => 'Attachment',
            'contents' => $pdf->getString(),
        ]);

        $email = $em->createEntity('Email', [
            'to' => $to,
            'subject' => 'Rēķins ' . $order->get('name'),
            'body' => "Labdien!\n\nPielikumā rēķins par pakalpojumu.\n\nAr cieņu" .
                (($sig = (new \Espo\Custom\Tools\Brand\Brand($this->config))->emailSignature()) !== '' ? ",\n" . $sig : ''),
            'isHtml' => false,
        ]);

        $sender->withAttachments([$attachment])->send($email);

        // The invoice is now out: record that the order is billed. It becomes Paid only
        // when the money actually arrives — sending a bill is not a payment.
        if ($order->get('paymentStatus') !== 'Paid') {
            $order->set([
                'paymentMethod' => 'Invoice',
                'paymentStatus' => 'Invoiced',
                'invoiceSentAt' => date('Y-m-d H:i:s'),
            ]);

            $em->saveEntity($order);
        }

        return (object) [
            'sent' => true,
            'to' => $to,
            'orderName' => $order->get('name'),
            'paymentStatus' => $order->get('paymentStatus'),
        ];
    }

    /**
     * Payroll summary: total bonuses per employee from work assignments on
     * completed orders within a period (defaults to the current month).
     */
    public function postActionPayroll(Request $request): stdClass
    {
        // Wages are the administrator's alone (docs/ROLES.md): no role, however wide, opens them.
        $this->requireAdminOnly('payroll');

        $data = $request->getParsedBody();

        $from = trim((string) ($data->dateFrom ?? '')) ?: date('Y-m-01 00:00:00');
        $to = trim((string) ($data->dateTo ?? '')) ?: date('Y-m-t 23:59:59');
        [$fromU, $toU] = $this->utcBounds($from, $to);

        $orders = $this->entityManager
            ->getRDBRepository('WashOrder')
            ->select(['id'])
            ->where([
                'status' => 'Completed',
                'orderDateTime>=' => $fromU,
                'orderDateTime<=' => $toU,
                'deleted' => false,
            ])
            ->find();

        $orderIds = [];

        foreach ($orders as $order) {
            $orderIds[] = $order->getId();
        }

        $map = [];
        $roleNames = [];

        if ($orderIds) {
            $lines = $this->entityManager
                ->getRDBRepository('OrderWork')
                ->where([
                    'washOrderId' => $orderIds,
                    'employeeId!=' => null,
                ])
                ->find();

            foreach ($lines as $line) {
                $employeeId = $line->get('employeeId');

                if (!$employeeId) {
                    continue;
                }

                if (!isset($map[$employeeId])) {
                    $map[$employeeId] = [
                        'employeeId' => $employeeId,
                        'employeeName' => $this->nameOf('Employee', $employeeId),
                        'works' => 0,
                        'earned' => 0.0,
                        'byRole' => [],
                    ];
                }

                $map[$employeeId]['works']++;
                $map[$employeeId]['earned'] += (float) $line->get('amount');

                $roleId = $line->get('roleId');

                if ($roleId) {
                    $roleName = $this->nameOf('WorkRole', $roleId);
                    $roleNames[$roleId] = $roleName;
                    $map[$employeeId]['byRole'][$roleId] = ($map[$employeeId]['byRole'][$roleId] ?? 0) + 1;
                }
            }
        }

        $rows = [];

        foreach ($map as $row) {
            $row['earned'] = round($row['earned'], 2);
            $row['byRole'] = (object) $row['byRole'];
            $rows[] = $row;
        }

        usort($rows, fn($a, $b) => $b['earned'] <=> $a['earned']);

        $roles = [];

        foreach ($roleNames as $id => $name) {
            $roles[] = (object) ['id' => $id, 'name' => $name];
        }

        return (object) [
            'dateFrom' => $from,
            'dateTo' => $to,
            'roles' => $roles,
            'rows' => $rows,
            'totalWorks' => array_sum(array_column($rows, 'works')),
            'totalEarned' => round(array_sum(array_column($rows, 'earned')), 2),
        ];
    }

    /**
     * Clock an employee in or out in one call — the Shift dashlet's two buttons.
     * Toggles: an open shift is closed, otherwise a new one is opened.
     *
     * POST /api/v1/CarWash/action/clock { employeeId }
     *
     * @throws BadRequest
     * @throws NotFound
     */
    public function postActionClock(Request $request): stdClass
    {
        // Create Shift + READ the employee is enough: clocking never edits the Employee record (the rate stays
        // out of reach; the ClockService mirrors onShift itself). The tablet is the worker's own way in.
        $this->requireScope('Shift', 'create');
        $this->requireScope('Employee', 'read');

        $data = $request->getParsedBody();
        $employeeId = (string) ($data->employeeId ?? '');

        if ($employeeId === '') {
            throw new BadRequest("Field 'employeeId' is required.");
        }

        // The shared implementation (also behind Kiosk/action/clock). The response keeps
        // the shape the Shifts dashlet reads.
        $employee = $this->entityManager->getEntityById('Employee', $employeeId);

        if ($employee) {
            $this->requireEntity($employee, 'read');
        }

        $result = $this->clockService->toggle($employeeId);

        unset($result['changed'], $result['finishedAt']);

        return (object) $result;
    }

    /** Owner's name for a vehicle. A raw repository find does not populate foreign fields. */
    private function customerNameOf(\Espo\ORM\Entity $vehicle): string
    {
        $id = $vehicle->get('customerId');

        if (!$id) {
            return '';
        }

        $customer = $this->entityManager->getEntityById('Customer', $id);

        return $customer ? (string) $customer->get('name') : '';
    }

    /**
     * Type-ahead for the intake field: plates, then customers, then drivers — in that
     * order, because that is how the operator identifies a car at the gate. Every hit
     * resolves to a plate (a customer/driver hit expands to their vehicles), since the
     * intake lookup itself works by plate.
     *
     * Returns at most 10 suggestions: { plate, label, sub, type }.
     */
    public function postActionSearch(Request $request): stdClass
    {
        // Scopes are checked per branch below; a role that reads none of them gets 403.
        if (!$this->acl->checkScope('Vehicle', 'read') && !$this->acl->checkScope('Customer', 'read') && !$this->acl->checkScope('Driver', 'read')) {
            throw new Forbidden('No access to vehicles, customers or drivers.');
        }

        $data = $request->getParsedBody();

        $query = trim((string) ($data->query ?? ''));

        if (mb_strlen($query) < 2) {
            return (object) ['list' => []];
        }

        $em = $this->entityManager;
        $limit = 10;

        // Every suggestion is a plate, so the vehicle must be readable; customer / driver names are
        // searched (and shown) only where that scope is readable too. Per-record ACL (own / team)
        // is applied to each hit after the fetch.
        $canVehicle = $this->acl->checkScope('Vehicle', 'read');
        $canCustomer = $this->acl->checkScope('Customer', 'read');
        $canDriver = $this->acl->checkScope('Driver', 'read') && $this->acl->checkScope('WashOrder', 'read');
        $subOf = function (\Espo\ORM\Entity $vehicle) use ($canCustomer): string {
            $id = $vehicle->get('customerId');
            $c = $canCustomer && $id ? $this->entityManager->getEntityById('Customer', $id) : null;

            return $c && $this->acl->checkEntityRead($c) ? (string) $c->get('name') : '';
        };

        $list = [];
        $seen = [];

        $add = function (string $plate, string $label, string $sub, string $type) use (&$list, &$seen, $limit) {
            if ($plate === '' || isset($seen[$plate]) || count($list) >= $limit) {
                return;
            }

            $seen[$plate] = true;

            $list[] = (object) ['plate' => $plate, 'label' => $label, 'sub' => $sub, 'type' => $type];
        };

        // 1. By plate. The stored plate has no dashes/spaces, so normalise the query too.
        $plateQuery = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $query));

        if ($canVehicle && $plateQuery !== '') {
            $vehicles = $em->getRDBRepository('Vehicle')
                ->where(['name*' => $plateQuery . '%', 'deleted' => false])
                ->order('name')
                ->limit(0, $limit)
                ->find();

            foreach ($vehicles as $vehicle) {
                if (!$this->acl->checkEntityRead($vehicle)) {
                    continue;
                }

                $add(
                    (string) $vehicle->get('name'),
                    (string) $vehicle->get('name'),
                    $subOf($vehicle),
                    'plate'
                );
            }
        }

        // 2. By customer name → their vehicles.
        if ($canVehicle && $canCustomer && count($list) < $limit) {
            $customers = $em->getRDBRepository('Customer')
                ->where(['name*' => '%' . $query . '%', 'deleted' => false])
                ->order('name')
                ->limit(0, $limit)
                ->find();

            foreach ($customers as $customer) {
                if (!$this->acl->checkEntityRead($customer)) {
                    continue;
                }

                $vehicles = $em->getRDBRepository('Vehicle')
                    ->where(['customerId' => $customer->getId(), 'deleted' => false])
                    ->order('name')
                    ->limit(0, $limit)
                    ->find();

                foreach ($vehicles as $vehicle) {
                    if (!$this->acl->checkEntityRead($vehicle)) {
                        continue;
                    }

                    $add(
                        (string) $vehicle->get('name'),
                        (string) $vehicle->get('name'),
                        (string) $customer->get('name'),
                        'customer'
                    );
                }
            }
        }

        // 3. By driver name → the cars that driver actually brought.
        if ($canVehicle && $canDriver && count($list) < $limit) {
            $drivers = $em->getRDBRepository('Driver')
                ->where(['name*' => '%' . $query . '%', 'deleted' => false])
                ->order('name')
                ->limit(0, $limit)
                ->find();

            foreach ($drivers as $driver) {
                if (!$this->acl->checkEntityRead($driver)) {
                    continue;
                }

                $orders = $em->getRDBRepository('WashOrder')
                    ->where(['driverId' => $driver->getId(), 'deleted' => false])
                    ->order('orderDateTime', 'DESC')
                    ->limit(0, $limit)
                    ->find();

                foreach ($orders as $order) {
                    $vehicleId = $order->get('vehicleId');

                    if (!$vehicleId || !$this->acl->checkEntityRead($order)) {
                        continue;
                    }

                    $vehicle = $em->getEntityById('Vehicle', $vehicleId);

                    if (!$vehicle || !$this->acl->checkEntityRead($vehicle)) {
                        continue;
                    }

                    $add(
                        (string) $vehicle->get('name'),
                        (string) $vehicle->get('name'),
                        $driver->get('name') . ' · ' . $subOf($vehicle),
                        'driver'
                    );
                }
            }
        }

        return (object) ['list' => $list];
    }

    /**
     * Totals for a list screen under the filter the user is looking at: how many records
     * and the sum of the money column. The client hands over the same `where` the list
     * itself was fetched with, so the numbers always describe what is on screen.
     *
     * @throws BadRequest
     */
    public function postActionListTotals(Request $request): stdClass
    {
        // (scope check happens once the entity type is known, below)

        $data = $request->getParsedBody();

        $entityType = (string) ($data->entityType ?? '');

        // Only entities that actually carry a money column are summable.
        $sumFieldMap = [
            'WashOrder' => 'finalPrice',
            'OrderWork' => 'amount',
            'Supply' => 'stockQuantity',
        ];

        if (!isset($sumFieldMap[$entityType])) {
            throw new BadRequest("Totals are not available for '$entityType'.");
        }

        $sumField = $sumFieldMap[$entityType];

        if ($entityType === 'OrderWork') {
            $this->requireAdminOnly('wage totals');
        }

        if ($entityType === 'WashOrder') {
            $this->requireRevenueReports();
        }

        // Same right as opening that list; withStrictAccessControl() below then narrows it to own / team.
        $this->requireScope($entityType, 'read');

        $where = json_decode(json_encode($data->where ?? []), true) ?: [];

        $searchParams = SearchParams::fromRaw(['where' => $where]);

        $query = $this->selectBuilderFactory
            ->create()
            ->from($entityType)
            ->withSearchParams($searchParams)
            ->withStrictAccessControl()
            ->build();

        $collection = $this->entityManager
            ->getRDBRepository($entityType)
            ->clone($query)
            ->find();

        $count = 0;
        $sum = 0.0;

        foreach ($collection as $entity) {
            $count++;
            $sum += (float) $entity->get($sumField);
        }

        return (object) [
            'entityType' => $entityType,
            'sumField' => $sumField,
            'count' => $count,
            'sum' => round($sum, 2),
            'average' => $count ? round($sum / $count, 2) : 0,
        ];
    }

    /**
     * Three numbers for the customer card: how many times they came (completed orders), what
     * they spent, when they were last here — plus what they still owe (completed, not paid).
     *
     * @throws BadRequest
     * @throws NotFound
     */
    public function postActionCustomerSummary(Request $request): stdClass
    {
        $this->requireScope('Customer', 'read');
        $this->requireScope('WashOrder', 'read');

        $data = $request->getParsedBody();
        $id = trim((string) ($data->customerId ?? ''));

        if ($id === '') {
            throw new BadRequest("Field 'customerId' is required.");
        }

        $customer = $this->entityManager->getEntityById('Customer', $id);

        if (!$customer) {
            throw new NotFound();
        }

        $this->requireEntity($customer, 'read');

        $visits = 0;
        $spent = 0.0;
        $debt = 0.0;
        $last = null;

        $orders = $this->entityManager->getRDBRepository('WashOrder')
            ->where(['customerId' => $id, 'status' => 'Completed', 'deleted' => false])
            ->find();

        foreach ($orders as $o) {
            if (!$this->acl->checkEntityRead($o)) {
                continue;
            }

            $visits++;
            $price = (float) $o->get('finalPrice');
            $spent += $price;

            if ($o->get('paymentStatus') !== 'Paid') {
                $debt += $price;
            }

            $at = $o->get('orderDateTime');

            if ($at && ($last === null || $at > $last)) {
                $last = $at;
            }
        }

        return (object) [
            'visits' => $visits,
            'spent' => round($spent, 2),
            'debt' => round($debt, 2),
            'lastVisit' => $last,
            'subscriptions' => $this->acl->checkScope('Subscription', 'read') ? $this->subscriptions->allFor($id) : [],
        ];
    }

    /**
     * Report ranges arrive as LOCAL calendar days ("2026-10-01 00:00:00" .. "2026-10-31 23:59:59",
     * Europe/Riga); the database stores UTC. Convert the bounds so "today" / "this month" mean the
     * operator's day, not a UTC day shifted by 2-3 hours.
     *
     * @return array{0: string, 1: string}
     */
    private function utcBounds(string $from, string $to): array
    {
        $tz = new \DateTimeZone((string) $this->config->get('timeZone', 'UTC') ?: 'UTC');
        $utc = new \DateTimeZone('UTC');

        $conv = function (string $v) use ($tz, $utc): string {
            try {
                return (new \DateTime($v, $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                return $v;
            }
        };

        return [$conv($from), $conv($to)];
    }

    /** UTC database datetime -> DateTime in the configured (display) timezone. */
    private function localTime(?string $utcValue): ?\DateTime
    {
        if (!$utcValue) {
            return null;
        }

        try {
            $d = new \DateTime($utcValue, new \DateTimeZone('UTC'));
            $d->setTimezone(new \DateTimeZone((string) $this->config->get('timeZone', 'UTC') ?: 'UTC'));

            return $d;
        } catch (\Exception $e) {
            return null;
        }
    }

    /** @param array<string, array{orders: int, revenue: float}> $daily */
    private function fillDays(string $from, string $to, array $daily): array
    {
        $out = [];

        try {
            $d = new \DateTime(substr($from, 0, 10));
            $end = new \DateTime(substr($to, 0, 10));
        } catch (\Exception $e) {
            return $out;
        }

        for ($i = 0; $d <= $end && $i < 400; $i++, $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $out[] = [
                'date' => $k,
                'orders' => $daily[$k]['orders'] ?? 0,
                'revenue' => round($daily[$k]['revenue'] ?? 0, 2),
            ];
        }

        return $out;
    }

    /**
     * Headline totals of the period BEFORE [from, to] with the same length, calculated by the
     * same rules as the main figures (revenue = completed orders only; cancelled excluded).
     */
    private function periodTotals(string $from, string $to): object
    {
        try {
            $f = new \DateTime($from);
            $t = new \DateTime($to);
        } catch (\Exception $e) {
            return (object) [];
        }

        $len = max(1, $t->getTimestamp() - $f->getTimestamp() + 1);
        $pTo = (clone $f)->modify('-1 second');
        $pFrom = (clone $pTo)->modify('-' . ($len - 1) . ' seconds');

        [$fromU, $toU] = $this->utcBounds($pFrom->format('Y-m-d H:i:s'), $pTo->format('Y-m-d H:i:s'));

        $em = $this->entityManager;
        $orders = $em->getRDBRepository('WashOrder')
            ->where(['orderDateTime>=' => $fromU, 'orderDateTime<=' => $toU, 'deleted' => false])
            ->find();

        $total = 0;
        $completed = 0;
        $revenue = 0.0;
        $unpaid = 0.0;
        $ids = [];

        foreach ($orders as $o) {
            if ($o->get('status') === 'Cancelled') {
                continue;
            }

            $total++;
            $ids[] = $o->getId();
            $price = (float) $o->get('finalPrice');

            if ($o->get('status') === 'Completed') {
                $completed++;
                $revenue += $price;
            }

            if ($o->get('paymentStatus') !== 'Paid') {
                $unpaid += $price;
            }
        }

        $cost = 0.0;
        $seeWages = $this->user->isAdmin();

        if ($ids && $seeWages) {
            foreach ($em->getRDBRepository('OrderWork')->where(['washOrderId' => $ids, 'deleted' => false])->find() as $w) {
                $cost += (float) $w->get('amount');
            }
        }

        $out = [
            'dateFrom' => $pFrom->format('Y-m-d H:i:s'),
            'dateTo' => $pTo->format('Y-m-d H:i:s'),
            'orders' => $total,
            'completed' => $completed,
            'revenue' => round($revenue, 2),
            'unpaid' => round($unpaid, 2),
            'averageCheck' => $completed ? round($revenue / $completed, 2) : 0,
        ];

        // Labour cost and margin reveal wages: administrator only (the fields are simply absent otherwise).
        if ($seeWages) {
            $out['labourCost'] = round($cost, 2);
            $out['margin'] = round($revenue - $cost, 2);
        }

        return (object) $out;
    }

    /**
     * CarWash/action/accountingExport { dateFrom, dateTo, format: csv|xml, lines?: bool, delimiter?: ; | , ,
     *                                    decimal?: . | , , preview?: bool }
     * The sales journal of COMPLETED orders for the accountant (Tools/Accounting/SalesJournal). Admin, or a role
     * that may read ALL orders. `preview` returns the first rows + totals only; otherwise `content` is the file.
     */
    public function postActionAccountingExport(Request $request): stdClass
    {
        if (!$this->user->isAdmin() && ($this->acl->getLevel('WashOrder', 'read') !== 'all' || !$this->acl->checkScope('RevenueReports'))) {
            throw new Forbidden('accounting_export_forbidden');
        }

        $data = $request->getParsedBody();
        $from = trim((string) ($data->dateFrom ?? ''));
        $to = trim((string) ($data->dateTo ?? ''));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
            throw new BadRequest('bad_period');
        }

        $format = (string) ($data->format ?? 'csv');

        if (!in_array($format, ['csv', 'xml'], true)) {
            throw new BadRequest('bad_format');
        }

        $j = $this->salesJournal->build($from, $to, !empty($data->lines));
        $out = [
            'dateFrom' => $from, 'dateTo' => $to, 'format' => $format, 'orders' => $j['orders'], 'rowCount' => count($j['rows']),
            'totals' => $j['totals'], 'byMethod' => $j['byMethod'], 'fiscalPending' => $j['fiscalPending'],
            'columns' => SalesJournal::COLUMNS,
        ];

        if (!empty($data->preview)) {
            $out['rows'] = array_slice($j['rows'], 0, 20);

            return (object) $out;
        }

        $name = 'sales-journal_' . $from . '_' . $to . ($format === 'xml' ? '.xml' : '.csv');
        $out['filename'] = $name;
        $out['mime'] = $format === 'xml' ? 'application/xml' : 'text/csv';
        $out['content'] = $format === 'xml'
            ? SalesJournal::xml($j['rows'], $from, $to)
            : SalesJournal::csv($j['rows'], (string) ($data->delimiter ?? ';'), (string) ($data->decimal ?? '.'));

        return (object) $out;
    }

    /**
     * One management overview for a period: orders and revenue, the services that earned
     * it, the customers who paid it and the employees who did the work. Built from the
     * same records the operational screens use — one honest dataset, no separate books.
     */
    public function postActionStatistics(Request $request): stdClass
    {
        $this->requireRevenueReports();

        $data = $request->getParsedBody();

        $from = trim((string) ($data->dateFrom ?? '')) ?: date('Y-m-01 00:00:00');
        $to = trim((string) ($data->dateTo ?? '')) ?: date('Y-m-t 23:59:59');
        [$fromU, $toU] = $this->utcBounds($from, $to);

        $em = $this->entityManager;

        $orders = $em->getRDBRepository('WashOrder')
            ->where(['orderDateTime>=' => $fromU, 'orderDateTime<=' => $toU, 'deleted' => false])
            ->find();

        $total = 0;
        $completed = 0;
        $cancelled = 0;
        $revenue = 0.0;
        $unpaid = 0.0;
        $byMethod = [];
        $byService = [];
        $byCustomer = [];
        $orderIds = [];
        $daily = [];
        $byHour = array_fill(0, 24, 0);
        $disc = ['promo' => 0.0, 'free' => 0.0, 'points' => 0.0];

        foreach ($orders as $o) {
            $total++;
            $status = $o->get('status');
            $price = (float) $o->get('finalPrice');

            if ($status === 'Cancelled') {
                $cancelled++;

                continue;
            }

            $orderIds[] = $o->getId();

            // Local calendar day / hour of the visit (the DB is UTC).
            $local = $this->localTime($o->get('orderDateTime'));

            if ($local) {
                $day = $local->format('Y-m-d');
                $daily[$day] = $daily[$day] ?? ['orders' => 0, 'revenue' => 0.0];
                $daily[$day]['orders']++;
            }

            $at = $this->localTime($o->get('startedAt') ?: $o->get('orderDateTime'));

            if ($at) {
                $byHour[(int) $at->format('G')]++;
            }

            if ($status === 'Completed') {
                $completed++;
                // Revenue counts completed work only — a car still on the ramp is not money.
                $revenue += $price;
                // What loyalty gave away on those orders (already inside the lower price above)
                $kind = (string) $o->get('loyaltyKind');
                if ($kind === 'Promo') {
                    $disc['promo'] += (float) $o->get('loyaltyDiscount');
                } elseif ($kind === 'FreeWash') {
                    $disc['free'] += (float) $o->get('loyaltyDiscount');
                }

                $disc['points'] += (float) $o->get('pointsDiscount');

                if ($local) {
                    $daily[$day]['revenue'] += $price;
                }
            }

            if ($o->get('paymentStatus') !== 'Paid') {
                $unpaid += $price;
            }

            $method = $o->get('paymentMethod') ?: 'Unknown';
            $byMethod[$method] = ($byMethod[$method] ?? 0) + 1;

            if ($o->get('serviceId')) {
                $key = $o->get('serviceId');
                $byService[$key] = $byService[$key] ?? ['name' => $o->get('serviceName'), 'count' => 0, 'sum' => 0.0];
                $byService[$key]['count']++;
                $byService[$key]['sum'] += $price;
            }

            if ($o->get('customerId') && $status === 'Completed') {
                $key = $o->get('customerId');
                $byCustomer[$key] = $byCustomer[$key] ?? ['name' => $o->get('customerName'), 'count' => 0, 'sum' => 0.0];
                $byCustomer[$key]['count']++;
                $byCustomer[$key]['sum'] += $price;
            }
        }

        // Wage cost and who earned it, from the order works of those orders.
        $byEmployee = [];
        $worksCost = 0.0;
        $seeWages = $this->user->isAdmin();

        if ($orderIds && $seeWages) {
            $works = $em->getRDBRepository('OrderWork')
                ->where(['washOrderId' => $orderIds, 'deleted' => false])
                ->find();

            foreach ($works as $w) {
                $amount = (float) $w->get('amount');
                $worksCost += $amount;

                $employeeId = $w->get('employeeId');

                if (!$employeeId) {
                    continue;
                }

                $byEmployee[$employeeId] = $byEmployee[$employeeId]
                    ?? ['name' => $w->get('employeeName'), 'works' => 0, 'sum' => 0.0, 'hours' => 0.0];
                $byEmployee[$employeeId]['works']++;
                $byEmployee[$employeeId]['sum'] += $amount;
            }
        }

        // Hours actually clocked in the period, per employee.
        $shifts = $em->getRDBRepository('Shift')
            ->where(['startedAt>=' => $fromU, 'startedAt<=' => $toU, 'deleted' => false])
            ->find();

        $hoursTotal = 0.0;

        foreach ($shifts as $sh) {
            $hours = (float) $sh->get('hours');
            $hoursTotal += $hours;

            $employeeId = $sh->get('employeeId');

            if (!$employeeId) {
                continue;
            }

            $byEmployee[$employeeId] = $byEmployee[$employeeId]
                ?? ['name' => $sh->get('employeeName'), 'works' => 0, 'sum' => 0.0, 'hours' => 0.0];
            $byEmployee[$employeeId]['hours'] += $hours;
        }

        $newCustomers = $em->getRDBRepository('Customer')
            ->where(['createdAt>=' => $fromU, 'createdAt<=' => $toU, 'deleted' => false])
            ->count();

        $sortDesc = function (array $rows, string $key): array {
            usort($rows, fn($a, $b) => $b[$key] <=> $a[$key]);

            return $rows;
        };

        $round = function (array $rows): array {
            return array_map(function ($r) {
                foreach (['sum', 'hours'] as $k) {
                    if (isset($r[$k])) {
                        $r[$k] = round($r[$k], 2);
                    }
                }

                return $r;
            }, $rows);
        };

        $employeeRows = $round($sortDesc(array_values($byEmployee), 'sum'));
        $labour = [
            'cost' => round($worksCost, 2),
            'hours' => round($hoursTotal, 2),
            // What is left of the revenue after the wage cost of those orders.
            'margin' => round($revenue - $worksCost, 2),
        ];

        // Earnings per employee, labour cost and margin are wages: for anyone but an administrator the
        // fields are not sent at all (hours worked stay - they are not pay).
        if (!$seeWages) {
            $employeeRows = array_map(function ($r) {
                unset($r['sum']);

                return $r;
            }, $employeeRows);
            $labour = ['hours' => $labour['hours']];
        }

        return (object) [
            'dateFrom' => $from,
            'dateTo' => $to,
            'orders' => (object) [
                'total' => $total,
                'completed' => $completed,
                'cancelled' => $cancelled,
                'revenue' => round($revenue, 2),
                'unpaid' => round($unpaid, 2),
                'averageCheck' => $completed ? round($revenue / $completed, 2) : 0,
                'byMethod' => (object) $byMethod,
            ],
            'services' => array_slice($round($sortDesc(array_values($byService), 'sum')), 0, 10),
            'customers' => (object) [
                'new' => $newCustomers,
                'top' => array_slice($round($sortDesc(array_values($byCustomer), 'sum')), 0, 10),
            ],
            'employees' => $employeeRows,
            'labour' => (object) $labour,
            // One entry per local day of the period (zeros included), for the revenue chart.
            'daily' => $this->fillDays($from, $to, $daily),
            // Visits by local hour of day (index 0..23), for the load strip.
            'byHour' => $byHour,
            // The same headline numbers for the previous period of equal length — the delta.
            'previous' => $this->periodTotals($from, $to),
            // Loyalty (module loyalty): discounts given on completed orders, points moved, referral rewards. Same gate as the revenue above.
            'loyalty' => $this->loyaltyStatistics($disc, $fromU, $toU),
        ];
    }

    /**
     * @param array{promo: float, free: float, points: float} $disc
     */
    private function loyaltyStatistics(array $disc, string $fromU, string $toU): ?stdClass
    {
        if (!$this->loyaltyCfg->enabled()) {
            return null;
        }

        $pdo = $this->entityManager->getPDO();
        $sum = function (string $where) use ($pdo, $fromU, $toU): int {
            $st = $pdo->prepare('SELECT COALESCE(SUM(points), 0) FROM loyalty_ledger WHERE deleted = 0 AND created_at >= ? AND created_at <= ? AND ' . $where);
            $st->execute([$fromU, $toU]);

            return (int) $st->fetchColumn();
        };
        $count = function (string $where) use ($pdo, $fromU, $toU): int {
            $st = $pdo->prepare('SELECT COUNT(*) FROM loyalty_ledger WHERE deleted = 0 AND created_at >= ? AND created_at <= ? AND ' . $where);
            $st->execute([$fromU, $toU]);

            return (int) $st->fetchColumn();
        };

        return (object) [
            'discounts' => (object) [
                'promo' => round($disc['promo'], 2),
                'free' => round($disc['free'], 2),
                'points' => round($disc['points'], 2),
                'total' => round($disc['promo'] + $disc['free'] + $disc['points'], 2),
            ],
            'points' => (object) [
                'earned' => $sum("kind = 'Earn'") + $sum("kind = 'Reverse' AND note = 'earn reversed'"),
                'redeemed' => -$sum("kind = 'Redeem'") - $sum("kind = 'Reverse' AND note = 'returned'"),
                'expired' => -$sum("kind = 'Expire'"),
                'adjusted' => $sum("kind = 'Adjust'"),
            ],
            'referrals' => (object) [
                'rewarded' => $count("kind = 'Referral' AND unique_key LIKE 'ref:%'") - $count("kind = 'Reverse' AND note = 'referral'"),
                'points' => $sum("kind = 'Referral'") + $sum("kind = 'Reverse' AND note LIKE 'referral%'"),
            ],
        ];
    }

    /**
     * Render the "Rēķins par mēnesi" Template record. A consolidated bill spans many orders,
     * so Espo's per-entity PDF service cannot produce it — but the wording must still be
     * editable, hence a real Template whose placeholders we substitute here.
     *
     * Supported placeholders: {{customerName}} {{regNumber}} {{pvnNumber}} {{legalAddress}}
     * {{dateFrom}} {{dateTo}} {{ordersCount}} {{net}} {{vat}} {{total}} and {{{rowsHtml}}}
     * for the order rows. Missing template → a plain built-in fallback, never an error.
     *
     * @param array<string, string> $values
     */
    private function renderMonthlyInvoiceTemplate(array $values, string $rowsHtml, ?string $templateId = null): string
    {
        $repository = $this->entityManager->getRDBRepository('Template');

        // An explicitly chosen template wins; otherwise the one shipped with the system.
        $template = $templateId
            ? $repository->where(['id' => $templateId, 'deleted' => false])->findOne()
            : null;

        $template = $template ?: $repository
            ->where(['name' => self::MONTHLY_INVOICE_TEMPLATE, 'deleted' => false])
            ->findOne();

        $body = $template
            ? (string) $template->get('body')
            : '<h3>Rēķins</h3><div>{{customerName}}</div><div>{{dateFrom}} — {{dateTo}}</div>' .
            '<table border="0" style="width:100%">{{{rowsHtml}}}</table>' .
            '<div style="text-align:right"><b>{{total}} EUR</b></div>';

        // Rows are already HTML — everything else is escaped when it goes in.
        $body = str_replace(['{{{rowsHtml}}}', '{{rowsHtml}}'], $rowsHtml, $body);

        // The client's header / footer come from Settings, like on the per-order documents.
        $brand = new \Espo\Custom\Tools\Brand\Brand($this->config);
        $body = str_replace(['{{{brandHeaderHtml}}}', '{{{brandFooterHtml}}}'], [$brand->headerHtml(), $brand->footerHtml()], $body);

        foreach ($values as $key => $value) {
            $body = str_replace('{{' . $key . '}}', htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), $body);
        }

        return $body;
    }

    /**
     * "Izpildīto darbu pieņemšanas-nodošanas akts" for the period: the same completed orders and the same totals as the
     * monthly invoice, without payment, with the parties' details and both signature lines. Printed documents are always
     * Latvian; the performer's header comes from the brand settings, never from code.
     *
     * @param list<object> $rows
     */
    private function renderWorkAct(\Espo\ORM\Entity $customer, array $rows, float $total, float $net, string $from, string $to): string
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $money = fn ($v) => number_format((float) $v, 2, '.', '');
        $brand = new \Espo\Custom\Tools\Brand\Brand($this->config);

        $tr = '';

        foreach ($rows as $row) {
            $services = [];

            foreach ($row->lines as $line) {
                $services[] = $line->name . ' (' . $money($line->price) . ')';
            }

            $tr .= '<tr>' .
                '<td style="padding:2px 4px; border-bottom:1px solid #ccc">' . $esc(substr((string) $row->date, 0, 10)) . '</td>' .
                '<td style="padding:2px 4px; border-bottom:1px solid #ccc">' . $esc($row->number) . '</td>' .
                '<td style="padding:2px 4px; border-bottom:1px solid #ccc">' . $esc($row->vehicle) . '</td>' .
                '<td style="padding:2px 4px; border-bottom:1px solid #ccc">' . $esc(implode(', ', $services)) . '</td>' .
                '<td style="padding:2px 4px; border-bottom:1px solid #ccc; text-align:right">' . $money($row->price) . '</td>' .
                '</tr>';
        }

        $party = [];

        foreach ([
            'Reģ. Nr. ' => $customer->get('regNumber'),
            'PVN Nr. ' => $customer->get('pvnNumber'),
            '' => $customer->get('legalAddress'),
        ] as $label => $value) {
            if (trim((string) $value) !== '') {
                $party[] = $esc($label . $value);
            }
        }

        $sign = static fn (string $role, string $name) =>
            '<td style="width:50%; vertical-align:top; padding-top:8px"><b>' . $role . '</b><br>' . $name .
            '<br><br><br>______________________________<br><span style="font-size:11px">(paraksts, atšifrējums)</span>' .
            '<br><br><span style="font-size:11px">Z.v.</span></td>';

        return '<div class="work-act" style="font-size:13px">' .
            '<div style="text-align:center">' . $brand->headerHtml() . '</div>' .
            '<h3 style="text-align:center; margin:18px 0 4px">Izpildīto darbu pieņemšanas–nodošanas akts</h3>' .
            '<div style="text-align:center; margin-bottom:14px">Periods: ' . $esc(substr($from, 0, 10)) . ' — ' . $esc(substr($to, 0, 10)) .
            ' · Akta datums: ' . $esc(date('Y-m-d')) . '</div>' .
            '<table style="width:100%; margin-bottom:12px"><tbody><tr>' .
            '<td style="width:50%; vertical-align:top"><b>Izpildītājs</b><br>' . $esc($brand->docName()) . '</td>' .
            '<td style="width:50%; vertical-align:top"><b>Pasūtītājs</b><br>' . $esc($customer->get('name')) .
            ($party ? '<br><span style="font-size:11px">' . implode(' · ', $party) . '</span>' : '') . '</td>' .
            '</tr></tbody></table>' .
            '<p>Izpildītājs ir veicis, bet Pasūtītājs pieņēmis šādus darbus (transportlīdzekļu mazgāšanas pakalpojumus):</p>' .
            ($tr === ''
                ? ''
                : '<table style="width:100%; font-size:12px; border-collapse:collapse"><tbody><tr>' .
                '<td style="padding:2px 4px"><b>Datums</b></td><td style="padding:2px 4px"><b>Nr.</b></td>' .
                '<td style="padding:2px 4px"><b>Auto</b></td><td style="padding:2px 4px"><b>Pakalpojumi</b></td>' .
                '<td style="padding:2px 4px; text-align:right"><b>EUR</b></td></tr>' . $tr . '</tbody></table>') .
            '<table style="margin:12px 0 0 auto; text-align:right"><tbody>' .
            '<tr><td style="padding:1px 8px">Pasūtījumu skaits:</td><td>' . count($rows) . '</td></tr>' .
            '<tr><td style="padding:1px 8px">Kopā bez PVN:</td><td>' . $money($net) . ' EUR</td></tr>' .
            '<tr><td style="padding:1px 8px">PVN 21%:</td><td>' . $money($total - $net) . ' EUR</td></tr>' .
            '<tr><td style="padding:1px 8px"><b>Kopā ar PVN:</b></td><td><b>' . $money($total) . ' EUR</b></td></tr>' .
            '</tbody></table>' .
            '<p style="margin-top:14px">Darbi izpildīti pilnā apmērā un noteiktajā termiņā. Pasūtītājam pret darbu apjomu, kvalitāti un izpildes termiņiem pretenziju nav.</p>' .
            '<table style="width:100%; margin-top:10px"><tbody><tr>' .
            $sign('Izpildītājs', $esc($brand->docName())) . $sign('Pasūtītājs', $esc($customer->get('name'))) .
            '</tr></tbody></table>' .
            $brand->footerHtml() .
            '</div>';
    }

    /**
     * Consolidated monthly invoice: every completed order of one customer in a period on a
     * single bill (contract customers are invoiced per month, not per wash). Returns the
     * data; the client renders and prints it, so no PDF template is involved.
     *
     * @throws BadRequest
     * @throws NotFound
     */
    public function postActionMonthlyInvoice(Request $request): stdClass
    {
        $this->requireScope('Customer', 'read');
        $this->requireAll('WashOrder', 'read');

        $data = $request->getParsedBody();

        $customerId = trim((string) ($data->customerId ?? ''));

        if ($customerId === '') {
            throw new BadRequest("Field 'customerId' is required.");
        }

        $customer = $this->entityManager->getEntityById('Customer', $customerId);

        if (!$customer) {
            throw new NotFound("Customer not found.");
        }

        $this->requireEntity($customer, 'read');

        $from = trim((string) ($data->dateFrom ?? '')) ?: date('Y-m-01 00:00:00');
        $to = trim((string) ($data->dateTo ?? '')) ?: date('Y-m-t 23:59:59');

        $em = $this->entityManager;

        // Only completed work is billable — a car still on the ramp is not invoiced.
        $orders = $em->getRDBRepository('WashOrder')
            ->where([
                'customerId' => $customerId,
                'status' => 'Completed',
                'orderDateTime>=' => $from,
                'orderDateTime<=' => $to,
                'deleted' => false,
            ])
            ->order('orderDateTime')
            ->find();

        $rows = [];
        $total = 0.0;

        foreach ($orders as $o) {
            $lines = [];

            foreach (
                $em->getRDBRepository('OrderAddon')
                    ->where(['washOrderId' => $o->getId(), 'deleted' => false])
                    ->order('sortOrder')
                    ->find() as $line
            ) {
                $lines[] = (object) [
                    'name' => $line->get('name'),
                    'price' => (float) $line->get('price'),
                    'isBase' => (bool) $line->get('isBase'),
                ];
            }

            $price = (float) $o->get('finalPrice');
            $total += $price;

            $rows[] = (object) [
                'id' => $o->getId(),
                'number' => $o->get('name'),
                'date' => $o->get('orderDateTime'),
                'vehicle' => $o->get('vehicleName'),
                'lines' => $lines,
                'price' => round($price, 2),
                'paymentStatus' => $o->get('paymentStatus'),
            ];
        }

        // Prices are VAT-inclusive (21%), same split as on a single order.
        $net = round($total / 1.21, 2);

        // The document itself comes from an editable Template record, so its wording and
        // layout can be changed in the UI like the receipt and the single-order invoice.
        $rowsHtml = '';

        foreach ($rows as $row) {
            $services = [];

            foreach ($row->lines as $line) {
                $services[] = $line->name . ' (' . number_format($line->price, 2, '.', '') . ')';
            }

            $rowsHtml .= '<tr>' .
                '<td style="padding:2px 4px">' . htmlspecialchars(substr((string) $row->date, 0, 10), ENT_QUOTES, 'UTF-8') . '</td>' .
                '<td style="padding:2px 4px">' . htmlspecialchars((string) $row->number, ENT_QUOTES, 'UTF-8') . '</td>' .
                '<td style="padding:2px 4px">' . htmlspecialchars((string) $row->vehicle, ENT_QUOTES, 'UTF-8') . '</td>' .
                '<td style="padding:2px 4px">' . htmlspecialchars(implode(', ', $services), ENT_QUOTES, 'UTF-8') . '</td>' .
                '<td style="padding:2px 4px; text-align:right">' . number_format($row->price, 2, '.', '') . '</td>' .
                '</tr>';
        }

        // A COMPLETE table with its own header: the placeholder must be block level. Bare
        // <tr> rows would need a surrounding <table> in the template, and the template
        // editor hoists a placeholder out of a table on the next save — the rows then land
        // outside any table and print as one run-on line.
        $rowsHtml = $rowsHtml === ''
            ? ''
            : '<table style="width:100%; font-size:12px; border-collapse:collapse"><tbody>' .
            '<tr>' .
            '<td style="padding:2px 4px"><b>Datums</b></td>' .
            '<td style="padding:2px 4px"><b>Nr.</b></td>' .
            '<td style="padding:2px 4px"><b>Auto</b></td>' .
            '<td style="padding:2px 4px"><b>Pakalpojumi</b></td>' .
            '<td style="padding:2px 4px; text-align:right"><b>EUR</b></td>' .
            '</tr>' . $rowsHtml .
            '</tbody></table>';

        $html = $this->renderMonthlyInvoiceTemplate([
            'customerName' => (string) $customer->get('name'),
            'regNumber' => (string) $customer->get('regNumber'),
            'pvnNumber' => (string) $customer->get('pvnNumber'),
            'legalAddress' => (string) $customer->get('legalAddress'),
            'dateFrom' => substr($from, 0, 10),
            'dateTo' => substr($to, 0, 10),
            'ordersCount' => (string) count($rows),
            'net' => number_format($net, 2, '.', ''),
            'vat' => number_format($total - $net, 2, '.', ''),
            'total' => number_format($total, 2, '.', ''),
        ], $rowsHtml, trim((string) ($data->templateId ?? '')) ?: null);

        // The second document of the same dialog: the act of work performed. Built from the SAME $rows / totals as the
        // invoice (no second query, no money logic of its own) - it carries no payment data, only what was done.
        $actHtml = $this->renderWorkAct($customer, $rows, $total, $net, $from, $to);

        $templateList = [];

        foreach (
            $this->entityManager->getRDBRepository('Template')
                ->where(['entityType' => 'Customer', 'deleted' => false])
                ->order('name')
                ->find() as $t
        ) {
            $templateList[] = (object) ['id' => $t->getId(), 'name' => $t->get('name')];
        }

        return (object) [
            'html' => $html,
            'actHtml' => $actHtml,
            'templates' => $templateList,
            'customer' => (object) [
                'id' => $customer->getId(),
                'name' => $customer->get('name'),
                'regNumber' => $customer->get('regNumber'),
                'pvnNumber' => $customer->get('pvnNumber'),
                'legalAddress' => $customer->get('legalAddress'),
                'email' => $customer->get('email'),
                'phone' => $customer->get('phone'),
            ],
            'dateFrom' => $from,
            'dateTo' => $to,
            'orders' => $rows,
            'ordersCount' => count($rows),
            'total' => round($total, 2),
            'net' => $net,
            'vat' => round($total - $net, 2),
        ];
    }

    /**
     * Client-side error log. The browser posts what it caught (message, stack, where) and
     * it lands in the normal Espo log, so a UI glitch a user reports later can actually be
     * looked up instead of guessed at.
     */
    public function postActionLogClientError(Request $request): stdClass
    {
        $data = $request->getParsedBody();

        $message = mb_substr(trim((string) ($data->message ?? '')), 0, 500);

        if ($message === '') {
            return (object) ['ok' => false];
        }

        $this->log->error('Client error: ' . $message, [
            'where' => mb_substr((string) ($data->where ?? ''), 0, 200),
            'stack' => mb_substr((string) ($data->stack ?? ''), 0, 2000),
            'url' => mb_substr((string) ($data->url ?? ''), 0, 300),
        ]);

        return (object) ['ok' => true];
    }

    /**
     * Supplies consumption summary over a period: how many orders (works) were done and
     * how much of each supply they consumed, valued at unit cost. Mirrors the
     * DeductSupplies bill-of-materials (base wash + add-ons → ServiceSupply lines) so the
     * report matches what was actually deducted from stock.
     */
    public function postActionSupplyUsage(Request $request): stdClass
    {
        $this->requireRevenueReports();
        $this->requireScope('Supply', 'read');

        $data = $request->getParsedBody();

        $from = trim((string) ($data->dateFrom ?? '')) ?: date('Y-m-01 00:00:00');
        $to = trim((string) ($data->dateTo ?? '')) ?: date('Y-m-t 23:59:59');
        [$fromU, $toU] = $this->utcBounds($from, $to);

        $em = $this->entityManager;

        $orders = $em->getRDBRepository('WashOrder')
            ->where([
                'status' => 'Completed',
                'orderDateTime>=' => $fromU,
                'orderDateTime<=' => $toU,
                'deleted' => false,
            ])
            ->find();

        // How many times each service was performed across the period (base + add-ons).
        $serviceCount = [];
        $ordersCount = 0;
        $orderIds = [];

        foreach ($orders as $order) {
            $ordersCount++;
            $orderIds[] = $order->getId();

            if ($order->get('serviceId')) {
                $sid = $order->get('serviceId');
                $serviceCount[$sid] = ($serviceCount[$sid] ?? 0) + 1;
            }

            $addons = $em->getRDBRepository('WashOrder')->getRelation($order, 'addons')->find();

            foreach ($addons as $addon) {
                $aid = $addon->getId();
                $serviceCount[$aid] = ($serviceCount[$aid] ?? 0) + 1;
            }
        }

        // Works actually recorded on those orders (OrderWork lines).
        $worksCount = 0;

        if ($orderIds) {
            $worksCount = $em->getRDBRepository('OrderWork')
                ->where(['washOrderId' => $orderIds, 'deleted' => false])
                ->count();
        }

        // Supplies are read once and cached — the same supply appears in many BOM lines.
        $supplyCache = [];

        $getSupply = function (string $id) use ($em, &$supplyCache) {
            if (!array_key_exists($id, $supplyCache)) {
                $supplyCache[$id] = $em->getEntityById('Supply', $id);
            }

            return $supplyCache[$id];
        };

        // Expand services → supplies, multiplying each BOM line by how often the service ran.
        // The same pass values each service's own consumption, which is what ties the spend
        // to the services that caused it.
        $usage = [];
        $serviceRows = [];

        foreach ($serviceCount as $serviceId => $times) {
            $lines = $em->getRDBRepository('ServiceSupply')->where(['serviceId' => $serviceId])->find();

            $serviceCost = 0.0;

            foreach ($lines as $line) {
                $supplyId = $line->get('supplyId');
                $quantity = (float) $line->get('quantity') * $times;

                if (!$supplyId || $quantity <= 0) {
                    continue;
                }

                $usage[$supplyId] = ($usage[$supplyId] ?? 0) + $quantity;

                $supply = $getSupply($supplyId);

                if ($supply) {
                    $serviceCost += $quantity * (float) $supply->get('unitCost');
                }
            }

            $service = $em->getEntityById('WashService', $serviceId);

            $serviceRows[] = [
                'serviceId' => $serviceId,
                'serviceName' => $service ? $service->get('name') : $serviceId,
                'category' => $service ? $service->get('category') : null,
                'count' => $times,
                'cost' => round($serviceCost, 2),
                // Average supply spend for one performance of this service.
                'costPerUnit' => $times > 0 ? round($serviceCost / $times, 2) : 0,
            ];
        }

        usort($serviceRows, fn($a, $b) => $b['cost'] <=> $a['cost']);

        $rows = [];
        $totalCost = 0.0;

        foreach ($usage as $supplyId => $quantity) {
            $supply = $getSupply($supplyId);

            if (!$supply) {
                continue;
            }

            $unitCost = (float) $supply->get('unitCost');
            $cost = round($quantity * $unitCost, 2);
            $totalCost += $cost;

            $rows[] = [
                'supplyId' => $supplyId,
                'supplyName' => $supply->get('name'),
                'unit' => $supply->get('unit'),
                'quantity' => round($quantity, 2),
                'unitCost' => round($unitCost, 2),
                'cost' => $cost,
            ];
        }

        usort($rows, fn($a, $b) => $b['cost'] <=> $a['cost']);

        return (object) [
            'dateFrom' => $from,
            'dateTo' => $to,
            'ordersCount' => $ordersCount,
            'worksCount' => $worksCount,
            'services' => $serviceRows,
            'servicesCount' => array_sum(array_column($serviceRows, 'count')),
            'rows' => $rows,
            'totalCost' => round($totalCost, 2),
        ];
    }

    /**
     * Preview a mailing with the placeholders filled from a real customer's data — the
     * "automatic insertion of customer data" made visible before sending. Uses the given
     * customer, else the first consenting recipient for the channel, else any customer.
     *
     * @throws BadRequest
     * @throws NotFound
     */
    public function postActionPreviewMailing(Request $request): stdClass
    {
        $this->requireScope('Mailing', 'read');
        $this->requireScope('Customer', 'read');

        $data = $request->getParsedBody();

        $mailingId = trim((string) ($data->mailingId ?? ''));

        if ($mailingId === '') {
            throw new BadRequest("Field 'mailingId' is required.");
        }

        $em = $this->entityManager;

        $mailing = $em->getEntityById('Mailing', $mailingId);

        if (!$mailing) {
            throw new NotFound('Mailing not found.');
        }

        $this->requireEntity($mailing, 'read');

        $channel = (string) $mailing->get('channel');
        $consentMap = [
            'WhatsApp' => ['allowWhatsapp', 'phone'],
            'Phone' => ['allowPhone', 'phone'],
            'Email' => ['allowEmail', 'email'],
        ];
        $map = $consentMap[$channel] ?? null;

        $customer = null;
        $customerId = trim((string) ($data->customerId ?? ''));

        if ($customerId !== '') {
            $customer = $em->getEntityById('Customer', $customerId);

            if ($customer) {
                $this->requireEntity($customer, 'read');
            }
        } else {
            // No customer chosen: a sample is picked from ALL customers, so that needs read: all.
            $this->requireAll('Customer', 'read');
        }

        if (!$customer && $map) {
            [$consentField, $contactField] = $map;
            $customer = $em->getRDBRepository('Customer')
                ->where([$consentField => true, 'deleted' => false])
                ->where([$contactField . '!=' => null])
                ->where([$contactField . '!=' => ''])
                ->findOne();
        }

        if (!$customer) {
            $customer = $em->getRDBRepository('Customer')->where(['deleted' => false])->findOne();
        }

        $render = function (?string $tpl) use ($mailing, $customer): string {
            return strtr((string) $tpl, [
                '{name}' => $customer ? (string) $customer->get('name') : '',
                '{phone}' => $customer ? (string) $customer->get('phone') : '',
                '{discount}' => (string) ($mailing->get('discountPercent') ?? ''),
                '{offer}' => (string) ($mailing->get('offer') ?? ''),
                '{unsubscribe}' => $customer ? $this->unsubscribeUrl($this->ensureUnsubscribeToken($customer)) : '',
            ]);
        };

        $recipientCount = 0;

        if ($map) {
            [$consentField, $contactField] = $map;
            $recipientCount = $em->getRDBRepository('Customer')
                ->where([$consentField => true, 'deleted' => false])
                ->where([$contactField . '!=' => null])
                ->where([$contactField . '!=' => ''])
                ->count();
        }

        return (object) [
            'channel' => $channel,
            'sampleCustomer' => $customer ? $customer->get('name') : null,
            'subject' => $render($mailing->get('subject') ?: $mailing->get('name')),
            'body' => $render($mailing->get('body') ?: $mailing->get('offer')),
            'recipientCount' => $recipientCount,
            'smtpConfigured' => $this->emailSender->hasSystemSmtp(),
        ];
    }

    /**
     * Build (or preview) the recipient audience for a mailing from a simple filter — the
     * "Build recipients" button, so the operator does not add recipients by hand.
     *
     *  { mailingId, segment?, onlyConsented? }                 -> preview: { total, list }
     *  { mailingId, materialize:true, customerIds?: [...] }     -> write PENDING recipient rows
     *
     * Preview returns who qualifies (name + contact) so the list is visible before sending.
     * Materialize writes one PENDING MailingRecipient per kept customer (replacing the previous
     * PENDING set), which the detail view shows and the operator can trim; Send then delivers to
     * exactly those rows.
     *
     * @throws BadRequest
     * @throws NotFound
     */
    public function postActionBuildAudience(Request $request): stdClass
    {
        $this->requireScope('Mailing', 'edit');
        $this->requireAll('Customer', 'read');

        $data = $request->getParsedBody();
        $mailingId = trim((string) ($data->mailingId ?? ''));

        if ($mailingId === '') {
            throw new BadRequest("Field 'mailingId' is required.");
        }

        $em = $this->entityManager;
        $mailing = $em->getEntityById('Mailing', $mailingId);

        if (!$mailing) {
            throw new NotFound('Mailing not found.');
        }

        $this->requireEntity($mailing, 'edit');

        $channel = (string) $mailing->get('channel');
        $map = self::CONSENT_MAP[$channel] ?? null;

        if (!$map) {
            throw new BadRequest('Unknown channel.');
        }

        [, $contactField] = $map;

        // Consent is respected by default; the operator may widen to "everyone with a contact"
        // for one build, but the opt-out link and default-on consent are the standing model.
        $onlyConsented = !isset($data->onlyConsented) || (bool) $data->onlyConsented;
        $segment = isset($data->segment) && $data->segment !== '' ? (string) $data->segment : null;

        $builder = $this->audienceBuilder($mailing, $onlyConsented, $segment);

        $customers = [];
        $list = [];

        foreach ($builder->order('name')->find() as $c) {
            $customers[] = $c;
            $list[] = (object) [
                'id' => $c->getId(),
                'name' => (string) $c->get('name'),
                'contact' => (string) $c->get($contactField),
                'isCorporate' => (bool) $c->get('isCorporate'),
            ];
        }

        if (empty($data->materialize)) {
            return (object) [
                'channel' => $channel,
                'segment' => $segment ?? (string) $mailing->get('segment'),
                'onlyConsented' => $onlyConsented,
                'total' => count($list),
                'list' => $list,
            ];
        }

        $keep = null;

        if (isset($data->customerIds) && is_array($data->customerIds)) {
            $keep = array_flip(array_map('strval', $data->customerIds));
        }

        // Replace the previous draft (PENDING) set; already-delivered rows are left untouched.
        foreach (
            $em->getRDBRepository('MailingRecipient')
                ->where(['mailingId' => $mailingId, 'status' => 'Pending'])
                ->find() as $old
        ) {
            $em->removeEntity($old);
        }

        $created = 0;

        foreach ($customers as $c) {
            if ($keep !== null && !isset($keep[$c->getId()])) {
                continue;
            }

            $em->createEntity('MailingRecipient', [
                'name' => $c->get('name'),
                'contact' => $c->get($contactField),
                'status' => 'Pending',
                'mailingId' => $mailingId,
                'customerId' => $c->getId(),
            ]);

            $created++;
        }

        return (object) ['materialized' => true, 'count' => $created];
    }

    /**
     * Send a mailing (the "Send" button). If the operator has already built a recipient list
     * (PENDING rows via "Build recipients"), it sends to exactly that reviewed audience;
     * otherwise it auto-selects the whole consented audience. Each recipient gets a delivery
     * status; Email is delivered when SMTP is configured; results roll onto the mailing.
     *
     * @throws BadRequest
     * @throws NotFound
     */
    public function postActionSendMailing(Request $request): stdClass
    {
        $this->requireScope('Mailing', 'edit');
        $this->requireAll('Customer', 'read');

        $data = $request->getParsedBody();
        $mailingId = trim((string) ($data->mailingId ?? ''));

        if ($mailingId === '') {
            throw new BadRequest("Field 'mailingId' is required.");
        }

        $em = $this->entityManager;
        $mailing = $em->getEntityById('Mailing', $mailingId);

        if (!$mailing) {
            throw new NotFound('Mailing not found.');
        }

        $this->requireEntity($mailing, 'edit');

        $channel = (string) $mailing->get('channel');
        $map = self::CONSENT_MAP[$channel] ?? null;

        if (!$map) {
            throw new BadRequest('Unknown channel.');
        }

        [, $contactField] = $map;

        $emailChannel = ($channel === 'Email');
        $smtp = $this->emailSender->hasSystemSmtp();

        // A built (PENDING) list is the reviewed/edited audience — send to exactly it. If there
        // is none, fall back to auto-selecting the whole consented audience (old behaviour).
        $pending = [];

        foreach (
            $em->getRDBRepository('MailingRecipient')
                ->where(['mailingId' => $mailingId, 'status' => 'Pending'])
                ->find() as $row
        ) {
            $pending[] = $row;
        }

        /** @var array<array{0: \Espo\ORM\Entity, 1: string, 2: ?\Espo\ORM\Entity}> $recipients */
        $recipients = [];

        if (count($pending) > 0) {
            foreach ($pending as $row) {
                $customerId = (string) $row->get('customerId');
                $customer = $customerId !== '' ? $em->getEntityById('Customer', $customerId) : null;

                if (!$customer) {
                    $em->removeEntity($row);

                    continue;
                }

                $recipients[] = [$customer, (string) $row->get('contact'), $row];
            }
        } else {
            // Clean any stale delivery rows so an auto re-send starts fresh.
            foreach ($em->getRDBRepository('MailingRecipient')->where(['mailingId' => $mailingId])->find() as $old) {
                $em->removeEntity($old);
            }

            foreach ($this->audienceBuilder($mailing, true)->find() as $customer) {
                $recipients[] = [$customer, (string) $customer->get($contactField), null];
            }
        }

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $total = 0;

        foreach ($recipients as [$customer, $contact, $row]) {
            $total++;
            $status = 'Skipped';
            $error = null;
            $deliveredAt = null;

            if ($emailChannel && $smtp && $contact !== '') {
                try {
                    $email = $em->createEntity('Email', [
                        'to' => $contact,
                        'subject' => $this->renderMailing($mailing->get('subject') ?: $mailing->get('name'), $mailing, $customer),
                        'body' => $this->renderMailing($mailing->get('body') ?: $mailing->get('offer'), $mailing, $customer),
                        'isHtml' => false,
                    ]);
                    $this->emailSender->create()->send($email);
                    $status = 'Sent';
                    $deliveredAt = date('Y-m-d H:i:s');
                    $sent++;
                } catch (\Throwable $e) {
                    $status = 'Failed';
                    $error = mb_substr($e->getMessage(), 0, 255);
                    $failed++;
                }
            } else {
                // No delivery gateway for this channel (or SMTP off): record the audience only.
                $skipped++;
            }

            if ($row) {
                $row->set([
                    'status' => $status,
                    'error' => $error,
                    'deliveredAt' => $deliveredAt,
                    'contact' => $contact,
                ]);
                $em->saveEntity($row);
            } else {
                $em->createEntity('MailingRecipient', [
                    'name' => $customer->get('name'),
                    'contact' => $contact,
                    'status' => $status,
                    'error' => $error,
                    'deliveredAt' => $deliveredAt,
                    'mailingId' => $mailingId,
                    'customerId' => $customer->getId(),
                ]);
            }
        }

        $mailing->set([
            'status' => 'Sent',
            'sentAt' => date('Y-m-d H:i:s'),
            'recipientCount' => $total,
            'sentCount' => $sent,
            'failedCount' => $failed,
        ]);
        $em->saveEntity($mailing, ['skipMailingDispatch' => true]);

        return (object) [
            'channel' => $channel,
            'segment' => (string) $mailing->get('segment'),
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'delivered' => $emailChannel && $smtp,
        ];
    }

    /** Fill the customer/mailing placeholders in a subject or body. */
    private function renderMailing(?string $tpl, \Espo\ORM\Entity $mailing, \Espo\ORM\Entity $customer): string
    {
        return strtr((string) $tpl, [
            '{name}' => (string) $customer->get('name'),
            '{phone}' => (string) $customer->get('phone'),
            '{discount}' => (string) ($mailing->get('discountPercent') ?? ''),
            '{offer}' => (string) ($mailing->get('offer') ?? ''),
            '{unsubscribe}' => $this->unsubscribeUrl($this->ensureUnsubscribeToken($customer)),
        ]);
    }

    /** Per-channel consent flag + contact field. The ONE audience definition. */
    private const CONSENT_MAP = [
        'WhatsApp' => ['allowWhatsapp', 'phone'],
        'Phone' => ['allowPhone', 'phone'],
        'Email' => ['allowEmail', 'email'],
    ];

    /**
     * The audience for a mailing: customers who have the channel's contact detail and — when
     * $onlyConsented — have ticked that channel's consent flag, narrowed by the segment. Shared
     * by preview, "Build recipients" and Send so the three never disagree. `where()` returns a
     * new builder, so every clause is reassigned.
     *
     * @return \Espo\ORM\Repository\RDBSelectBuilder<\Espo\ORM\Entity>
     */
    private function audienceBuilder(\Espo\ORM\Entity $mailing, bool $onlyConsented, ?string $segment = null)
    {
        $channel = (string) $mailing->get('channel');
        $map = self::CONSENT_MAP[$channel] ?? null;

        if (!$map) {
            throw new BadRequest('Unknown channel.');
        }

        [$consentField, $contactField] = $map;

        $builder = $this->entityManager->getRDBRepository('Customer')
            ->where(['deleted' => false])
            ->where([$contactField . '!=' => null])
            ->where([$contactField . '!=' => '']);

        if ($onlyConsented) {
            $builder = $builder->where([$consentField => true]);
        }

        $segment = $segment ?? (string) $mailing->get('segment');

        if ($segment === 'Corporate') {
            $builder = $builder->where(['isCorporate' => true]);
        } elseif ($segment === 'Individual') {
            $builder = $builder->where(['isCorporate' => false]);
        }

        return $builder;
    }

    /** Lazily mint a per-customer unsubscribe token (the authorization in the opt-out link). */
    private function ensureUnsubscribeToken(\Espo\ORM\Entity $customer): string
    {
        $token = (string) $customer->get('unsubscribeToken');

        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            $customer->set('unsubscribeToken', $token);
            $this->entityManager->saveEntity($customer, ['skipHooks' => true]);
        }

        return $token;
    }

    /** The opt-out link that goes in the email — points at the landing page, not the CRM API. */
    private function unsubscribeUrl(string $token): string
    {
        // The client's own site hosts the opt-out page (Settings → Brand). With none configured
        // the link points straight at the CRM's public opt-out entry point, so a mailing is
        // never sent without a working unsubscribe.
        $base = rtrim((string) $this->config->get('landingSiteUrl', ''), '/');

        if ($base === '') {
            return rtrim((string) $this->config->get('siteUrl', ''), '/') . '/?entryPoint=mailingUnsubscribe&id=' . $token;
        }

        return $base . '/unsubscribe.html?id=' . $token;
    }

    /** Cached name lookup, so a payroll run does not re-query the same employee/role. */
    private array $nameCache = [];

    private function nameOf(string $entityType, string $id): string
    {
        $key = $entityType . ':' . $id;

        if (!array_key_exists($key, $this->nameCache)) {
            $entity = $this->entityManager->getEntityById($entityType, $id);
            $this->nameCache[$key] = $entity ? (string) $entity->get('name') : $id;
        }

        return $this->nameCache[$key];
    }
}
