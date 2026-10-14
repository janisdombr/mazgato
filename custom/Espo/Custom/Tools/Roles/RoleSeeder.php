<?php

namespace Espo\Custom\Tools\Roles;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * The two working roles of a car wash, as data (docs/ROLES.md is the human-readable table):
 *
 *   operator - the counter: orders, customers, cars, bookings, payment, shifts. NO wages, NO revenue overview,
 *              NO stock edits, no mailings.
 *   manager  - the operator plus stock, mailings and the revenue reports. Still NO wages.
 *
 * Wages are not a role matter at all: the fields/actions are administrator-only in the code (entityAcl
 * `onlyAdmin`, CarWash::payroll). The administrator needs no role. Workers use the tablet (Kiosk device role).
 *
 * Idempotent: a role is found by any of its three localised names and is
 * left untouched when it exists - an owner's edits survive a re-run. `$update = true` rewrites the rights.
 */
final class RoleSeeder
{
    /** @var array<string, array<string, string>> */
    public const NAMES = [
        'operator' => ['lv_LV' => 'Operators', 'ru_RU' => 'Оператор', 'en_US' => 'Operator', 'lt_LT' => 'Operatorius'],
        'manager' => ['lv_LV' => 'Vadītājs', 'ru_RU' => 'Менеджер', 'en_US' => 'Manager', 'lt_LT' => 'Vadovas'],
    ];

    public function __construct(private EntityManager $entityManager)
    {}

    private static function lvl(string $c, string $r, string $e, string $d): array
    {
        return ['create' => $c, 'read' => $r, 'edit' => $e, 'delete' => $d, 'stream' => 'no'];
    }

    /** @return array<string, mixed> scope => rights */
    public static function rights(string $kind): array
    {
        $d = [
            // the counter
            'WashOrder' => self::lvl('yes', 'all', 'all', 'no'),
            'Customer' => self::lvl('yes', 'all', 'all', 'no'),
            'Vehicle' => self::lvl('yes', 'all', 'all', 'no'),
            'Driver' => self::lvl('yes', 'all', 'all', 'no'),
            'OrderWork' => self::lvl('yes', 'all', 'all', 'no'),
            'OrderAddon' => self::lvl('yes', 'all', 'all', 'no'),
            'OrderPhoto' => self::lvl('yes', 'all', 'no', 'no'),
            'OrderMessage' => self::lvl('no', 'all', 'no', 'no'),
            'Subscription' => self::lvl('yes', 'all', 'no', 'no'),
            'SubscriptionUse' => self::lvl('no', 'all', 'no', 'no'),
            'Shift' => self::lvl('yes', 'all', 'no', 'no'),
            // read-only: the staff list (Shifts dashlet), the catalogue, stock levels
            'Employee' => self::lvl('no', 'all', 'no', 'no'),
            'WashService' => self::lvl('no', 'all', 'no', 'no'),
            'Work' => self::lvl('no', 'all', 'no', 'no'),
            'WorkRole' => self::lvl('no', 'all', 'no', 'no'),
            'ServiceWork' => self::lvl('no', 'all', 'no', 'no'),
            'ServiceSupply' => self::lvl('no', 'all', 'no', 'no'),
            'Translation' => self::lvl('no', 'all', 'no', 'no'),
            'Supply' => self::lvl('no', 'all', 'no', 'no'),
            // the order calendar (#Calendar, nav entry; the data behind it still needs read: all on WashOrder)
            'Calendar' => true,
            // the gate on the board
            'PlateEvent' => self::lvl('no', 'all', 'all', 'no'),
            'Camera' => self::lvl('no', 'all', 'no', 'no'),
        ];

        if ($kind === 'manager') {
            $d['WashOrder'] = self::lvl('yes', 'all', 'all', 'all');
            $d['Customer'] = self::lvl('yes', 'all', 'all', 'all');
            $d['Employee'] = self::lvl('no', 'all', 'all', 'no');
            $d['Subscription'] = self::lvl('yes', 'all', 'all', 'no');
            // a client may ask for a photo of their car to be erased: the manager can remove it for real (the record AND the file, Hooks/OrderPhoto/RemoveFile)
            $d['OrderPhoto'] = self::lvl('yes', 'all', 'no', 'all');

            foreach (['Supply', 'SupplyReceipt', 'SupplyReceiptLine', 'Stocktake', 'StocktakeLine', 'Mailing', 'MailingRecipient'] as $s) {
                $d[$s] = self::lvl('yes', 'all', 'all', 'no');
            }

            $d['SupplyMovement'] = self::lvl('no', 'all', 'no', 'no');
            // promo codes (module loyalty): the manager makes and switches them; the operator only TYPES a code (the server checks it)
            $d['PromoCode'] = self::lvl('yes', 'all', 'all', 'no');
            $d['PromoRedemption'] = self::lvl('no', 'all', 'no', 'no');
            // the points journal (read only): the manager sees every change of a customer's points
            $d['LoyaltyLedger'] = self::lvl('no', 'all', 'no', 'no');
            $d['RevenueReports'] = true;
            // may book a reservation over the capacity of the slot / the day, knowingly (flagged on the order; docs/BOOKING.md)
            $d['CapacityOverride'] = true;
        }

        return $d;
    }

    /**
     * @return array<string, string> kind => 'created' | 'exists' | 'updated'
     */
    public function seed(string $lang = 'lv_LV', bool $update = false): array
    {
        $out = [];

        foreach (array_keys(self::NAMES) as $kind) {
            $role = $this->find($kind);
            $data = (object) array_map(fn ($v) => is_array($v) ? (object) $v : $v, self::rights($kind));

            if ($role) {
                if ($update) {
                    $role->set('data', $data);
                    $this->entityManager->saveEntity($role);
                    $out[$kind] = 'updated';
                } else {
                    $out[$kind] = 'exists';
                }

                continue;
            }

            $this->entityManager->createEntity('Role', [
                'name' => self::NAMES[$kind][$lang] ?? self::NAMES[$kind]['en_US'],
                'data' => $data,
                'fieldData' => (object) [],
                'assignmentPermission' => 'no',
                'userPermission' => 'no',
                'messagePermission' => 'no',
                'portalPermission' => 'no',
                'groupEmailAccountPermission' => 'no',
                'exportPermission' => $kind === 'manager' ? 'yes' : 'no',
                'massUpdatePermission' => 'no',
                'dataPrivacyPermission' => 'no',
                'followerManagementPermission' => 'no',
                'auditPermission' => 'no',
                'mentionPermission' => 'no',
                'userCalendarPermission' => 'no',
            ]);
            $out[$kind] = 'created';
        }

        return $out;
    }

    private function find(string $kind): ?Entity
    {
        return self::findRole($this->entityManager, $kind);
    }

    /** The seeded role of this kind by any of its three localised names, or null. */
    public static function findRole(EntityManager $entityManager, string $kind): ?Entity
    {
        return $entityManager->getRDBRepository('Role')->where(['name' => array_values(self::NAMES[$kind])])->findOne();
    }
}
