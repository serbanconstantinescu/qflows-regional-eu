<?php

/*
    VIES, the European Commission's check of EU VAT numbers (the member states' registers, asked live), for the VIES VAT Check step. Its public REST API
    (https://ec.europa.eu/taxation_customs/vies/rest-api, no key):
	POST check-vat-number   { countryCode, vatNumber, requesterMemberStateCode, requesterNumber, traderName, traderStreet, traderPostalCode, traderCity, traderCompanyType }:
	                        { valid, name, address, requestDate, requestIdentifier (the consultation number, when the requester is given), trader*Match (VALID | INVALID |
	                        NOT_PROCESSED: the states that compare them) }; "---" for what the state does not give; an error: { actionSucceed: false, errorWrappers: [ { error } ] }
	GET check-status        { vow: { available }, countries: [ { countryCode, availability } ] }
    Northern Ireland is XI, Greece EL (GR taken too).
	Vies::split($text, $default)    [ country, number ] of a VAT number (the country its prefix, else $default), its spaces, dots and dashes dropped; null: not one
	Vies::check($country, $number, $more, $timeout)   the answer made plain (check()), or [ 'error' => code ] when the state's register cannot answer; a busy service is asked again
	Vies::status($timeout)          [ available, countries [ code => availability ] ]
    Vies::$testing: the check suite's local server instead of BASE
*/

namespace plugins\RegionalEU\util;

class Vies {
    const BASE = 'https://ec.europa.eu/taxation_customs/vies/rest-api/';
    const MAX_ANSWER = 1024 * 1024;
    //the shape of each state's numbers (the country's prefix not included)
    const FORMATS = [
	'AT' => 'U\d{8}', 'BE' => '[01]\d{9}', 'BG' => '\d{9,10}', 'CY' => '\d{8}[A-Z]', 'CZ' => '\d{8,10}', 'DE' => '\d{9}', 'DK' => '\d{8}', 'EE' => '\d{9}', 'EL' => '\d{9}',
	'ES' => '[A-Z0-9]\d{7}[A-Z0-9]', 'FI' => '\d{8}', 'FR' => '[A-HJ-NP-Z0-9]{2}\d{9}', 'HR' => '\d{11}', 'HU' => '\d{8}', 'IE' => '\d{7}[A-W][A-I]?|\d[A-Z+*]\d{5}[A-W]',
	'IT' => '\d{11}', 'LT' => '\d{9}|\d{12}', 'LU' => '\d{8}', 'LV' => '\d{11}', 'MT' => '\d{8}', 'NL' => '\d{9}B\d{2}', 'PL' => '\d{10}', 'PT' => '\d{9}', 'RO' => '\d{2,10}',
	'SE' => '\d{12}', 'SI' => '\d{8}', 'SK' => '\d{10}', 'XI' => '\d{9}|\d{12}|GD\d{3}|HA\d{3}',
    ];
    //the errors after which the same request is sent again (a second later), while the timeout allows
    const BUSY = [ 'MS_MAX_CONCURRENT_REQ', 'GLOBAL_MAX_CONCURRENT_REQ', 'SERVICE_UNAVAILABLE', 'TIMEOUT' ];
    const ERRORS = [
	'INVALID_INPUT' => 'the country or number is not valid',
	'INVALID_REQUESTER_INFO' => 'the requester\'s country or VAT number is not valid',
	'MS_UNAVAILABLE' => 'the member state\'s register does not answer now',
	'MS_MAX_CONCURRENT_REQ' => 'the member state\'s register is busy',
	'GLOBAL_MAX_CONCURRENT_REQ' => 'VIES is busy',
	'SERVICE_UNAVAILABLE' => 'VIES does not answer now',
	'TIMEOUT' => 'the member state\'s register did not answer in time',
	'VAT_BLOCKED' => 'the number may not be checked',
	'IP_BLOCKED' => 'VIES refuses this server\'s address',
    ];

    public static $testing = null;

    public static function base() {
	return self::$testing ?? self::BASE;
    }

    //the country code of a text (GR: EL); null: not a VIES country
    public static function country($c) {
	$c = strtoupper(trim((string)$c));
	$c = $c === 'GR' ? 'EL' : $c;
	return isset(self::FORMATS[$c]) ? $c : null;
    }

