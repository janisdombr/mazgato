<?php

namespace Espo\Custom\Tools\Assistant\Tools;

use Espo\Custom\Tools\Assistant\Toolset;
use Espo\Custom\Tools\Assistant\Untrusted;

/**
 * The ONLY thing the assistant can "do": prepare a filled-in form. Nothing is written here —
 * the result carries the form's attributes (to the operator's screen, under `_client`; the model
 * sees a summary without names) and the operator opens the ordinary CRM form, checks it and
 * presses Save, so every hook and guard (ReserveGuard, consent rules…) runs exactly as for a
 * hand-typed record. There is deliberately no create/update/delete tool anywhere in the registry.
 */
class DraftTools extends Toolset
{
    public function specs(): array
    {
        return [[
            'name' => 'draft',
            'description' => 'Prepare a pre-filled form for the user to open, check and save themselves. You cannot create or change anything: this only fills a form. kind=mailing prepares a Draft mailing (the audience is built by the user afterwards with "Build recipients"; describe who it is for in `audience`). kind=reservation prepares a reservation (status Reserved) for a plate, service and time. Say plainly that nothing is saved yet.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['mailing', 'reservation']],
                'title' => ['type' => 'string', 'description' => 'mailing: short name of the campaign.'],
                'channel' => ['type' => 'string', 'enum' => ['Email', 'WhatsApp', 'Phone'], 'description' => 'mailing channel.'],
                'segment' => ['type' => 'string', 'enum' => ['All', 'Corporate', 'Individual']],
                'subject' => ['type' => 'string', 'description' => 'mailing: e-mail subject.'],
                'body' => ['type' => 'string', 'description' => 'mailing: message text; placeholders {name} {discount} {offer} {unsubscribe} are filled per recipient. Plain text.'],
                'offer' => ['type' => 'string', 'description' => 'mailing: the offer in one line.'],
                'discountPercent' => ['type' => 'integer', 'description' => 'mailing: 0-100.'],
                'audience' => ['type' => 'string', 'description' => 'mailing: who it is for (e.g. "not seen for 60+ days"), for the description.'],
                'plate' => ['type' => 'string', 'description' => 'reservation: plate number.'],
                'serviceId' => ['type' => 'string', 'description' => 'reservation: id from the services tool.'],
                'when' => ['type' => 'string', 'description' => 'reservation: local date and time, YYYY-MM-DD HH:MM, in the future.'],
                'note' => ['type' => 'string', 'description' => 'reservation: note.'],
            ], 'required' => ['kind'], 'additionalProperties' => false],
        ]];
    }

    public function run(string $name, array $args): array
    {
        if ($name !== 'draft') {
            return $this->fail(404, 'unknown tool');
        }

        return match ($args['kind'] ?? '') {
            'mailing' => $this->mailing($args),
            'reservation' => $this->reservation($args),
            default => $this->fail(400, 'kind must be mailing or reservation.'),
        };
    }

    private function text(mixed $v, int $max): string
    {
        return mb_substr(trim(strip_tags((string) $v)), 0, $max);
    }

    private function mailing(array $a): array
    {
        if (!$this->ctx->acl->checkScope('Mailing', 'create')) {
            return $this->denied('creating mailings');
        }

        $channel = in_array($a['channel'] ?? 'Email', ['Email', 'WhatsApp', 'Phone'], true) ? ($a['channel'] ?? 'Email') : 'Email';
        $segment = in_array($a['segment'] ?? 'All', ['All', 'Corporate', 'Individual'], true) ? ($a['segment'] ?? 'All') : 'All';
        $disc = isset($a['discountPercent']) && is_numeric($a['discountPercent']) ? max(0, min(100, (int) $a['discountPercent'])) : null;
        $name = $this->text($a['title'] ?? '', 120) ?: 'Draft';

        $attrs = array_filter([
            'name' => $name,
            'channel' => $channel,
            'segment' => $segment,
            'status' => 'Draft',
            'subject' => $this->text($a['subject'] ?? '', 200),
            'body' => $this->text($a['body'] ?? '', 3000),
            'offer' => $this->text($a['offer'] ?? '', 300),
            'discountPercent' => $disc,
            'description' => $this->text($a['audience'] ?? '', 300),
        ], fn($v) => $v !== null && $v !== '');

        return [
            'ok' => true,
            'saved' => false,
            'note' => 'Nothing was saved. The user must open the form, check it and press Save. The audience is built afterwards with "Build recipients".',
            'summary' => 'Mailing draft "' . Untrusted::clean($name, 80) . '" (' . $channel . ', ' . $segment . ($disc !== null ? ', ' . $disc . '%' : '') . ')',
            '_client' => ['draft' => [
                'entityType' => 'Mailing', 'action' => 'create', 'attributes' => $attrs,
                'summary' => $name,
            ]],
        ];
    }

    private function reservation(array $a): array
    {
        if (!$this->ctx->acl->checkScope('WashOrder', 'create')) {
            return $this->denied('creating orders');
        }

        $when = trim((string) ($a['when'] ?? ''));
        $tz = new \DateTimeZone($this->ctx->cfg->timeZone());
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $when, $tz);

        if (!$d || $d->format('Y-m-d H:i') !== $when) {
            return $this->fail(400, 'when must be local "YYYY-MM-DD HH:MM".');
        }

        if ($d->getTimestamp() < time()) {
            return $this->fail(400, 'A reservation must be in the future.');
        }

        $em = $this->ctx->em;
        $attrs = [
            'status' => 'Reserved', 'source' => 'Phone',
            'orderDateTime' => $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
        $summary = 'Reservation draft ' . $when;

        $plate = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($a['plate'] ?? '')));

        if ($plate !== '') {
            $v = $em->getRDBRepository('Vehicle')->where(['name' => $plate, 'deleted' => false])->findOne();

            if ($v && $this->ctx->canSee($v)) {
                $attrs['vehicleId'] = $v->getId();
                $attrs['vehicleName'] = (string) $v->get('name');

                if ($v->get('customerId')) {
                    $c = $em->getEntityById('Customer', (string) $v->get('customerId'));

                    if ($c && $this->ctx->canSee($c)) {
                        $attrs['customerId'] = $c->getId();
                        $attrs['customerName'] = (string) $c->get('name');
                    }
                }
            }

            $summary .= ' for plate ' . $plate . ($v ? '' : ' (not in the system yet - the user picks or creates the car)');
        }

        if (!empty($a['serviceId'])) {
            $s = $em->getEntityById('WashService', (string) $a['serviceId']);

            if (!$s || !$s->get('isActive')) {
                return $this->fail(404, 'No such active service; use an id from the services tool.');
            }

            $attrs['serviceId'] = $s->getId();
            $attrs['serviceName'] = (string) $s->get('name');
            $summary .= ', ' . Untrusted::clean((string) $s->get('name'), 50);
        }

        if (!empty($a['note'])) {
            $attrs['reservationNote'] = $this->text($a['note'], 500);
        }

        return [
            'ok' => true,
            'saved' => false,
            'note' => 'Nothing was saved. The user must open the form, check it and press Save; the slot-capacity check runs only then and may refuse it.',
            'summary' => $summary,
            '_client' => ['draft' => [
                'entityType' => 'WashOrder', 'action' => 'create', 'attributes' => $attrs,
                // language-neutral line for the operator's screen (the panel adds the translated title)
                'summary' => trim($when . ($plate !== '' ? ' · ' . $plate : '') . (!empty($attrs['serviceName']) ? ' · ' . Untrusted::clean((string) $attrs['serviceName'], 50) : '')),
            ]],
        ];
    }
}
