<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Custom\Tools\Kiosk\DeviceGuard;
use Espo\Custom\Tools\Subscriptions\Subscriptions;
use stdClass;

/**
 * Subscription/action/*  (the entity API for plain CRUD is the template controller)
 *
 *  sell        { customerId, kind: Pack|Unlimited, washes?, validFrom?, validTo?, price, paymentMethod?, paid?, serviceId?, name? }
 *              -> the Subscription + the completed sale order with one priced line (the revenue)
 *  forCustomer { customerId, serviceId? }  -> subscriptions that can pay a wash today (intake hint)
 *  cancel      { id }  -> status Cancelled (the balance stays on record; no refund is made by the CRM)
 *
 * ACL: sell needs create on Subscription AND WashOrder; forCustomer needs read on the customer's subscriptions.
 */
class Subscription extends \Espo\Core\Templates\Controllers\Base
{
    /** A tablet's device key opens nothing here (same barrier as every other back-office controller). */
    private function guard(): void
    {
        $this->injectableFactory->create(DeviceGuard::class)->denyDevice($this->user);
    }

    public function postActionSell(Request $request): stdClass
    {
        $this->guard();
        $acl = $this->acl;

        if (!$acl->checkScope('Subscription', 'create') || !$acl->checkScope('WashOrder', 'create')) {
            throw new Forbidden();
        }

        $r = $this->subscriptions()->sell((array) $request->getParsedBody());

        return (object) [
            'subscriptionId' => $r['subscription']->getId(),
            'orderId' => $r['order']->getId(),
            'orderName' => $r['order']->get('name'),
            'price' => (float) $r['order']->get('finalPrice'),
            'subscription' => (object) $this->subscriptions()->summary($r['subscription']),
        ];
    }

    public function postActionForCustomer(Request $request): stdClass
    {
        $this->guard();

        if (!$this->acl->checkScope('Subscription', 'read')) {
            throw new Forbidden();
        }

        $d = $request->getParsedBody();

        if (empty($d->customerId)) {
            throw new BadRequest('customer_required');
        }

        // The balance of a customer's subscriptions is that customer's data: the caller must be allowed to read the customer.
        $customer = $this->subscriptions()->find('Customer', (string) $d->customerId);

        if (!$customer || !$this->acl->checkEntityRead($customer)) {
            throw new Forbidden();
        }

        return (object) ['list' => $this->subscriptions()->usableFor((string) $d->customerId, !empty($d->serviceId) ? (string) $d->serviceId : null)];
    }

    public function postActionCancel(Request $request): stdClass
    {
        $this->guard();

        if (!$this->acl->checkScope('Subscription', 'edit')) {
            throw new Forbidden();
        }

        $id = (string) ($request->getParsedBody()->id ?? '');
        $sub = $this->subscriptions()->find('Subscription', $id);

        // Scope level alone is not enough (edit may be "own"): the write below is a direct query, so the
        // permission on THIS record is checked here, before it.
        if ($sub && !$this->acl->checkEntityEdit($sub)) {
            throw new Forbidden();
        }

        $this->subscriptions()->cancel($id);

        return (object) ['ok' => true];
    }

    private function subscriptions(): Subscriptions
    {
        return $this->injectableFactory->create(Subscriptions::class);
    }
}
