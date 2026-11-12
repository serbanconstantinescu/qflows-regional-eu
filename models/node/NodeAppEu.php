<?php

/*
    the base of the RegionalEU steps (not registered itself), as RegionalRO's NodeAppRo without a credential (the EU's public services need none):
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output"); in the picker's group Regional
	- the settings: the operation (static::OPERATIONS; one: not shown), the operation's own settings (operationFields(): each shown for its operations), At most (the
	    lists: LISTS), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (the service's errors,
	    no connection, a value that cannot be used) follows On error; other errors fail the step
	- left(): the seconds the step's timeout leaves (none: a \RuntimeException); dateOf(): a date setting; saveFile(): a file saved in the project's Files
*/

namespace plugins\RegionalEU\models\node;

abstract class NodeAppEu extends \models\wf\WfNode {
    use \models\node\AgentToolTrait;

    public static $type = 'app';
    public static $icon = 'bi-globe-europe-africa';
    public static $category = 'regional';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'result';

    const OPERATIONS = [];
    const LISTS = [];
    const MAX_ITEMS = 5000;
    const DEFAULT_ITEMS = 100;
    const MAX_TIMEOUT = 600;
    const DEFAULT_TIMEOUT = 60;
    //the dates of the services: Brussels' (ECB, TED, VIES)
    const TZ = 'Europe/Brussels';

    protected $start = 0;
    protected $timeout = 60;

    //the operation: [ result, summary for the log ]; $text($key): a setting as trimmed text, $value($key): its value ({{ }} evaluated)
    abstract protected function runOperation($operation, \Closure $text, \Closure $value);

    protected static function operationFields() {
	return [];
    }

    // ------------------------------------------------------------------------------------
    //settings

