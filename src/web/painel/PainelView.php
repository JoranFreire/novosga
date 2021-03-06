<?php
namespace painel;

use \core\SGAContext;
use \core\view\PageView;

/**
 * PainelView
 * 
 * @author rogeriolino
 */
class PainelView extends PageView {
    
    public function __construct() {
        parent::__construct(_('Painel'));
    }
    
    protected function basePath(SGAContext $context) {
        return dirname(__FILE__);
    }
    
}
