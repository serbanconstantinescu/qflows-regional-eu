<?php

/*
    TED (Tenders Electronic Daily, the Official Journal's supplement S: the EU's public procurement notices), for the TED Tenders step and the TED notice trigger. Its
    public Search API (https://api.ted.europa.eu/v3, anonymous):
	POST notices/search   { query (TED's expert query, e.g. buyer-country IN (ROU DEU) AND classification-cpv IN (72000000) AND publication-date>=20261101 SORT BY
	                      publication-date DESC), fields, page, limit (at most PAGE), scope (ACTIVE | LATEST | ALL), paginationMode PAGE_NUMBER }:
	                      { notices: [ { <field>: value, links: { xml, pdf, html } } ], totalNoticeCount }; an error: { message, error: { type } }
    The multilingual fields ({ eng: ..., ron: [...] }) are given in one language (the step's, else English, else the first).
	Ted::search($query, $fields, $max, $scope, $timeout)   [ notices (raw), total ]: the pages read until $max
	Ted::notice($raw, $lang)                               a notice made plain (FIELDS' short names), `links` kept
	Ted::query($filters)                                   the expert query of the step's filters (keywords, countries, CPV codes, kinds, dates, more)
	Ted::fetch($url, $timeout)                             a notice's XML / PDF: [ body, content type ]
    Ted::$testing: the check suite's local server instead of BASE
*/

namespace plugins\RegionalEU\util;

class Ted {
    const BASE = 'https://api.ted.europa.eu/v3/';
    const PAGE = 250;
    //TED pages through at most 15,000 notices of a search
    const MAX_RESULTS = 15000;
    const MAX_ANSWER = 20 * 1024 * 1024;
    const MAX_FILE = 25 * 1024 * 1024;

    //the fields asked for, and their names in a notice made plain
    const FIELDS = [
	'publication-number' => 'publicationNumber',
	'notice-title' => 'title',
	'buyer-name' => 'buyer',
	'buyer-country' => 'buyerCountry',
	'publication-date' => 'publicationDate',
	'notice-type' => 'noticeType',
	'form-type' => 'formType',
	'procedure-type' => 'procedureType',
	'contract-nature' => 'contractNature',
	'classification-cpv' => 'cpv',
	'deadline-receipt-tender-date-lot' => 'deadline',
	'estimated-value-lot' => 'estimatedValue',
	'estimated-value-cur-lot' => 'currency',
	'place-of-performance' => 'placeOfPerformance',
    ];
    //the kinds of notices (form-type)
    const KINDS = [
	'competition' => 'Calls for competition (contract notices)',
	'planning' => 'Prior information (planning)',
	'result' => 'Contract awards (results)',
	'dir-awa-pre' => 'Direct awards announced',
	'cont-modif' => 'Contract modifications',
	'change' => 'Changes (corrigenda)',
    ];
    //the languages of the multilingual fields (ISO 639-2/B as TED names them)
    const LANGUAGES = [
	'eng' => 'English', 'bul' => 'Bulgarian', 'ces' => 'Czech', 'dan' => 'Danish', 'deu' => 'German', 'ell' => 'Greek', 'spa' => 'Spanish', 'est' => 'Estonian', 'fin' => 'Finnish',
	'fra' => 'French', 'gle' => 'Irish', 'hrv' => 'Croatian', 'hun' => 'Hungarian', 'ita' => 'Italian', 'lit' => 'Lithuanian', 'lav' => 'Latvian', 'mlt' => 'Maltese', 'nld' => 'Dutch',
	'pol' => 'Polish', 'por' => 'Portuguese', 'ron' => 'Romanian', 'slk' => 'Slovak', 'slv' => 'Slovenian', 'swe' => 'Swedish',
    ];
    //the countries' ISO 3166 two-letter codes as TED's three-letter ones (the EU, the EEA, and the others that publish on TED)
    const COUNTRIES = [
	'AT' => 'AUT', 'BE' => 'BEL', 'BG' => 'BGR', 'CY' => 'CYP', 'CZ' => 'CZE', 'DE' => 'DEU', 'DK' => 'DNK', 'EE' => 'EST', 'GR' => 'GRC', 'EL' => 'GRC', 'ES' => 'ESP', 'FI' => 'FIN',
	'FR' => 'FRA', 'HR' => 'HRV', 'HU' => 'HUN', 'IE' => 'IRL', 'IT' => 'ITA', 'LT' => 'LTU', 'LU' => 'LUX', 'LV' => 'LVA', 'MT' => 'MLT', 'NL' => 'NLD', 'PL' => 'POL', 'PT' => 'PRT',
	'RO' => 'ROU', 'SE' => 'SWE', 'SI' => 'SVN', 'SK' => 'SVK', 'IS' => 'ISL', 'LI' => 'LIE', 'NO' => 'NOR', 'CH' => 'CHE', 'MK' => 'MKD', 'RS' => 'SRB', 'ME' => 'MNE', 'AL' => 'ALB',
	'MD' => 'MDA', 'UA' => 'UKR', 'GE' => 'GEO', 'BA' => 'BIH', 'XK' => 'XKX', 'TR' => 'TUR', 'GB' => 'GBR', 'UK' => 'GBR',
    ];

