<?php

namespace Espo\Custom\Tools\ClientAssistant;

use Espo\Custom\Tools\Booking\Planner;
use Espo\Custom\Tools\Brand\Brand;
use Espo\ORM\EntityManager;

/**
 * Everything the public chat may state as fact, assembled from the CRM's own settings:
 * brand name, address / phone / e-mail, the opening hours the booking planner really uses,
 * the active price list (RETAIL prices only), the booking rules, and the two texts the owner
 * writes (payments, FAQ). Nothing about customers, orders, revenue or staff is in here.
 *
 * Admin-written strings are data: angle brackets are neutralised so they cannot close the
 * block, and the prompt tells the model they are reference text, never instructions.
 */
class Knowledge
{
    private const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function __construct(private EntityManager $em, private Planner $planner, private Brand $brand, private ClientConfig $cfg, private \Espo\Custom\Tools\Licensing\Modules $modules, private \Espo\Custom\Tools\Loyalty\Promo $promo, private \Espo\Custom\Tools\Loyalty\Referral $referral)
    {}

    private function clean(string $s, int $max = 300): string
    {
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
        $s = str_replace(['<', '>'], ['‹', '›'], $s);

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    private function line(string $s, int $max = 200): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $this->clean($s, $max)));
    }

    public function businessName(): string
    {
        return $this->line($this->brand->docName() ?: $this->cfg->businessName(), 80);
    }

    public function address(): string
    {
        return $this->line($this->cfg->text('clientAssistantAddress', 200) ?: $this->cfg->text('brandDocAddress', 200), 200);
    }

    public function phone(): string
    {
        return $this->line($this->cfg->text('clientAssistantPhone', 40), 40);
    }

    /** A dialable href ("tel:+371…") or '' - digits and a leading plus only. */
    public function telHref(): string
    {
        $p = preg_replace('/[^\d+]/', '', $this->phone()) ?? '';

        return preg_match('/^\+?\d{6,15}$/', $p) ? 'tel:' . $p : '';
    }

    /** @return array<int, array<string, mixed>> */
    public function services(): array
    {
        $out = [];

        foreach ($this->em->getRDBRepository('WashService')->where(['isActive' => true, 'deleted' => false])->order('sortOrder')->find() as $s) {
            $out[] = [
                'id' => $s->getId(),
                'name' => $this->line((string) $s->get('name'), 80),
                'category' => $s->get('category') === 'Add-on' ? 'Add-on' : 'Base',
                'price' => round((float) $s->get('priceRetail'), 2),
                'minutes' => (int) $s->get('durationMinutes'),
                'description' => $this->line((string) $s->get('description'), 300),
            ];
        }

        return $out;
    }

    /** @return string[] one line per day for the next 7 days */
    public function hoursLines(): array
    {
        $out = [];
        $day = $this->planner->now()->setTimezone($this->planner->tz())->setTime(0, 0);

        for ($i = 0; $i < 7; $i++) {
            $d = $day->modify('+' . $i . ' days');
            $h = $this->planner->hoursFor($d);
            $out[] = $d->format('Y-m-d') . ' ' . self::DAYS[(int) $d->format('w')] . ': ' .
                ($h ? sprintf('%02d:%02d-%02d:%02d', intdiv($h[0], 60), $h[0] % 60, intdiv($h[1], 60), $h[1] % 60) : 'closed');
        }

        return $out;
    }

    public function bookingUrl(): string
    {
        return $this->cfg->siteUrl() === '' ? '' : $this->cfg->siteUrl() . '/booking/';
    }

    /** The knowledge block of the system prompt. */
    /** Which optional codes the booking can take now (the widget shows the matching form fields only then). @return array{promo: bool, referral: bool} */
    public function codes(): array
    {
        $on = $this->modules->moduleEnabled(\Espo\Custom\Tools\Licensing\Modules::LOYALTY);

        return ['promo' => $on && $this->promo->anyPublic(), 'referral' => $on && $this->referral->enabled()];
    }

    public function block(): string
    {
        $l = [];
        $l[] = 'Business name: ' . ($this->businessName() ?: '(not set)');
        $l[] = 'Address: ' . ($this->address() ?: '(not set - say you do not know and suggest phoning)');
        $l[] = 'Phone: ' . ($this->phone() ?: '(not set)');

        $mail = $this->line($this->cfg->text('clientAssistantEmail', 100), 100);
        $l[] = 'E-mail: ' . ($mail ?: '(not set)');
        $l[] = 'Opening hours, next 7 days (local time; "closed" = shut):';

        foreach ($this->hoursLines() as $h) {
            $l[] = '  ' . $h;
        }

        $l[] = 'Online booking: at least ' . $this->planner->lead() . ' minutes ahead and up to ' . $this->planner->horizonDays() . ' days ahead; the online booking form is ' . ($this->bookingUrl() ?: '(address not set)');

        $pay = $this->cfg->text('clientAssistantPayments', 600);
        $l[] = 'Payment: ' . ($pay !== '' ? $this->clean($pay, 600) : '(not stated - say you do not know and suggest phoning)');

        $l[] = 'Price list (EUR, retail; "Base" = a wash, "Add-on" = an extra on top of a wash; minutes = how long it takes):';

        foreach ($this->services() as $s) {
            $l[] = sprintf('  id=%s | %s | %s | %.2f EUR | %d min%s', $s['id'], $s['name'], $s['category'], $s['price'], $s['minutes'],
                $s['description'] !== '' ? ' | ' . $s['description'] : '');
        }

        // Only while the loyalty module is on and some promo code could work. No code is ever listed here.
        if ($this->modules->moduleEnabled(\Espo\Custom\Tools\Licensing\Modules::LOYALTY) && $this->promo->anyPublic()) {
            $l[] = 'Promo codes: a customer may give a promo code when booking (pass it to create_booking as promo). Never list, suggest or guess codes and never promise a discount: the booking system decides whether a code works and you cannot know.';
        }

        if ($this->modules->moduleEnabled(\Espo\Custom\Tools\Licensing\Modules::LOYALTY) && $this->referral->enabled()) {
            $l[] = 'Referral codes: a customer may give a friend\'s referral code when booking (pass it to create_booking as referral). Never list, suggest or guess codes and never promise a reward: the booking system decides and you cannot know.';
        }

        $faq = $this->cfg->text('clientAssistantFaq', 4000);
        $l[] = 'Frequently asked questions and house rules (written by the owner):';
        $l[] = $faq !== '' ? $this->clean($faq, 4000) : '  (none written)';

        return implode("\n", $l);
    }
}
