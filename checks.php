<?php

/*
    the RegionalEU plugin's checks, run by the core's check suite (commands\CheckCommand, hook check:suites: Plugin): each a closure run as a method of the check command (->check(),
    ->fail(), ->account, ->project, ->runFlow(), ->startServer()), so the core is checked without this plugin.
      regionaleu   against the fake services tests/EuTestServer.php: VAT numbers split and their shapes (prefixes, GR as EL, a default country), VIES (valid with a consultation
                   number, not valid, a state's register not answering: not an error, a busy VIES asked again, the requester refused, the trader matched; the status), the
                   ECB's rates (the latest, a weekend: the day before, an old day: the whole history, a conversion through the euro, the rates between two days), TED (the
                   expert query of the filters, a notice made plain in a language, a search's pages, a notice by number in both forms, its PDF saved, TED's error), the TED
                   notice trigger (the first check notes today's, then the new ones once, the oldest first), the AI families (public, only Download writes)
*/

return [
    'regionaleu' => function() {
	$om = \V::app()->om;
	$V = \plugins\RegionalEU\util\Vies::class;
	$E = \plugins\RegionalEU\util\Ecb::class;
	$T = \plugins\RegionalEU\util\Ted::class;
	$TT = \plugins\RegionalEU\models\node\NodeTriggerEuTed::class;
	$n = fn($id, $fq, $config = []) => [ 'wfid' => $id, 'isNode' => true, 'parentId' => null, 'fqname' => $fq, 'title' => $id, 'geometry' => [ 'x' => 0, 'y' => 0, 'w' => 120, 'h' => 120 ], 'config' => $config ];
	$e = fn($id, $from, $to, $port = 0) => [ 'wfid' => $id, 'isEdge' => true, 'fromNode' => $from, 'fromPort' => $port, 'toNode' => $to, 'toPort' => 0, 'branch' => null ];

	// ---------------------------------------------------------------------------------- without the services
	$this->check('regionaleu: VAT numbers: a prefix, spaces and dots, GR as EL, the default country, a Dutch B, the wrong shape, not a VIES country', [
	    [ 'RO', '14399840' ], [ 'EL', '094014201' ], [ 'RO', '14399840' ], [ 'NL', '004495445B01' ], null, null ], [
	    $V::split('ro 1439.9840'), $V::split('GR094014201'), $V::split('14399840', 'RO'), $V::split('NL 0044 95445 B01'), $V::split('DE12345'), $V::split('US123456789') ]);
	$this->check('regionaleu: TED: the expert query of the filters; publication numbers (both forms, leading zeros); countries (two letters, three)', [
	    'FT~"road works" AND buyer-country IN (ROU DEU) AND classification-cpv IN (45233140) AND form-type IN (competition) AND publication-date>=20261101 AND (procedure-type=open) SORT BY publication-date DESC',
	    [ '787856-2026', '787856-2026', null ], [ 'ROU', 'GRC', 'ROU', null ] ], [
	    $T::query([ 'keywords' => [ 'road "works"' ], 'countries' => [ 'ROU', 'DEU' ], 'cpv' => [ '45233140' ], 'kinds' => [ 'competition' ], 'from' => '2026-11-01', 'more' => 'procedure-type=open' ]),
	    [ $T::number('2026/S 219-787856'), $T::number('00787856-2026'), $T::number('nope') ], [ $T::country('ro'), $T::country('EL'), $T::country('ROU'), $T::country('Romania') ] ]);
	$plain = $T::notice([ 'publication-number' => '1-2026', 'notice-title' => [ 'eng' => 'Road works', 'ron' => 'Lucrări de drumuri' ], 'buyer-name' => [ 'ron' => [ 'Primăria X', 'Primăria X' ] ],
	    'publication-date' => '2026-11-12+01:00', 'deadline-receipt-tender-date-lot' => [ '2026-12-15+02:00', '2026-12-10+02:00' ], 'estimated-value-lot' => [ '100.5', '200' ],
	    'estimated-value-cur-lot' => [ 'RON' ], 'classification-cpv' => [ '45233140', '45233140' ], 'description-lot' => [ 'eng' => 'Lot 1' ] ], 'ron');
	$this->check('regionaleu: a TED notice made plain: the title and buyer in the language (else English), the earliest deadline, the lots\' value summed, codes once, More fields', [
	    [ 'Lucrări de drumuri', 'Primăria X', '2026-11-12', '2026-12-10', 300.5, 'RON', [ '45233140' ], [ 'description-lot' => 'Lot 1' ] ], 'Road works' ], [
	    [ $plain['title'], $plain['buyer'], $plain['publicationDate'], $plain['deadline'], $plain['estimatedValue'], $plain['currency'], $plain['cpv'], $plain['fields'] ?? null ],
	    $T::notice([ 'notice-title' => [ 'eng' => 'Road works', 'ron' => 'x' ] ], 'deu')['title'] ]);
	$A = \util\AgentTools::class;
	$ops = array_merge($A::operations('euVies'), $A::operations('euEcb'), $A::operations('euTed'));
	$this->check('regionaleu: the AI families: public (no credential); the tools; only TED Download writes', [
	    [ false, false, false ], [ 'eu_vies_check', 'eu_vies_status', 'eu_ecb_rates', 'eu_ecb_convert', 'eu_ecb_history', 'eu_ted_search', 'eu_ted_get', 'eu_ted_download' ], [ 'eu_ted_download' ] ], [
	    array_map(fn($k) => $A::families()[$k]['credential'] ?? null, [ 'euVies', 'euEcb', 'euTed' ]), array_keys($ops), array_keys(array_filter($ops, fn($o) => !empty($o['writes']))) ]);

	// ---------------------------------------------------------------------------------- the fake services
	$dir = sys_get_temp_dir() . '/qflows-check-eu-' . uniqid();
	@mkdir($dir, 0700, true);
	$server = $this->startServer('regionaleu', '../../plugins/RegionalEU/tests/EuTestServer.php', $dir, [ 'EU_STATE' => $dir ]);
	if (!$server)
	    return;
	[ $port, $proc ] = $server;
	$base = "http://127.0.0.1:{$port}/";
	$V::$testing = "{$base}vies/";
	$E::$testing = "{$base}ecb/";
	$T::$testing = "{$base}ted/";
	(new \ReflectionProperty($E, 'files'))->setValue(null, []);
	$requests = function() use ($dir) {
	    $list = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("{$dir}/requests.jsonl"))));
	    @unlink("{$dir}/requests.jsonl");
	    return $list;
	};
	$files = [];
	$wfs = [];
	$started = [];
	try {
	    $step = function($fq, $cfg, $vars = []) use ($n, $e, $requests) {
		$requests();
		$exe = $this->runFlow("regionaleu {$fq}", [ $n('m', 'trigger::manual'), $n('s', $fq, $cfg + [ 'onError' => 'output' ]),
		    $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]), $e('e1', 'm', 's'), $e('e2', 's', 'err', 1) ], $vars);
		return [ $exe, $requests() ];
	    };
	    $logOf = function($exe, $prefix) {
		$line = null;
		foreach((array)$exe?->log as $l)
		    if (str_starts_with((string)$l['message'], $prefix))
			$line = (string)$l['message'];
		return preg_replace('/ \(\d+ ms\)$/', '', str_replace($prefix, '', (string)$line));
	    };

	    //VIES
	    file_put_contents("{$dir}/busy.json", '1');
	    [ $v1, $sent ] = $step('app::eu-vies', [ 'operation' => 'check', 'numbers' => 'RO14399840', 'requester' => 'RO 14399840', 'traderName' => 'Dante International', 'variable' => 'v' ]);
	    [ $v2, ] = $step('app::eu-vies', [ 'operation' => 'check', 'numbers' => [ '14399840', [ 'vat' => 'DE811115368' ], 'RO123', 'nonsense' ], 'country' => 'RO', 'variable' => 'v' ]);
	    [ $v3, ] = $step('app::eu-vies', [ 'operation' => 'check', 'numbers' => 'xx, yy', 'variable' => 'v' ]);
	    [ $v4, ] = $step('app::eu-vies', [ 'operation' => 'check', 'numbers' => 'RO14399840', 'requester' => 'RO000', 'variable' => 'v' ]);
	    [ $v5, ] = $step('app::eu-vies', [ 'operation' => 'status', 'variable' => 'v' ]);
	    $first = $v1?->getVariable('v')['first'] ?? [];
	    $this->check('regionaleu: VIES: valid (a busy VIES asked again), its name and address, the consultation number with a requester, the name matched; several (a default country, an object): '
		. 'one not valid, one state not answering (not an error), the texts not VAT numbers; none valid: an error; a wrong requester: an error; the status', [
		[ 2, true, 'DANTE INTERNATIONAL SA', "STR. GARA HERĂSTRĂU Nr. 6", 'WAPIAAAA-check', [ 'name' => true ], 'RO', '14399840' ],
		[ 3, false, [ true, null, false ], [ 'DE811115368' ], [ 'nonsense' ], 'MS_UNAVAILABLE' ], 'error', 'VIES: the requester\'s country or VAT number is not valid',
		[ true, [ 'DE' ], 'VIES answers, not available: DE' ] ], [
		[ count(array_filter($sent, fn($r) => $r['path'] === '/vies/check-vat-number')), $first['valid'] ?? null, $first['name'] ?? null, $first['address'] ?? null, $first['consultationNumber'] ?? null,
		    $first['match'] ?? null, $sent[0]['body']['requesterMemberStateCode'] ?? null, $sent[0]['body']['requesterNumber'] ?? null ],
		[ $v2?->getVariable('v')['count'] ?? null, $v2?->getVariable('v')['valid'] ?? null, array_column($v2?->getVariable('v')['items'] ?? [], 'valid'), $v2?->getVariable('v')['unavailable'] ?? null,
		    $v2?->getVariable('v')['invalid'] ?? null, $v2?->getVariable('v')['items'][1]['error'] ?? null ], $v3?->getVariable('route'), $logOf($v4, 'VIES VAT Check check: '),
		[ $v5?->getVariable('v')['available'] ?? null, $v5?->getVariable('v')['unavailable'] ?? null, $logOf($v5, 'VIES VAT Check status: ') ] ]);

	    //ECB
	    [ $r1, ] = $step('app::eu-ecb-rates', [ 'operation' => 'rates', 'variable' => 'r' ]);
	    [ $r2, ] = $step('app::eu-ecb-rates', [ 'operation' => 'rates', 'date' => '2026-11-08', 'currencies' => 'ron', 'variable' => 'r' ]);
	    [ $r3, $sent3 ] = $step('app::eu-ecb-rates', [ 'operation' => 'rates', 'date' => '2010-01-05', 'variable' => 'r' ]);
	    [ $r4, ] = $step('app::eu-ecb-rates', [ 'operation' => 'convert', 'amount' => '1 000,50', 'fromCurrency' => 'usd', 'toCurrency' => 'RON', 'decimals' => '2', 'variable' => 'r' ]);
	    [ $r5, ] = $step('app::eu-ecb-rates', [ 'operation' => 'history', 'from' => '2026-11-06', 'to' => '2026-11-11', 'currencies' => 'USD', 'variable' => 'r' ]);
	    [ $r6, ] = $step('app::eu-ecb-rates', [ 'operation' => 'rates', 'currencies' => 'XYZ', 'variable' => 'r' ]);
	    $this->check('regionaleu: ECB: the latest rates (EUR left out); a Sunday: the Friday before, one currency; an old day: the whole history read; a conversion through the euro; '
		. 'the days between two; a currency the ECB has not: an error', [
		[ '2026-11-12', [ 'USD' => 1.1081, 'RON' => 5.3473 ] ], [ '2026-11-06', [ 'RON' => 5.33 ] ], [ '2010-01-04', true ], [ 1000.5, 4828.06, '2026-11-12' ],
		[ [ '2026-11-06', '2026-11-11' ], [ 'USD' => 1.11 ] ], 'Currencies: the ECB has no rate of XYZ on 2026-11-12' ], [
		[ $r1?->getVariable('r')['date'] ?? null, $r1?->getVariable('r')['rates'] ?? null ], [ $r2?->getVariable('r')['date'] ?? null, $r2?->getVariable('r')['rates'] ?? null ],
		[ $r3?->getVariable('r')['date'] ?? null, in_array('/ecb/eurofxref-hist.xml', array_column($sent3, 'path'), true) ],
		[ $r4?->getVariable('r')['amount'] ?? null, $r4?->getVariable('r')['result'] ?? null, $r4?->getVariable('r')['date'] ?? null ],
		[ array_column($r5?->getVariable('r')['items'] ?? [], 'date'), $r5?->getVariable('r')['items'][1]['rates'] ?? null ], $logOf($r6, 'ECB Exchange Rates rates: ') ]);

	    //TED
	    $notices = [];
	    foreach([ [ '100-2026', '2026-11-10', 'ROU' ], [ '101-2026', '2026-11-11', 'DEU' ], [ '102-2026', '2026-11-11', 'ROU' ], [ '103-2026', '2026-11-12', 'ROU' ] ] as [ $num, $d, $c ])
		$notices[] = [ 'publication-number' => $num, 'publication-date' => "{$d}+01:00", 'buyer-country' => [ $c ], 'notice-title' => [ 'eng' => "Tender {$num}" ], 'buyer-name' => [ 'eng' => [ "Buyer {$c}" ] ],
		    'form-type' => 'competition', 'notice-type' => 'cn-standard' ];
	    file_put_contents("{$dir}/notices.json", json_encode($notices));
	    [ $t1, $sentT ] = $step('app::eu-ted', [ 'operation' => 'search', 'countries' => 'RO', 'from' => '2026-11-01', 'kinds' => [ 'competition' ], 'maxItems' => 2, 'variable' => 't' ]);
	    [ $t2, ] = $step('app::eu-ted', [ 'operation' => 'get', 'number' => '2026/S 220-102', 'variable' => 't' ]);
	    [ $t3, ] = $step('app::eu-ted', [ 'operation' => 'get', 'number' => '999-2026', 'variable' => 't' ]);
	    [ $t4, ] = $step('app::eu-ted', [ 'operation' => 'download', 'number' => '103-2026', 'format' => 'pdf', 'variable' => 't' ]);
	    if ($t4?->getVariable('t')['id'] ?? null)
		$files[] = $t4->getVariable('t')['id'];
	    [ $t5, ] = $step('app::eu-ted', [ 'operation' => 'search', 'query' => 'nosuch=1', 'variable' => 't' ]);
	    [ $t6, ] = $step('app::eu-ted', [ 'operation' => 'search', 'cpv' => '4523', 'variable' => 't' ]);
	    $saved = $t4?->getVariable('t')['id'] ?? null ? (string)$om->findOne('File', [ '_id' => $t4->getVariable('t')['id'] ])?->getContents() : null;
	    $this->check('regionaleu: TED: a search (the buyers\' country, published from, a kind; the newest first, At most 2 of 3, the fields asked); a notice by its OJ number; none: null; '
		. 'its PDF saved; TED\'s error said; a CPV code of the wrong shape refused', [
		[ [ '103-2026', '102-2026' ], 3, true, 'ACTIVE', true ], [ '102-2026', 'Tender 102-2026', 'Buyer ROU' ], [ null, 'TED has no notice 999-2026' ],
		[ 'ted-103-2026.pdf', "%PDF-1.4\nnotice 103-2026\n%%EOF" ], 'TED answered HTTP 400: Unknown search field \'nosuch\' found in expert query', 'CPV codes: not a CPV code (8 digits): `4523`' ], [
		[ array_column($t1?->getVariable('t')['items'] ?? [], 'publicationNumber'), $t1?->getVariable('t')['total'] ?? null, $t1?->getVariable('t')['truncated'] ?? null,
		    $sentT[0]['body']['scope'] ?? null, in_array('notice-title', (array)($sentT[0]['body']['fields'] ?? []), true) ],
		[ $t2?->getVariable('t')['publicationNumber'] ?? null, $t2?->getVariable('t')['title'] ?? null, $t2?->getVariable('t')['buyer'] ?? null ],
		[ $t3?->getVariable('t'), $logOf($t3, 'TED Tenders get: ') ], [ $t4?->getVariable('t')['filename'] ?? null, $saved ], $logOf($t5, 'TED Tenders search: '), $logOf($t6, 'TED Tenders search: ') ]);

	    //the TED notice trigger
	    $wf = $this->workflow('regionaleu ted', [ $n('t', 'trigger::eu-ted-notice', [ 'countries' => 'RO', 'kinds' => [ 'competition' ], 'runs' => 'each', 'variable' => 'notice',
		'mode' => 'interval', 'every' => 1, 'every_unit' => 'hours' ]), $n('s', 'app::set', [ 'variable' => 'got', 'value' => '{{ notice.publicationNumber }}' ]), $e('e1', 't', 's') ]);
	    $this->check('regionaleu: TED notice: the workflow is valid', true, $wf->validates());
	    $wf->save();
	    $this->created[] = $wf->_id;
	    $wfs[] = $wf;
	    $node = $wf->nodes['t'];
	    [ $d0, $none ] = $TT::check($wf, $node, [], '2026-11-11');
	    $notices[] = [ 'publication-number' => '104-2026', 'publication-date' => '2026-11-12+01:00', 'buyer-country' => [ 'ROU' ], 'notice-title' => [ 'eng' => 'Tender 104-2026' ] ];
	    $notices[] = [ 'publication-number' => '105-2026', 'publication-date' => '2026-11-12+01:00', 'buyer-country' => [ 'DEU' ], 'notice-title' => [ 'eng' => 'Tender 105-2026' ] ];
	    file_put_contents("{$dir}/notices.json", json_encode($notices));
	    [ $d1, $first ] = $TT::check($wf, $node, $d0, '2026-11-12');
	    [ $d2, $second ] = $TT::check($wf, $node, $d1, '2026-11-12');
	    $started = array_merge($started, $first, $second);
	    $this->check('regionaleu: TED notice: the first check notes the notices published since its day (102, 103); then the new ones of the filters once (104; not 105: DEU); '
		. 'checked again: none; since moves to the newest day, seen keeps its notices', [
		[ [], [ '102-2026', '103-2026' ], [ '104-2026' ], [], '2026-11-12', [ '103-2026', '104-2026' ] ] ], [
		[ $none, array_column($d0['seen'] ?? [], 0), array_map(fn($x) => $x->getVariable('notice')['publicationNumber'] ?? null, $first), $second, $d2['since'] ?? null, array_column($d2['seen'] ?? [], 0) ] ]);
	    $now = $TT::startNow($wf, $node);
	    $started = array_merge($started, $now['executions'] ?? []);
	    $this->check('regionaleu: TED notice: Run in the designer: the newest notice the filters find', 'Run with the newest notice: 104-2026 Tender 104-2026', $now['message'] ?? null);
	} finally {
	    $V::$testing = null;
	    $E::$testing = null;
	    $T::$testing = null;
	    (new \ReflectionProperty($E, 'files'))->setValue(null, []);
	    foreach($started as $x) {
		foreach($om->find('Job', [ 'job_spec' => '\\util\\Scheduler::start', 'job_data.exe' => (string)$x->_id ]) as $job)
		    $job->delete();
		$x->delete();
	    }
	    foreach($wfs as $wf)
		\models\TriggerState::beforeWorkflowDelete($wf);
	    foreach($files as $f)
		$om->findOne('File', [ '_id' => $f ])?->delete();
	    proc_terminate($proc);
	    proc_close($proc);
	    foreach(glob("{$dir}/*") as $f)
		@unlink($f);
	    @rmdir($dir);
	}
    },
];
