<?php

/*
    the euro foreign exchange reference rates of the European Central Bank, for the ECB Exchange Rates step. Public XML files on www.ecb.europa.eu/stats/eurofxref/
    (published each TARGET day about 16:00 CET):
	eurofxref-daily.xml      the latest day's
	eurofxref-hist-90d.xml   the last 90 days'
	eurofxref-hist.xml       every day since 1999-01-04 (about 8 MB, read compressed)
    <gesmes:Envelope><Cube><Cube time="YYYY-MM-DD"><Cube currency="USD" rate="1.1081"/>...</Cube>...</Cube></gesmes:Envelope>: units of the currency for 1 EUR.
	Ecb::on($date, $timeout)               [ date, rates ] of the last day with rates on or before $date (YYYY-MM-DD; null: the latest): rates [ currency => for 1 EUR ], EUR 1
	Ecb::between($from, $to, $timeout)     [ date => rates ] of the days from $from to $to (in order)
    The files read are kept in the process (the daemon runs many steps): the latest and the 90 days' TTL, the whole history HIST_TTL
    Ecb::$testing: the check suite's local server instead of BASE
*/

namespace plugins\RegionalEU\util;

class Ecb {
    const BASE = 'https://www.ecb.europa.eu/stats/eurofxref/';
    const FIRST = '1999-01-04';
    const TTL = 600;
    const HIST_TTL = 3600;
    const MAX_ANSWER = 20 * 1024 * 1024;

    public static $testing = null;

    //file => [ read at, [ date => [ currency => rate ] ] (dates in order) ]
    private static $files = [];

    public static function on($date, $timeout) {
	if ($date === null) {
	    $days = self::file('eurofxref-daily.xml', self::TTL, $timeout);
	    $last = array_key_last($days);
	    return [ $last, $days[$last] ];
	}
	if ($date < self::FIRST)
	    throw new \RuntimeException('the ECB\'s rates start on ' . self::FIRST);
	$days = self::days($date, $timeout);
	$best = null;
	foreach($days as $d => $rates) {
	    if ($d > $date)
		break;
	    $best = $d;
	}
	if ($best === null)
	    throw new \RuntimeException("the ECB has no rates on or before {$date}");
	return [ $best, $days[$best] ];
    }

    public static function between($from, $to, $timeout) {
	if ($to < self::FIRST)
	    throw new \RuntimeException('the ECB\'s rates start on ' . self::FIRST);
	return array_filter(self::days($from, $timeout), fn($d) => $d >= $from && $d <= $to, ARRAY_FILTER_USE_KEY);
    }

    //the file that has $from: the 90 days' when it starts on or before it, else the whole history
    private static function days($from, $timeout) {
	$start = microtime(true);
	$recent = self::file('eurofxref-hist-90d.xml', self::TTL, $timeout);
	if (array_key_first($recent) <= $from)
	    return $recent;
	return self::file('eurofxref-hist.xml', self::HIST_TTL, max(1, $timeout - (int)(microtime(true) - $start)));
    }

    private static function file($name, $ttl, $timeout) {
	if (isset(self::$files[$name]) && time() - self::$files[$name][0] < $ttl)
	    return self::$files[$name][1];
	$base = self::$testing ?? self::BASE;
	$r = \util\Http::request('GET', $base . $name, [ 'timeout' => max(1, (int)$timeout), 'maxBytes' => self::MAX_ANSWER, 'https' => self::$testing === null,
	    'public' => self::$testing === null, 'compressed' => true ]);
	if ($r['error'] !== null)
	    throw new \RuntimeException('no answer from the ECB: ' . $r['error']);
	if ($r['status'] != 200)
	    throw new \RuntimeException("the ECB answered HTTP {$r['status']} for {$name}");
	$days = self::parse($r['body']);
	if (!$days)
	    throw new \RuntimeException("the ECB's {$name} has no rates (not its XML?)");
	self::$files[$name] = [ time(), $days ];
	return $days;
    }

    //[ date => [ currency => rate ] ] of the ECB's XML (EUR 1 added), dates in order; read with a pattern (the history is large: no DOM of it)
    public static function parse($xml) {
	$xml = (string)$xml;
	if (trim($xml) === '' || preg_match('~<!(DOCTYPE|ENTITY)~i', $xml))
	    return [];
	$days = [];
	if (!preg_match_all('~<Cube\s+time=["\'](\d{4}-\d\d-\d\d)["\']\s*>(.*?)</Cube>\s*(?=<Cube\s+time|</Cube>)~s', $xml, $m, PREG_SET_ORDER))
	    return [];
	foreach($m as [ , $date, $body ]) {
	    $rates = [ 'EUR' => 1.0 ];
	    preg_match_all('~<Cube\s+currency=["\']([A-Z]{3})["\']\s+rate=["\']([0-9.]+)["\']\s*/>~', $body, $r, PREG_SET_ORDER);
	    foreach($r as [ , $cur, $rate ])
		if ((float)$rate > 0)
		    $rates[$cur] = (float)$rate;
	    if (count($rates) > 1)
		$days[$date] = $rates;
	}
	ksort($days);
	return $days;
    }
}
