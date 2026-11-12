<?php

/*
    TED Tenders - the EU's public procurement notices on TED (Tenders Electronic Daily; util\Ted, its public Search API, no credential). Operations (setting `operation`):
	search     the notices the filters find, the newest first: keywords (all, in the notice's text), the buyers' countries (RO or ROU), CPV codes, kinds (calls for
	           competition, awards, ...), published from / to, a deadline on or after a day, TED's own expert query (and-ed); which notices (active ones, the latest
	           version, all). { items, count, total, truncated }: each util\Ted::notice (publicationNumber, title, buyer, buyerCountry, publicationDate, noticeType, formType,
	           procedureType, contractNature, cpv, deadline (the earliest of its lots), estimatedValue (of its lots), currency, placeOfPerformance, url, pdf, xml, fields)
	get        one notice by its publication number (787856-2026, or 2026/S 219-787856): the notice, or null when TED has none
	download   a notice's PDF (in the step's language) or XML (eForms) saved in the project's Files: { id, filename, size, contentType, publicationNumber }
    The multilingual fields (title, buyer) are given in Language (else English, else the first); More fields: other fields of TED's (e.g. description-lot), in `fields`
*/

namespace plugins\RegionalEU\models\node;

use plugins\RegionalEU\util\Ted;

class NodeAppEuTed extends NodeAppEu {
    public static $name = 'eu-ted';
    public static $label = 'TED Tenders';
    public static $icon = 'bi-megaphone';
    public static $defaultOperation = 'search';
    public static $defaultVariable = 'tenders';
    const MAX_ITEMS = Ted::MAX_RESULTS;

    const OPERATIONS = [
	'search' => 'Search notices',
	'get' => 'Get a notice',
	'download' => 'Download a notice (PDF, XML)',
    ];
    const LISTS = [ 'search' ];
    const WRITES = [ 'download' ];
    const SCOPES = [ 'ACTIVE' => 'Active notices (deadline not passed)', 'LATEST' => 'The latest version of each', 'ALL' => 'All notices' ];
    const FORMATS = [ 'pdf' => 'PDF', 'xml' => 'XML (eForms)' ];

