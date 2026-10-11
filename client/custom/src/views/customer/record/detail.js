/**
 * Customer detail view.
 *
 * The core "Print to PDF" action is switched off here on purpose: the only Customer
 * template we ship is the MONTHLY invoice, which needs a period and spans many orders.
 * Rendered against a bare Customer record it produces an empty document, so the single
 * correct way to print it is the "Счёт за месяц" button (it asks for the period and the
 * template).
 */
define(['views/record/detail'], function (Dep) {

    return Dep.extend({

        printPdfAction: false,

    });
});
