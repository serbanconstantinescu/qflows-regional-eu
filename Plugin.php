<?php

/*
    RegionalEU: the steps of the European Union's public services, for the installations that serve EU accounts (one plugin per country or region, as RegionalRO: kept in
    its own repository, cloned into app/plugins/, turned on in plugins.json; plugins.json.example: this plugin's entry as shipped).
    Its steps are registered here (\V::app()->om->registerObjects: `wfNode`), as the core's are in data\SysObjects; they are in the designer's palette of every account of the
    installation (no tier feature), in the group Regional. All public: no credential.
      models/node/          the steps (NodeAppEu their base)
	  NodeAppEuVies         VIES VAT Check: EU VAT numbers checked in the member states' registers (with a consultation number, trader matching); VIES' availability
	  NodeAppEuEcb          ECB Exchange Rates: the euro reference rates of a day, a conversion, the rates between two days
	  NodeAppEuTed          TED Tenders: the EU's public procurement notices (search, one notice, its PDF / XML in the project's Files)
	  NodeTriggerEuTed      TED notice: a run for each new notice the filters find (polled on a schedule)
      util/                 Vies, Ecb, Ted (the services' clients)
    the steps as the tools of AI steps (the core's util\AgentTools, hook agent:families; public families: chosen with `<family>Credential` = use): euVies (eu_vies_check,
    eu_vies_status), euEcb (eu_ecb_rates, eu_ecb_convert, eu_ecb_history), euTed (eu_ted_search, eu_ted_get, eu_ted_download)
    this plugin's checks in the core's check suite (commands\CheckCommand, hook check:suites: regionaleu; checks.php)
*/

namespace plugins\RegionalEU;

class Plugin {
    public function __construct() {
	\V::app()->om->registerObjects([
	    'NodeAppEuVies' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppEuVies',
		'wfNode' => true,
	    ],
	    'NodeAppEuEcb' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppEuEcb',
		'wfNode' => true,
	    ],
	    'NodeAppEuTed' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppEuTed',
		'wfNode' => true,
	    ],
	    'NodeTriggerEuTed' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeTriggerEuTed',
		'wfNode' => true,
	    ],
	]);

	//the families of the AI steps' tools
	\V::app()->hooks->addHook('agent:families', function(\ArrayObject $families) {
	    $families['euVies'] = [ 'label' => 'VIES VAT Check', 'prefix' => 'eu_vies', 'credential' => false, 'steps' => [ models\node\NodeAppEuVies::class ] ];
	    $families['euEcb'] = [ 'label' => 'ECB Exchange Rates', 'prefix' => 'eu_ecb', 'credential' => false, 'steps' => [ models\node\NodeAppEuEcb::class ] ];
	    $families['euTed'] = [ 'label' => 'TED Tenders', 'prefix' => 'eu_ted', 'credential' => false, 'steps' => [ models\node\NodeAppEuTed::class ] ];
	});

	if (\V::app()->config('app.mode') != 'web')
	    \V::app()->hooks->addHook('check:suites', function(\ArrayObject $suites) {
		foreach(require(__DIR__ . '/checks.php') as $name => $fn)
		    $suites[$name] = $fn;
	    });
    }
}