    //the filter settings, shared with the TED notice trigger: [ fields ] for $ops
    public static function filterFields(array $ops) {
	return [
	    self::field('keywords', 'Keywords', $ops, [ 'help' => 'Words or phrases the notice\'s text has, separated by commas (all of them)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('countries', 'Buyers\' countries', $ops, [ 'help' => 'e.g. RO, DE (or ROU, DEU); empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('cpv', 'CPV codes', $ops, [ 'help' => 'e.g. 72000000, 48000000 (TED also finds their sub-codes); empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('kinds', 'Kinds', $ops, [ 'type' => 'select', 'multiple' => true, 'default' => [], 'choices' => Ted::KINDS, 'help' => 'None chosen: all', 'ui' => [ 'width' => 6 ] ]),
	    self::field('query', 'Expert query', $ops, [ 'type' => 'textarea', 'help' => 'TED\'s own search syntax, and-ed with the above, e.g. procedure-type=open AND estimated-value-lot>=100000',
		'ui' => [ 'rows' => 2, 'expression' => 'text', 'code' => true, 'advanced' => true ] ]),
	];
    }

    public static function languageField(array $ops) {
	return self::field('language', 'Language', $ops, [ 'type' => 'select', 'default' => 'eng', 'choices' => Ted::LANGUAGES, 'help' => 'Of titles and buyers\' names (else English)', 'ui' => [ 'width' => 6 ] ]);
    }

    protected static function operationFields() {
	return array_merge(self::filterFields([ 'search' ]), [
	    self::field('from', 'Published from', [ 'search' ], [ 'help' => 'YYYY-MM-DD, or e.g. -7 days; empty: any', 'ui' => [ 'expression' => 'text', 'width' => 4, 'newRow' => true, 'placeholder' => 'YYYY-MM-DD' ] ]),
	    self::field('to', 'Published to', [ 'search' ], [ 'ui' => [ 'expression' => 'text', 'width' => 4, 'placeholder' => 'YYYY-MM-DD' ] ]),
	    self::field('deadlineAfter', 'Deadline on or after', [ 'search' ], [ 'help' => 'e.g. today', 'ui' => [ 'expression' => 'text', 'width' => 4, 'placeholder' => 'YYYY-MM-DD' ] ]),
	    self::field('scope', 'Notices', [ 'search' ], [ 'type' => 'select', 'default' => 'ACTIVE', 'choices' => self::SCOPES, 'ui' => [ 'width' => 6 ] ]),
	    self::field('number', 'Publication number', [ 'get', 'download' ], [ 'required' => true, 'help' => 'e.g. 787856-2026 or 2026/S 219-787856',
		'ui' => [ 'expression' => 'text', 'width' => 6, 'placeholder' => '787856-2026' ] ]),
	    self::field('format', 'Format', [ 'download' ], [ 'type' => 'select', 'default' => 'pdf', 'choices' => self::FORMATS, 'ui' => [ 'width' => 6 ] ]),
	    self::languageField([ 'search', 'get', 'download' ]),
	    self::field('fields', 'More fields', [ 'search', 'get' ], [ 'help' => 'Other fields of TED\'s, separated by commas, e.g. description-lot, winner-name; given in `fields`',
		'ui' => [ 'expression' => 'text', 'width' => 6, 'advanced' => true ] ]),
	]);
    }

    protected static function resultOf($operation) {
	$notice = [
	    'publicationNumber' => [ 'type' => 'string', 'info' => 'e.g. 787856-2026' ],
	    'title' => [ 'type' => 'string' ],
	    'buyer' => [ 'type' => 'string' ],
	    'buyerCountry' => [ 'type' => 'array', 'info' => 'e.g. [ "ROU" ]' ],
	    'publicationDate' => [ 'type' => 'string', 'info' => 'YYYY-MM-DD' ],
	    'noticeType' => [ 'type' => 'string', 'info' => 'e.g. cn-standard, can-standard' ],
	    'formType' => [ 'type' => 'string', 'info' => 'competition, planning, result, ...' ],
	    'procedureType' => [ 'type' => 'string', 'info' => 'e.g. open' ],
	    'contractNature' => [ 'type' => 'array', 'info' => 'services, supplies, works' ],
	    'cpv' => [ 'type' => 'array' ],
	    'deadline' => [ 'type' => 'string', 'info' => 'the earliest deadline for tenders of its lots, YYYY-MM-DD' ],
	    'estimatedValue' => [ 'type' => 'number', 'info' => 'the sum of its lots\' estimates' ],
	    'currency' => [ 'type' => 'string' ],
	    'placeOfPerformance' => [ 'type' => 'array', 'info' => 'NUTS or country codes' ],
	    'url' => [ 'type' => 'string', 'info' => 'the notice on ted.europa.eu' ],
	    'pdf' => [ 'type' => 'string' ],
	    'xml' => [ 'type' => 'string' ],
	    'fields' => [ 'type' => 'object', 'info' => 'More fields' ],
	];
	if ($operation === 'download')
	    return [ 'type' => 'object', 'info' => 'the file: { id, filename, size, contentType, publicationNumber }', 'fields' => [ 'id' => [ 'type' => 'string', 'info' => 'the project file' ],
		'filename' => [ 'type' => 'string' ], 'size' => [ 'type' => 'number' ], 'contentType' => [ 'type' => 'string' ], 'publicationNumber' => [ 'type' => 'string' ] ] ];
	if ($operation === 'get')
	    return [ 'type' => 'object', 'info' => 'the notice (null: TED has none)', 'fields' => $notice ];
	return [ 'type' => 'object', 'info' => '{ items, count, total, truncated, query }', 'fields' => [
	    'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'a notice', 'fields' => $notice ] ],
	    'count' => [ 'type' => 'number', 'info' => 'the notices read' ],
	    'total' => [ 'type' => 'number', 'info' => 'all the matching notices' ],
	    'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    'query' => [ 'type' => 'string', 'info' => 'the expert query sent' ],
	] ];
    }

    //the filters of a step's (or trigger's) settings, for Ted::query
    public static function filters(\Closure $text, \Closure $value, array $kinds) {
	$texts = fn($k) => array_values(array_filter(array_map(fn($p) => is_scalar($p) ? trim((string)$p) : '', self::texts($value($k))), 'strlen'));
	$countries = [];
	foreach($texts('countries') as $c) {
	    $code = Ted::country($c);
	    if ($code === null)
		throw new \RuntimeException('Buyers\' countries: not a country code: `' . mb_substr($c, 0, 10) . '`');
	    $countries[$code] = true;
	}
	$cpv = [];
	foreach($texts('cpv') as $c) {
	    $code = preg_replace('/-\d$/', '', str_replace(' ', '', $c));
	    if (!preg_match('/^\d{8}$/', $code))
		throw new \RuntimeException('CPV codes: not a CPV code (8 digits): `' . mb_substr($c, 0, 16) . '`');
	    $cpv[$code] = true;
	}
	$more = $text('query');
	if (preg_match('/\bSORT\s+BY\b/i', $more))
	    throw new \RuntimeException('Expert query: without SORT BY (the step sorts)');
	return [ 'keywords' => $texts('keywords'), 'countries' => array_keys($countries), 'cpv' => array_keys($cpv),
	    'kinds' => array_values(array_intersect(array_map('strval', $kinds), array_keys(Ted::KINDS))), 'more' => $more ];
    }

    //the fields asked for: TED's + More fields
    public static function fieldsOf($value) {
	$more = [];
	foreach(self::texts($value, true) as $f) {
	    $f = strtolower(is_scalar($f) ? trim((string)$f) : '');
	    if ($f === '')
		continue;
	    if (!preg_match('/^[a-z][a-z0-9-]{1,80}$/', $f))
		throw new \RuntimeException("More fields: not a field of TED's: `" . mb_substr($f, 0, 40) . '`');
	    $more[] = $f;
	}
	return array_values(array_unique(array_merge(array_keys(Ted::FIELDS), $more)));
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$lang = isset(Ted::LANGUAGES[(string)$this->cfg('language', 'eng')]) ? (string)$this->cfg('language', 'eng') : 'eng';
	if ($operation === 'search') {
	    $f = self::filters($text, $value, (array)$this->cfg('kinds', []));
	    $f['from'] = $this->dateOf($text, 'from', 'Published from');
	    $f['to'] = $this->dateOf($text, 'to', 'Published to');
	    $f['deadlineAfter'] = $this->dateOf($text, 'deadlineAfter', 'Deadline on or after');
	    $query = Ted::query($f);
	    $scope = isset(self::SCOPES[(string)$this->cfg('scope', 'ACTIVE')]) ? (string)$this->cfg('scope', 'ACTIVE') : 'ACTIVE';
	    [ $raw, $total ] = Ted::search($query, self::fieldsOf($value('fields')), $this->maxItems(), $scope, $this->left());
	    $items = array_map(fn($n) => Ted::notice($n, $lang), $raw);
	    return [ [ 'items' => $items, 'count' => count($items), 'total' => $total, 'truncated' => $total > count($items), 'query' => $query ],
		\util\Format::plural(count($items), 'notice') . ($total > count($items) ? " of {$total}" : '') ];
	}
	$num = Ted::number($text('number'));
	if ($num === null)
	    throw new \RuntimeException('Publication number: not one (e.g. 787856-2026): `' . mb_substr($text('number'), 0, 30) . '`');
	[ $raw ] = Ted::search("publication-number={$num}", $operation === 'get' ? self::fieldsOf($value('fields')) : [ 'publication-number', 'notice-title' ], 1, 'ALL', $this->left());
	$notice = $raw ? Ted::notice($raw[0], $lang) : null;
	if ($operation === 'get')
	    return [ $notice, $notice ? "{$num}: " . mb_substr((string)$notice['title'], 0, 120) : "TED has no notice {$num}" ];
	if (!$notice)
	    throw new \RuntimeException("TED has no notice {$num}");
	$format = (string)$this->cfg('format', 'pdf') === 'xml' ? 'xml' : 'pdf';
	$url = $notice[$format] ?? ('https://ted.europa.eu/en/notice/' . rawurlencode($num) . "/{$format}");
	[ $body, $mime ] = Ted::fetch($url, $this->left());
	$file = $this->saveFile("ted-{$num}.{$format}", $body, $mime ?: ($format === 'pdf' ? 'application/pdf' : 'application/xml'), 'ted');
	return [ $file + [ 'publicationNumber' => $num ], "{$num} saved as `{$file['filename']}` ({$file['size']} bytes)" ];
    }
}
