<?php

/*
    TED notice - starts the workflow for each new notice on TED (the EU's public procurement notices: calls for competition, awards, ...) that the filters find (the TED
    Tenders step's: keywords, buyers' countries, CPV codes, kinds, expert query). It searches on its schedule (models\wf\WfSchedule, every hour by default; TED publishes
    once each working day), public: no credential.
    What was started is kept in models\TriggerState, locked during a check: { since (YYYY-MM-DD), seen: [ [ publication number, date ] ] }:
	the first check notes the notices published today (they never start runs), each later one asks for publication-date >= since, the oldest first, and starts those not
	seen; since moves to the newest day done, seen keeps the numbers of that day and later
    At most MAX_RUNS per check (the others: the next check). The variable: the notice (util\Ted::notice), or { notices, count } per check
*/

namespace plugins\RegionalEU\models\node;

use plugins\RegionalEU\util\Ted;

class NodeTriggerEuTed extends \models\wf\WfNode {
    public static $type = 'trigger';
    public static $name = 'eu-ted-notice';
    public static $label = 'TED notice';
    public static $icon = 'bi-megaphone';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const RUNS = [ 'each' => 'One run per notice', 'batch' => 'One run per check with notices' ];
    const MAX_RUNS = 200;
    //the most notices kept as seen (a filter that finds more in a day than this starts some twice)
    const MAX_SEEN = 10000;
    const TIMEOUT = 120;

    public static function configSchema() {
	$fields = [];
	foreach(array_merge(NodeAppEuTed::filterFields([ 'search' ]), [ NodeAppEuTed::languageField([ 'search' ]) ]) as $f) {
	    unset($f['ops']);
	    $f['ui'] = array_diff_key($f['ui'] ?? [], [ 'expression' => true ]);
	    $fields[] = $f + [ 'default' => '' ];
	}
	foreach($fields as &$f)
	    if ($f['key'] === 'kinds')
		$f['default'] = [ 'competition' ];
	unset($f);
	return array_merge($fields, [
	    [
		'key' => 'runs',
		'type' => 'select',
		'label' => 'Runs',
		'required' => true,
		'default' => 'each',
		'choices' => self::RUNS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Notice variable',
		'required' => true,
		'default' => 'notice',
		'help' => 'e.g. {{ notice.title }}, {{ notice.buyer }}, {{ notice.deadline }}, {{ notice.url }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	], \models\wf\WfSchedule::schema([ 'mode' => 'interval', 'every' => 1, 'every_unit' => 'hours' ]));
    }

    public static function matches(array $cfg, \DateTimeInterface $t) {
	return \models\wf\WfSchedule::matches($cfg, $t);
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$error = \models\wf\WfSchedule::validate(is_array($this->config) ? $this->config : []);
	if ($error !== null) {
	    $this->errors('config', $error);
	    $valid = false;
	}
	try {
	    self::filtersOf(is_array($this->config) ? $this->config : []);
	} catch (\RuntimeException $e) {
	    $this->errors('config', 'TED notice: ' . $e->getMessage());
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'notice') : 'notice';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$notice = [ 'publicationNumber' => [ 'type' => 'string' ], 'title' => [ 'type' => 'string' ], 'buyer' => [ 'type' => 'string' ], 'buyerCountry' => [ 'type' => 'array' ],
	    'publicationDate' => [ 'type' => 'string' ], 'noticeType' => [ 'type' => 'string' ], 'formType' => [ 'type' => 'string' ], 'procedureType' => [ 'type' => 'string' ],
	    'contractNature' => [ 'type' => 'array' ], 'cpv' => [ 'type' => 'array' ], 'deadline' => [ 'type' => 'string', 'info' => 'YYYY-MM-DD, the earliest of its lots' ],
	    'estimatedValue' => [ 'type' => 'number' ], 'currency' => [ 'type' => 'string' ], 'placeOfPerformance' => [ 'type' => 'array' ],
	    'url' => [ 'type' => 'string', 'info' => 'the notice on ted.europa.eu' ], 'pdf' => [ 'type' => 'string' ], 'xml' => [ 'type' => 'string' ] ];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ notices, count }', 'fields' => [ 'notices' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => $notice ] ],
		'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the notice', 'fields' => $notice ] ];
    }

    //the filters of the trigger's settings (taken as they are: no {{ }} in a trigger)
    protected static function filtersOf(array $cfg) {
	$text = fn($k) => trim(is_scalar($cfg[$k] ?? '') ? (string)($cfg[$k] ?? '') : '');
	return NodeAppEuTed::filters($text, fn($k) => $cfg[$k] ?? '', (array)($cfg['kinds'] ?? []));
    }

