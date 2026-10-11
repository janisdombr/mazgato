<?php

namespace Espo\Custom\Services;

use Espo\Custom\Tools\Brand\Brand;
use Espo\ORM\Entity;

class WashOrder extends \Espo\Core\Templates\Services\Base
{
    /**
     * Called by Espo's PDF service just before a template is rendered. The client's header
     * and footer come from Settings (Administration → Brand) so the templates carry no
     * business name: they print {{{brandHeaderHtml}}} / {{{brandFooterHtml}}}.
     */
    public function loadAdditionalFieldsForPdf(Entity $entity): void
    {
        $brand = new Brand($this->config);

        $entity->set('brandHeaderHtml', $brand->headerHtml());
        $entity->set('brandFooterHtml', $brand->footerHtml());
    }
}
