<?php

namespace Espo\Custom\Hooks\Email;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Turn an inbound reservation email into a Reserved WashOrder.
 *
 * Activation: configure Inbound Email (Administration → Inbound Emails) for the
 * bookings mailbox. This hook fires when a received email's subject starts with a
 * reservation keyword (so it never touches ordinary mail). It matches the customer
 * by sender email / phone / plate, or creates one, and records a Reserved order
 * with source = Email. Any failure is swallowed so mail intake is never blocked.
 */
class CreateReservation
{
    public static $order = 20;

    /** Subject must start with one of these (case-insensitive) to be treated as a booking. */
    private const KEYWORDS = ['REZ', 'RĒĶ', 'RESERV', 'BOOK', 'ЗАПИС', 'РЕЗЕРВ', 'PIETEIK'];

    public function __construct(private EntityManager $entityManager)
    {}

    public function afterSave(Entity $email, array $options = []): void
    {
        try {
            $this->process($email);
        } catch (Throwable $e) {
            $GLOBALS['log']->warning('CreateReservation hook failed: ' . $e->getMessage());
        }
    }

    private function process(Entity $email): void
    {
        if (!$email->isNew()) {
            return;
        }

        // Received emails only.
        if ($email->get('status') !== 'Archived') {
            return;
        }

        $subject = trim((string) $email->get('name'));

        if (!$this->isReservation($subject)) {
            return;
        }

        $em = $this->entityManager;

        $text = $subject . ' ' . strip_tags((string) ($email->get('bodyPlain') ?: $email->get('body')));

        $plate = $this->extractPlate($text);
        $phone = $this->extractPhone($text);
        $fromEmail = (string) $email->get('fromEmailAddress');

        // A reservation needs a car; without a plate in the mail there is nothing to book (and no customer is made).
        if ($plate === '') {
            return;
        }

        // Match a customer: by plate → vehicle → customer, else by email, else by phone.
        $customer = null;
        $vehicle = null;

        if ($plate !== '') {
            $vehicle = $em->getRDBRepository('Vehicle')->where(['name' => $plate])->findOne();

            if ($vehicle && $vehicle->get('customerId')) {
                $customer = $em->getEntityById('Customer', $vehicle->get('customerId'));
            }
        }

        if (!$customer && $fromEmail !== '') {
            $customer = $em->getRDBRepository('Customer')->where(['email' => $fromEmail])->findOne();
        }

        if (!$customer && $phone !== '') {
            $customer = $em->getRDBRepository('Customer')->where(['phone' => $phone])->findOne();
        }

        if (!$customer) {
            // The payer needs a name: the sender's display name, else the address itself (it identifies them).
            $senderName = trim((string) preg_replace('/<[^>]*>/', '', (string) $email->get('fromString')), " \t\n\r\"'");
            $payer = $senderName !== '' ? $senderName : $fromEmail;

            if ($payer === '') {
                return; // nobody to bill and no way to reach: no order is made
            }

            $customer = $em->createEntity('Customer', [
                'name' => $payer,
                'email' => $fromEmail ?: null,
                'phone' => $phone ?: null,
            ]);
        }

        if (!$vehicle && $plate !== '') {
            $vehicle = $em->createEntity('Vehicle', [
                'name' => $plate,
                'customerId' => $customer->getId(),
            ]);
        }

        $em->createEntity('WashOrder', [
            'name' => 'RES-' . date('ymd-His'),
            'status' => 'Reserved',
            'source' => 'Email',
            'orderDateTime' => date('Y-m-d H:i:s'),
            'customerId' => $customer->getId(),
            'vehicleId' => $vehicle->getId(),
            'finalPrice' => 0,
            'reservationNote' => mb_substr($subject, 0, 250),
            'isCorporate' => (bool) $customer->get('isCorporate'),
        ]);
    }

    private function isReservation(string $subject): bool
    {
        $upper = mb_strtoupper($subject);

        foreach (self::KEYWORDS as $kw) {
            if (str_starts_with($upper, $kw)) {
                return true;
            }
        }

        return false;
    }

    private function extractPlate(string $text): string
    {
        if (preg_match('/\b([A-Za-z]{1,3}[- ]?\d{1,4}|\d{1,4}[- ]?[A-Za-z]{1,3})\b/u', $text, $m)) {
            return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $m[1]));
        }

        return '';
    }

    private function extractPhone(string $text): string
    {
        if (preg_match('/(\+?\d[\d\s\-]{6,}\d)/', $text, $m)) {
            return preg_replace('/\s/', '', $m[1]);
        }

        return '';
    }
}
