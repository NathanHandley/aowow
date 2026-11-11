<?php

if (!defined('AOWOW_REVISION'))
    die('illegal access');


// ROWOW - Ragnarok character builder: job path, job and base levels, stats and skills, kept in the URL hash
// tabId 1: Tools g_initHeader()
class RocalcPage extends GenericPage
{
    protected $tpl           = 'rocalc';
    protected $tabId         = 1;
    protected $path          = [1, 9];
    protected $mode          = CACHE_TYPE_NONE;
    protected $gDataKey      = true;
    protected $scripts       = array(
        [SC_JS_FILE,  '?data=ro-builder'],
        [SC_JS_FILE,  'js/RoBuilder.js'],
        [SC_CSS_FILE, 'css/robuilder.css']
    );

    public function __construct($pageCall, $__)
    {
        parent::__construct($pageCall, $__);

        $this->name = Lang::main('roCalc');
    }

    protected function generateContent()
    {
    }

    protected function generateTitle()
    {
        array_unshift($this->title, $this->name);
    }

    protected function generatePath()
    {
    }
}

?>
