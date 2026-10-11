<?php

namespace Espo\Custom\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\ORM\EntityManager;

/**
 * Public, no-auth unsubscribe endpoint reached from a mailing's unsubscribe link:
 *
 *   {crmHost}/?entryPoint=mailingUnsubscribe&id={unsubscribeToken}
 *
 * The unsubscribe TOKEN is the authorization — it is unguessable and per-customer, so no
 * login is needed. Knowing the token means the request came from that customer's own email.
 * Turning consent off never fails a delivery, so the worst case of a leaked token is that
 * someone stops one customer's marketing email — not a data risk.
 *
 * Called by the landing page (`landing/unsubscribe.html`) via a cross-origin `fetch`, so the
 * response is JSON with an open CORS header. GET is deliberate: the email itself links to the
 * static landing page, not here, so mail scanners/prefetchers never trigger the opt-out — only
 * an explicit click on the landing "Unsubscribe" button reaches this endpoint.
 *
 * @noinspection PhpUnused
 */
class MailingUnsubscribe implements EntryPoint
{
    use NoAuth;

    public function __construct(private EntityManager $entityManager)
    {}

    public function run(Request $request, Response $response): void
    {
        $response->setHeader('Access-Control-Allow-Origin', '*');
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');

        $token = trim((string) ($request->getQueryParam('id') ?? ''));

        if ($token === '') {
            $response->setStatus(400);
            $response->writeBody(json_encode(['ok' => false, 'error' => 'no-token']));

            return;
        }

        $customer = $this->entityManager
            ->getRDBRepository('Customer')
            ->where(['unsubscribeToken' => $token, 'deleted' => false])
            ->findOne();

        if (!$customer) {
            // Do not reveal whether a token exists; report a neutral not-found.
            $response->setStatus(404);
            $response->writeBody(json_encode(['ok' => false, 'error' => 'not-found']));

            return;
        }

        $already = !$customer->get('allowEmail');

        if (!$already) {
            $customer->set('allowEmail', false);
            $customer->set('unsubscribedAt', date('Y-m-d H:i:s'));
            $this->entityManager->saveEntity($customer, ['skipHooks' => true]);
        }

        $response->writeBody(json_encode([
            'ok' => true,
            'alreadyUnsubscribed' => $already,
            'name' => (string) $customer->get('name'),
        ]));
    }
}