    protected static function language(array $cfg) {
	return isset(Ted::LANGUAGES[(string)($cfg['language'] ?? 'eng')]) ? (string)$cfg['language'] : 'eng';
    }

    protected static function today() {
	return (new \DateTime('now', new \DateTimeZone(NodeAppEu::TZ)))->format('Y-m-d');
    }

    public static function runScheduled($wf, array $node, $at) {
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("TED notice: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
	    return;
	}
	try {
	    [ $data, $started ] = self::check($wf, $node, $state->dataArray());
	} catch (\Throwable $e) {
	    $state->release();
	    throw $e;
	}
	$state->finish($data);
	return $started;
    }

    //one check: [ the new state data, the executions started ]; $today: the day (YYYY-MM-DD; the checks)
    public static function check($wf, array $node, array $data, $today = null) {
	$cfg = (array)($node['config'] ?? []);
	$today = $today ?? self::today();
	$filters = self::filtersOf($cfg);
	$seen = [];
	foreach((array)($data['seen'] ?? []) as $s) {
	    $s = array_values((array)$s);
	    $seen[(string)$s[0]] = (string)$s[1];
	}
	if (empty($data['since'])) {
	    [ $raw ] = Ted::search(Ted::query($filters + [ 'from' => $today ], null), [ 'publication-number', 'publication-date' ], self::MAX_SEEN, 'ALL', self::TIMEOUT);
	    foreach($raw as $n)
		$seen[(string)$n['publication-number']] = Ted::day($n['publication-date'] ?? '') ?? $today;
	    return [ [ 'since' => $today, 'seen' => self::pairs($seen) ], [] ];
	}
	if (array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");
	$since = (string)$data['since'];
	[ $raw, $total ] = Ted::search(Ted::query($filters + [ 'from' => $since ], 'publication-date ASC'), array_keys(Ted::FIELDS),
	    min(Ted::MAX_RESULTS, self::MAX_RUNS + count($seen) + 100), 'ALL', self::TIMEOUT);
	$lang = self::language($cfg);
	$new = [];
	$done = $since;
	$more = false;
	foreach($raw as $n) {
	    $num = (string)($n['publication-number'] ?? '');
	    $day = Ted::day($n['publication-date'] ?? '') ?? $today;
	    if ($num === '' || isset($seen[$num]))
		continue;
	    if (count($new) >= self::MAX_RUNS) {
		$more = true;
		break;
	    }
	    $new[] = Ted::notice($n, $lang);
	    $seen[$num] = $day;
	    $done = max($done, $day);
	}
	//all read (not cut by MAX_RUNS or the pages read): the newest day seen is done
	if (!$more && count($raw) >= $total && $seen)
	    $done = max($done, max($seen));
	$seen = array_filter($seen, fn($d) => $d >= $done);
	if (count($seen) > self::MAX_SEEN)
	    $seen = array_slice($seen, -self::MAX_SEEN, null, true);
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'notice';
	$values = !$new ? [] : (($cfg['runs'] ?? 'each') === 'batch' ? [ [ 'notices' => $new, 'count' => count($new) ] ] : $new);
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'eu-ted-notice', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], $node['wfid']);
	    $exe->log($node['wfid'], isset($v['notices']) ? 'TED: ' . \util\Format::plural($v['count'], 'notice') : "TED notice {$v['publicationNumber']}: " . mb_substr((string)$v['title'], 0, 200));
	    $exe->queue();
	    $started[] = $exe;
	}
	return [ [ 'since' => $done, 'seen' => self::pairs($seen) ], $started ];
    }

    protected static function pairs(array $seen) {
	return array_map(fn($num, $d) => [ (string)$num, (string)$d ], array_keys($seen), array_values($seen));
    }

    //Run in the designer: one run with the newest notice the filters find; the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	try {
	    [ $raw ] = Ted::search(Ted::query(self::filtersOf($cfg)), array_keys(Ted::FIELDS), 1, 'ALL', 60);
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => 'TED notice: ' . $e->getMessage() ];
	}
	if (!$raw)
	    return [ 'executions' => [], 'message' => 'TED notice: no notice matches' ];
	$notice = Ted::notice($raw[0], self::language($cfg));
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'notice';
	$value = ($cfg['runs'] ?? 'each') === 'batch' ? [ 'notices' => [ $notice ], 'count' => 1 ] : $notice;
	$exe = \models\wf\WfExecution::startFor($wf, 'eu-ted-notice', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $value ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => "Run with the newest notice: {$notice['publicationNumber']} " . mb_substr((string)$notice['title'], 0, 120) ];
    }
}
