/**
 * Plate number as a plate (D16): mono, boxed, letter-spaced — the main identifier of a
 * car on a wash. The list keeps its link to the record; editing is the stock varchar.
 */
define(['views/fields/varchar'], function (Dep) {

    var SPAN = '{{#if value}}<span class="cw-plate cw-plate--sm">{{value}}</span>{{/if}}';

    return Dep.extend({

        listTemplateContent: SPAN,
        detailTemplateContent: SPAN,
        listLinkTemplateContent: '<a href="#{{scope}}/view/{{model.id}}" class="link cw-plate cw-plate--sm" data-id="{{model.id}}" title="{{value}}">{{value}}</a>',
    });
});