    public static function split($text, $default = '') {
	$t = strtoupper(preg_replace('/[\s.\-\/]/', '', trim((string)$text)));
	if ($t === '')
	    return null;
	$country = preg_match('/^[A-Z]{2}/', $t) ? self::country(substr($t, 0, 2)) : null;
	if ($country !== null)
	    $t = substr($t, 2);
	else
	    $country = self::country($default);
	if ($country === null || !preg_match('/^(' . self::FORMATS[$country] . ')$/', $t))
	    return null;
	return [ $country, $t ];
    }

    //"---" (not given by the state) or empty: null
    protected static function given($v) {
	$v = is_scalar($v) ? trim(preg_replace('/\s+/', ' ', (string)$v)) : '';
	return $v === '' || $v === '---' ? null : $v;
    }

    public static function check($country, $number, array $more, $timeout) {
	$start = microtime(true);
	$body = [ 'countryCode' => $country, 'vatNumber' => $number ] + array_filter($more, fn($v) => $v !== null && $v !== '');
	while (true) {
	    $left = $timeout - (int)(microtime(true) - $start);
	    if ($left < 1)
		throw new \RuntimeException("the step's timeout ({$timeout} s) passed");
	    $r = \util\Http::json('POST', self::base() . 'check-vat-number', [ 'json' => $body, 'timeout' => $left, 'maxBytes' => self::MAX_ANSWER,
		'https' => self::$testing === null, 'public' => self::$testing === null ]);
	    if ($r['error'] !== null)
		throw new \RuntimeException('no answer from VIES: ' . $r['error']);
	    $d = $r['data'];
	    if (!is_array($d))
		throw new \RuntimeException("VIES answered HTTP {$r['status']}, not its JSON");
	    $error = isset($d['errorWrappers']) || ($d['actionSucceed'] ?? true) === false ? (string)($d['errorWrappers'][0]['error'] ?? 'UNKNOWN') : null;
	    if ($error === null && $r['status'] >= 400)
		$error = 'HTTP_' . $r['status'];
	    if ($error !== null && in_array($error, self::BUSY, true) && $timeout - (microtime(true) - $start) > 3) {
		sleep(1);
		continue;
	    }
	    break;
	}
	if ($error === 'INVALID_REQUESTER_INFO' || $error === 'IP_BLOCKED')
	    throw new \RuntimeException('VIES: ' . self::ERRORS[$error]);
	if ($error !== null)
	    return [ 'error' => $error, 'message' => self::ERRORS[$error] ?? $error ];
	$match = [];
	foreach([ 'name' => 'traderNameMatch', 'street' => 'traderStreetMatch', 'postalCode' => 'traderPostalCodeMatch', 'city' => 'traderCityMatch', 'companyType' => 'traderCompanyTypeMatch' ] as $k => $f)
	    if (isset($d[$f]) && $d[$f] !== 'NOT_PROCESSED')
		$match[$k] = $d[$f] === 'VALID';
	return [ 'valid' => (bool)($d['valid'] ?? false), 'name' => self::given($d['name'] ?? null), 'address' => self::given($d['address'] ?? null),
	    'requestDate' => (string)($d['requestDate'] ?? ''), 'consultationNumber' => self::given($d['requestIdentifier'] ?? null), 'match' => $match ?: null ];
    }

    public static function status($timeout) {
	$r = \util\Http::json('GET', self::base() . 'check-status', [ 'timeout' => $timeout, 'maxBytes' => self::MAX_ANSWER, 'https' => self::$testing === null, 'public' => self::$testing === null ]);
	if ($r['error'] !== null)
	    throw new \RuntimeException('no answer from VIES: ' . $r['error']);
	if ($r['status'] != 200 || !is_array($r['data']))
	    throw new \RuntimeException("VIES answered HTTP {$r['status']}");
	$countries = [];
	foreach((array)($r['data']['countries'] ?? []) as $c)
	    if (isset($c['countryCode']))
		$countries[(string)$c['countryCode']] = (string)($c['availability'] ?? '');
	return [ (bool)($r['data']['vow']['available'] ?? false), $countries ];
    }
}