    public static function configSchema() {
	$fields = [];
	if (count(static::OPERATIONS) > 1)
	    $fields[] = [
		'key' => 'operation',
		'type' => 'select',
		'label' => 'Operation',
		'required' => true,
		'default' => static::$defaultOperation,
		'choices' => static::OPERATIONS,
	    ];
	foreach(static::operationFields() as $f) {
	    $ops = $f['ops'] ?? null;
	    unset($f['ops']);
	    $f += [ 'default' => '' ];
	    if ($ops !== null) {
		$f['ui'] = ($f['ui'] ?? []) + [ 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => $ops ] ];
		if (!empty($f['required'])) {
		    unset($f['required']);
		    $f['requiredIf'] = [ 'if' => 'operation', 'in' => $ops ];
		}
	    }
	    $fields[] = $f;
	}
	if (static::LISTS)
	    $fields[] = [
		'key' => 'maxItems',
		'type' => 'number',
		'label' => 'At most (items)',
		'required' => true,
		'default' => static::DEFAULT_ITEMS,
		'help' => 'Of a list: more are left out (truncated)',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => static::MAX_ITEMS, 'skipEmpty' => true, 'message' => 'from 1 to ' . static::MAX_ITEMS ] ],
		'ui' => [ 'limit' => true, 'step' => 1, 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => static::LISTS ] ],
	    ];
	$fields[] = [
	    'key' => 'timeout',
	    'type' => 'number',
	    'label' => 'Timeout (s)',
	    'required' => true,
	    'default' => static::DEFAULT_TIMEOUT,
	    'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_TIMEOUT, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_TIMEOUT ] ],
	    'ui' => [ 'step' => 1 ],
	];
	$fields[] = [
	    'key' => 'variable',
	    'type' => 'text',
	    'label' => 'Result variable',
	    'required' => true,
	    'default' => static::$defaultVariable,
	    'help' => static::LISTS ? 'A list: { items, count, total, truncated }; otherwise what the operation gives' : 'What the operation gives',
	    'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
	    'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	];
	$fields[] = [
	    'key' => 'onError',
	    'type' => 'select',
	    'label' => 'On error',
	    'required' => true,
	    'default' => 'fail',
	    'choices' => self::ON_ERROR,
	    'help' => 'The service\'s errors, or no connection',
	    'ui' => [ 'width' => 6 ],
	];
	return $fields;
    }

    protected static function field($key, $label, array $ops, array $more = []) {
	return array_merge([ 'key' => $key, 'type' => 'text', 'label' => $label, 'ops' => $ops ], $more);
    }

    //the operation of $config (the only one, when there is one)
    protected static function operationOf($config) {
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	return isset(static::OPERATIONS[$op]) ? $op : static::$defaultOperation;
    }

    //the result variable of each operation: [ type, info, fields ] (none: { items, count, total, truncated } for LISTS, else any)
    protected static function resultOf($operation) {
	return null;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$op = static::operationOf($config);
	if ($def = static::resultOf($op))
	    return [ $name => $def ];
	if (in_array($op, static::LISTS, true))
	    return [ $name => [ 'type' => 'object', 'info' => '{ items, count, total, truncated }', 'fields' => [
		'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'an item' ] ],
		'count' => [ 'type' => 'number', 'info' => 'the items read' ],
		'total' => [ 'type' => 'number', 'info' => 'all the matching items' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    ] ] ];
	return [ $name => [ 'type' => 'any', 'info' => 'the answer (null on error)' ] ];
    }

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	$this->settingVars = $this->varsForSettings();
	$operation = static::operationOf($this->config);
	if (!isset(static::OPERATIONS[$operation]))
	    throw new \ErrorException(static::$label . ": unknown operation `{$operation}`");
	$this->start = microtime(true);
	$this->timeout = max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', static::DEFAULT_TIMEOUT)));

	$name = (string)$this->cfg('variable', static::$defaultVariable);
	try {
	    [ $result, $summary ] = $this->runOperation($operation, $this->settingText(...), $this->settingOf(...));
	} catch (\RuntimeException $e) {
	    $this->setResult($name, null);
	    $this->exec->log($this->wfid, static::$label . " {$operation}: " . $e->getMessage());
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException(static::$label . ": {$operation}: " . $e->getMessage());
	}
	$this->setResult($name, $result);
	$this->exec->log($this->wfid, static::$label . " {$operation}: {$summary} (" . (int)round((microtime(true) - $this->start) * 1000) . ' ms)');
	return [ 0 ];
    }

    //the seconds the step's timeout leaves for a request
    protected function left() {
	$left = $this->timeout - (int)(microtime(true) - $this->start);
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	return $left;
    }

    protected function maxItems() {
	return max(1, min(static::MAX_ITEMS, (int)$this->cfg('maxItems', static::DEFAULT_ITEMS)));
    }

    // ------------------------------------------------------------------------------------
    //values

    //a date setting (YYYY-MM-DD, or anything strtotime reads, e.g. "-7 days"), Brussels' day; empty: $empty (null: none)
    protected function dateOf(\Closure $text, $key, $label, $empty = null) {
	$raw = $text($key);
	if ($raw === '')
	    return $empty;
	try {
	    return (new \DateTime($raw, new \DateTimeZone(self::TZ)))->format('Y-m-d');
	} catch (\Exception $e) {
	    throw new \RuntimeException("{$label}: not a date: `" . mb_substr($raw, 0, 32) . '` (YYYY-MM-DD)');
	}
    }

    //texts of a setting: a list, or separated by commas / semicolons / new lines (and spaces when $spaces)
    protected static function texts($value, $spaces = false) {
	$parts = is_array($value) ? (array_is_list($value) ? $value : [ $value ]) : preg_split($spaces ? '/[\s,;]+/' : '/[\r\n,;]+/', \util\Expression::toText($value));
	return array_values(array_filter(array_map(fn($p) => is_scalar($p) ? trim((string)$p) : $p, $parts), fn($p) => $p !== '' && $p !== null));
    }

    //$body saved as a file of the project: { id, filename, size, contentType }
    protected function saveFile($filename, $body, $contentType, $source) {
	$filename = \models\node\NodeAppDownload::filename($filename, '', '', $contentType);
	if (!\models\File::isAllowedName($filename))
	    throw new \RuntimeException("file type not allowed: `{$filename}`");
	$stream = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
	fwrite($stream, $body);
	rewind($stream);
	try {
	    $id = \models\File::createFromStream($filename, $stream, [ 'metadata' => [
		'project' => $this->exec->project,
		'aid' => $this->exec->aid,
		'owner' => null,
		'source' => $source,
		'wf' => $this->exec->wf,
		'created_at' => new \MongoDB\BSON\UTCDateTime(),
	    ] ]);
	} finally {
	    fclose($stream);
	}
	return [ 'id' => (string)$id, 'filename' => $filename, 'size' => strlen($body), 'contentType' => $contentType ];
    }
}
