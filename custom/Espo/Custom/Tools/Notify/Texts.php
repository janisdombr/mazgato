<?php

namespace Espo\Custom\Tools\Notify;

/**
 * Service-message wording in the customer's language. The brand name comes from Settings and
 * is passed in; no business name lives here. Empty parts (no amount, no review link) simply
 * produce no line.
 */
class Texts
{
    public const LANGS = ['lv_LV', 'ru_RU', 'en_US', 'et_EE', 'lt_LT'];

    private const T = [
        'lv_LV' => [
            'Ready' => 'Jūsu auto %plate% ir gatavs, var braukt pakaļ.',
            'Booked' => 'Pieraksts apstiprināts: %plate%, %when%.',
            'Reminder' => 'Atgādinājums: jūsu pieraksts %when%, auto %plate%.',
            'Cancelled' => 'Pieraksts %when% (auto %plate%) ir atcelts.',
            'Test' => 'Testa ziņa.',
            'amount' => 'Summa: %amount% EUR',
            'review' => 'Paldies! Atstājiet atsauksmi: %url%',
            'manage' => 'Atcelt vai pārcelt pierakstu: %url%',
            'stop' => 'Atteikties no paziņojumiem: %url%',
            'subjectReady' => 'Auto %plate% ir gatavs',
            'subjectBooked' => 'Pieraksts apstiprināts',
            'subjectReminder' => 'Atgādinājums par pierakstu',
            'subjectCancelled' => 'Pieraksts atcelts',
            'subjectTest' => 'Testa ziņa',
        ],
        'ru_RU' => [
            'Ready' => 'Ваша машина %plate% готова, можно забирать.',
            'Booked' => 'Запись подтверждена: %plate%, %when%.',
            'Reminder' => 'Напоминание: ваша запись %when%, машина %plate%.',
            'Cancelled' => 'Запись %when% (машина %plate%) отменена.',
            'Test' => 'Тестовое сообщение.',
            'amount' => 'Сумма: %amount% EUR',
            'review' => 'Спасибо! Оставьте отзыв: %url%',
            'manage' => 'Отменить или перенести запись: %url%',
            'stop' => 'Отказаться от уведомлений: %url%',
            'subjectReady' => 'Машина %plate% готова',
            'subjectBooked' => 'Запись подтверждена',
            'subjectReminder' => 'Напоминание о записи',
            'subjectCancelled' => 'Запись отменена',
            'subjectTest' => 'Тестовое сообщение',
        ],
        'en_US' => [
            'Ready' => 'Your car %plate% is ready for pickup.',
            'Booked' => 'Booking confirmed: %plate%, %when%.',
            'Reminder' => 'Reminder: your booking is %when%, car %plate%.',
            'Cancelled' => 'The booking for %when% (car %plate%) was cancelled.',
            'Test' => 'Test message.',
            'amount' => 'Amount: %amount% EUR',
            'review' => 'Thank you! Leave a review: %url%',
            'manage' => 'Cancel or reschedule: %url%',
            'stop' => 'Stop these messages: %url%',
            'subjectReady' => 'Car %plate% is ready',
            'subjectBooked' => 'Booking confirmed',
            'subjectReminder' => 'Booking reminder',
            'subjectCancelled' => 'Booking cancelled',
            'subjectTest' => 'Test message',
        ],
        'et_EE' => [
            'Ready' => 'Teie auto %plate% on valmis, võite järele tulla.',
            'Booked' => 'Broneering kinnitatud: %plate%, %when%.',
            'Reminder' => 'Meeldetuletus: teie broneering on %when%, auto %plate%.',
            'Cancelled' => 'Broneering %when% (auto %plate%) on tühistatud.',
            'Test' => 'Testsõnum.',
            'amount' => 'Summa: %amount% EUR',
            'review' => 'Aitäh! Jätke arvustus: %url%',
            'manage' => 'Tühista või muuda broneeringut: %url%',
            'stop' => 'Loobu teavitustest: %url%',
            'subjectReady' => 'Auto %plate% on valmis',
            'subjectBooked' => 'Broneering kinnitatud',
            'subjectReminder' => 'Broneeringu meeldetuletus',
            'subjectCancelled' => 'Broneering tühistatud',
            'subjectTest' => 'Testsõnum',
        ],
        'lt_LT' => [
            'Ready' => 'Jūsų automobilis %plate% paruoštas, galite atvykti pasiimti.',
            'Booked' => 'Registracija patvirtinta: %plate%, %when%.',
            'Reminder' => 'Priminimas: jūsų registracija %when%, automobilis %plate%.',
            'Cancelled' => 'Registracija %when% (automobilis %plate%) atšaukta.',
            'Test' => 'Bandomoji žinutė.',
            'amount' => 'Suma: %amount% EUR',
            'review' => 'Ačiū! Palikite atsiliepimą: %url%',
            'manage' => 'Atšaukti arba perkelti registraciją: %url%',
            'stop' => 'Atsisakyti pranešimų: %url%',
            'subjectReady' => 'Automobilis %plate% paruoštas',
            'subjectBooked' => 'Registracija patvirtinta',
            'subjectReminder' => 'Priminimas apie registraciją',
            'subjectCancelled' => 'Registracija atšaukta',
            'subjectTest' => 'Bandomoji žinutė',
        ],
    ];

    public static function lang(?string $l, string $fallback): string
    {
        $l = (string) $l;

        if (in_array($l, self::LANGS, true)) {
            return $l;
        }

        return in_array($fallback, self::LANGS, true) ? $fallback : 'lv_LV';
    }

    /**
     * @param array<string, string> $v brand, plate, when, amount, reviewUrl, manageUrl, stopUrl
     * @return array{0:string,1:string} [subject, body]
     */
    public static function render(string $kind, string $lang, array $v): array
    {
        $t = self::T[$lang] ?? self::T['lv_LV'];
        $plate = $v['plate'] ?? '';
        $fill = fn(string $s, array $x = []) => strtr($s, ['%plate%' => $plate, '%when%' => $v['when'] ?? ''] + $x);

        $lines = [];
        $brand = trim($v['brand'] ?? '');
        $lines[] = ($brand !== '' ? $brand . ': ' : '') . $fill($t[$kind] ?? $t['Test']);

        if ($kind === 'Ready' && ($v['amount'] ?? '') !== '') {
            $lines[] = $fill($t['amount'], ['%amount%' => $v['amount']]);
        }

        if ($kind === 'Ready' && ($v['reviewUrl'] ?? '') !== '') {
            $lines[] = $fill($t['review'], ['%url%' => $v['reviewUrl']]);
        }

        if (in_array($kind, ['Booked', 'Reminder'], true) && ($v['manageUrl'] ?? '') !== '') {
            $lines[] = $fill($t['manage'], ['%url%' => $v['manageUrl']]);
        }

        if (($v['stopUrl'] ?? '') !== '') {
            $lines[] = $fill($t['stop'], ['%url%' => $v['stopUrl']]) . ' (STOP)';
        }

        $subject = ($brand !== '' ? $brand . ' — ' : '') . $fill($t['subject' . $kind] ?? $t['subjectTest']);

        return [$subject, implode("\n", $lines)];
    }
}