    public static $testing = null;

    public static function base() {
	return self::$testing ?? self::BASE;
    }

    //TED's message of an error answer
    protected static function message($d) {
	if (!is_array($d))
	    return '';
	return mb_substr(trim((string)($d['message'] ?? $d['error']['message'] ?? '')), 0, 500);
    }

    public static function search($query, array $fields, $max, $scope, $timeout) {
	$start = microtime(true);
	$max = max(1, min(self::MAX_RESULTS, (int)$max));
	$notices = [];
	$total = 0;
	for ($page = 1; count($notices) < $max; $page++) {
	    $left = $timeout - (int)(microtime(true) - $start);
	    if ($left < 1)
		throw new \RuntimeException("the step's timeout ({$timeout} s) passed");
	    $limit = min(self::PAGE, $max);
	    $r = \util\Http::json('POST', self::base() . 'notices/search', [ 'json' => [ 'query' => (string)$query, 'fields' => array_values($fields), 'page' => $page, 'limit' => $limit,
		'scope' => $scope, 'paginationMode' => 'PAGE_NUMBER', 'checkQuerySyntax' => false ], 'timeout' => $left, 'maxBytes' => self::MAX_ANSWER, 'compressed' => true,
		'https' => self::$testing === null, 'public' => self::$testing === null ]);
	    if ($r['error'] !== null)
		throw new \RuntimeException($r['tooLarge'] ? 'TED\'s answer is larger than ' . (self::MAX_ANSWER >> 20) . ' MB' : 'no answer from TED: ' . $r['error']);
	    if ($r['status'] == 429)
		throw new \RuntimeException('TED\'s rate limit is reached (HTTP status 429): try again later');
	    if ($r['status'] >= 400 || !is_array($r['data'])) {
		$m = self::message($r['data']);
		throw new \RuntimeException("TED answered HTTP {$r['status']}" . ($m !== '' ? ": {$m}" : ''));
	    }
	    $got = (array)($r['data']['notices'] ?? []);
	    $total = (int)($r['data']['totalNoticeCount'] ?? count($got));
	    foreach($got as $n)
		$notices[] = (array)$n;
	    if (count($got) < $limit || $page * $limit >= min($total, self::MAX_RESULTS))
		break;
	}
	return [ array_slice($notices, 0, $max), $total ];
    }

    //a multilingual value ({ lang: text | [ texts ] }) in $lang, else English, else the first; a list: its first (titles) or all ($all: names)
    public static function text($v, $lang, $all = false) {
	if (!is_array($v) || array_is_list($v))
	    return is_array($v) ? ($all ? array_values(array_unique($v)) : ($v[0] ?? null)) : $v;
	$t = $v[$lang] ?? $v['eng'] ?? reset($v);
	if (is_array($t))
	    return $all ? implode('; ', array_values(array_unique(array_map('strval', $t)))) : ($t[0] ?? null);
	return $t;
    }

    //a date of TED (2026-11-12+01:00): YYYY-MM-DD
    public static function day($v) {
	$v = is_array($v) ? ($v[0] ?? '') : (string)$v;
	return preg_match('/^\d{4}-\d\d-\d\d/', $v) ? substr($v, 0, 10) : ($v !== '' ? $v : null);
    }

    public static function notice(array $n, $lang = 'eng') {
	$num = (string)($n['publication-number'] ?? '');
	$code = isset(self::LANGUAGES[$lang]) ? $lang : 'eng';
	$short = strtoupper($code);
	$links = (array)($n['links'] ?? []);
	$deadlines = array_values(array_filter(array_map([ self::class, 'day' ], (array)($n['deadline-receipt-tender-date-lot'] ?? []))));
	sort($deadlines);
	$values = array_map('floatval', (array)($n['estimated-value-lot'] ?? []));
	$u = fn($k) => array_values(array_unique(array_map('strval', (array)($n[$k] ?? []))));
	$out = [
	    'publicationNumber' => $num,
	    'title' => self::text($n['notice-title'] ?? null, $code),
	    'buyer' => self::text($n['buyer-name'] ?? null, $code, true),
	    'buyerCountry' => $u('buyer-country'),
	    'publicationDate' => self::day($n['publication-date'] ?? ''),
	    'noticeType' => $n['notice-type'] ?? null,
	    'formType' => $n['form-type'] ?? null,
	    'procedureType' => $n['procedure-type'] ?? null,
	    'contractNature' => $u('contract-nature'),
	    'cpv' => $u('classification-cpv'),
	    'deadline' => $deadlines[0] ?? null,
	    'estimatedValue' => $values ? round(array_sum($values), 2) : null,
	    'currency' => $u('estimated-value-cur-lot')[0] ?? null,
	    'placeOfPerformance' => $u('place-of-performance'),
	    'url' => $links['html'][$short] ?? $links['html']['ENG'] ?? ($num !== '' ? 'https://ted.europa.eu/en/notice/-/detail/' . rawurlencode($num) : null),
	    'pdf' => $links['pdf'][$short] ?? $links['pdf']['ENG'] ?? null,
	    'xml' => $links['xml']['MUL'] ?? null,
	];
	//the other fields asked for (More fields), as TED gives them
	foreach($n as $k => $v)
	    if ($k !== 'links' && !isset(self::FIELDS[$k]))
		$out['fields'][$k] = is_array($v) && !array_is_list($v) ? self::text($v, $code) : $v;
	return $out;
    }

