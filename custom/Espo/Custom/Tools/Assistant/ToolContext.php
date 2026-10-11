<?php

namespace Espo\Custom\Tools\Assistant;

use Espo\Core\Acl;
use Espo\Core\InjectableFactory;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Everything a tool may touch, bound to the ASKING user: their Acl, their pseudonym map. */
class ToolContext
{
    public function __construct(
        public EntityManager $em,
        public Acl $acl,
        public User $user,
        public AssistantConfig $cfg,
        public Pseudonymiser $ps,
        public InjectableFactory $factory
    ) {}

    /** The user may read EVERYTHING of this scope (aggregates cannot be row-filtered). */
    public function canAll(string $scope): bool
    {
        return $this->user->isAdmin() || $this->acl->getLevel($scope, 'read') === 'all';
    }

    /** The role's "Revenue reports and statistics" switch (scope RevenueReports), the same gate as CarWash::requireRevenueReports. */
    public function canRevenue(): bool
    {
        return $this->user->isAdmin() || $this->acl->checkScope('RevenueReports');
    }

    public function canRead(string $scope): bool
    {
        return $this->acl->checkScope($scope, 'read');
    }

    public function canSee(Entity $e): bool
    {
        return $this->acl->checkEntityRead($e);
    }

    public function fieldOk(string $scope, string $field): bool
    {
        return $this->user->isAdmin() || $this->acl->checkField($scope, $field, 'read');
    }

    /** Local calendar day bounds -> UTC database strings. */
    public function utc(string $localDateTime): string
    {
        $tz = new \DateTimeZone($this->cfg->timeZone());

        return (new \DateTimeImmutable($localDateTime, $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function localDate(?string $utc, string $format = 'Y-m-d H:i'): ?string
    {
        if (!$utc) {
            return null;
        }

        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone($this->cfg->timeZone()))->format($format);
    }

    public function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone($this->cfg->timeZone())))->format('Y-m-d');
    }

    /**
     * How a customer is shown to the model: companies by (wrapped) name, private persons as K-n.
     * Names of private persons only when the installation allows it AND the role can read the field.
     *
     * @return array<string, mixed>
     */
    public function customerRef(Entity $c, bool $wantName = false): array
    {
        $out = ['customer' => $this->ps->alias($c->getId())];

        if ($c->get('isCorporate')) {
            $out['company'] = Untrusted::wrap((string) $c->get('name'));
        } elseif ($wantName && $this->cfg->piiLevel() !== 'aggregate' && $this->fieldOk('Customer', 'name')) {
            $out['name'] = Untrusted::wrap((string) $c->get('name'));
        }

        return $out;
    }
}