    //a country as TED's code (RO / ROU => ROU); null: not one
    public static function country($c) {
	$c = strtoupper(trim((string)$c));
	if (strlen($c) === 2)
	    return self::COUNTRIES[$c] ?? null;
	return preg_match('/^[A-Z]{3}$/', $c) ? $c : null;
    }

    //a phrase of the full-text search, quoted
    protected static function phrase($t) {
	return '"' . str_replace([ '\\', '"' ], [ '', '' ], $t) . '"';
    }

    //the expert query of: keywords (all of them, in the notices' text), countries (the buyer's), cpv (codes), kinds (form-type), notice types, published from / to
    //(YYYY-MM-DD), deadline after, more (the user's expert query, and-ed); order: the newest first
    public static function query(array $f, $order = 'publication-date DESC') {
	$parts = [];
	foreach((array)($f['keywords'] ?? []) as $k)
	    $parts[] = 'FT~' . self::phrase($k);
	if (!empty($f['countries']))
	    $parts[] = 'buyer-country IN (' . implode(' ', $f['countries']) . ')';
	if (!empty($f['cpv']))
	    $parts[] = 'classification-cpv IN (' . implode(' ', $f['cpv']) . ')';
	if (!empty($f['kinds']))
	    $parts[] = 'form-type IN (' . implode(' ', $f['kinds']) . ')';
	if (!empty($f['types']))
	    $parts[] = 'notice-type IN (' . implode(' ', $f['types']) . ')';
	foreach([ 'from' => '>=', 'to' => '<=' ] as $k => $op)
	    if (!empty($f[$k]))
		$parts[] = "publication-date{$op}" . str_replace('-', '', $f[$k]);
	if (!empty($f['deadlineAfter']))
	    $parts[] = 'deadline-receipt-tender-date-lot>=' . str_replace('-', '', $f['deadlineAfter']);
	if (trim((string)($f['more'] ?? '')) !== '')
	    $parts[] = '(' . trim((string)$f['more']) . ')';
	return ($parts ? implode(' AND ', $parts) : 'publication-date>=19930101') . ($order ? " SORT BY {$order}" : '');
    }

    //a publication number (787856-2026, or 2026/S 219-787856): TED's form; null: not one
    public static function number($t) {
	$t = trim((string)$t);
	if (preg_match('~^0*(\d{1,8})-(\d{4})$~', $t, $m))
	    return "{$m[1]}-{$m[2]}";
	if (preg_match('~^(\d{4})\s*/\s*S\s*\d{1,3}\s*-\s*0*(\d{1,8})$~i', $t, $m))
	    return "{$m[2]}-{$m[1]}";
	return null;
    }

    //a notice's file (XML, PDF) from ted.europa.eu: [ body, content type ]
    public static function fetch($url, $timeout) {
	$test = self::$testing !== null;
	if (!$test && !preg_match('~^https://ted\.europa\.eu/~', (string)$url))
	    throw new \RuntimeException('not an address of ted.europa.eu: `' . mb_substr((string)$url, 0, 100) . '`');
	$r = \util\Http::request('GET', $url, [ 'timeout' => max(1, (int)$timeout), 'maxBytes' => self::MAX_FILE, 'redirects' => 3, 'https' => !$test, 'public' => !$test, 'compressed' => true ]);
	if ($r['error'] !== null)
	    throw new \RuntimeException($r['tooLarge'] ? 'the file is larger than ' . (self::MAX_FILE >> 20) . ' MB' : 'no answer from TED: ' . $r['error']);
	if ($r['status'] == 404)
	    throw new \RuntimeException('TED has no such notice');
	if ($r['status'] != 200)
	    throw new \RuntimeException("TED answered HTTP {$r['status']}");
	return [ $r['body'], (string)$r['mime'] ];
    }
}
