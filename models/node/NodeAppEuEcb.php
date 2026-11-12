<?php

/*
    ECB Exchange Rates - the euro foreign exchange reference rates of the European Central Bank (util\Ecb; public, no credential): about 30 currencies against the euro,
    each TARGET day. Operations (setting `operation`):
	rates     the rates of a day (empty: the latest), of some currencies (empty: all): { date (the day used: the last with rates on or before the one asked), requested,
	          base EUR, rates { USD: 1.1081, ... }: units for 1 EUR }
	convert   an amount from a currency to another (EUR or one of the ECB's; two others: through the euro) at a day's rates: { amount, from, to, rate (of 1 `from` in `to`),
	          result (rounded to Decimals), date, requested }
	history   the rates of some currencies from a day to another: { items: [ { date, rates } ], count, total, truncated, base EUR, from, to }
*/

namespace plugins\RegionalEU\models\node;

use plugins\RegionalEU\util\Ecb;

class NodeAppEuEcb extends NodeAppEu {
    public static $name = 'eu-ecb-rates';
    public static $label = 'ECB Exchange Rates';
    public static $icon = 'bi-currency-euro';
    public static $defaultOperation = 'rates';
    public static $defaultVariable = 'rates';
    const DEFAULT_TIMEOUT = 30;
    const DEFAULT_ITEMS = 1000;
    const MAX_ITEMS = 10000;

    const OPERATIONS = [
	'rates' => 'Get exchange rates',
	'convert' => 'Convert an amount',
	'history' => 'Rates between two days',
    ];
    const LISTS = [ 'history' ];
    const WRITES = [];

    protected static function operationFields() {
	return [
	    self::field('date', 'On date', [ 'rates', 'convert' ], [ 'help' => 'YYYY-MM-DD; empty: the latest rates. A day without rates (a weekend, a TARGET holiday): the day before with rates',
		'ui' => [ 'expression' => 'text', 'width' => 6, 'placeholder' => 'YYYY-MM-DD' ] ]),
	    self::field('currencies', 'Currencies', [ 'rates', 'history' ], [ 'help' => 'e.g. USD, RON; empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('from', 'From day', [ 'history' ], [ 'required' => true, 'help' => 'YYYY-MM-DD, or e.g. -30 days', 'ui' => [ 'expression' => 'text', 'width' => 6, 'newRow' => true, 'placeholder' => 'YYYY-MM-DD' ] ]),
	    self::field('to', 'To day', [ 'history' ], [ 'help' => 'Empty: today', 'ui' => [ 'expression' => 'text', 'width' => 6, 'placeholder' => 'YYYY-MM-DD' ] ]),
	    self::field('amount', 'Amount', [ 'convert' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('decimals', 'Decimals', [ 'convert' ], [ 'default' => '2', 'help' => 'Of the result, 0 to 8', 'ui' => [ 'width' => 6 ] ]),
	    self::field('fromCurrency', 'From currency', [ 'convert' ], [ 'required' => true, 'default' => 'EUR', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('toCurrency', 'To currency', [ 'convert' ], [ 'required' => true, 'default' => 'USD', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected static function resultOf($operation) {
	if ($operation === 'convert')
	    return [ 'type' => 'object', 'info' => '{ amount, from, to, rate, result, date, requested }', 'fields' => [
		'result' => [ 'type' => 'number', 'info' => 'the amount in `to`' ],
		'rate' => [ 'type' => 'number', 'info' => '1 `from` in `to`' ],
		'date' => [ 'type' => 'string', 'info' => 'the day of the rates' ],
	    ] ];
	if ($operation === 'history')
	    return [ 'type' => 'object', 'info' => '{ items, count, total, truncated, base, from, to }', 'fields' => [
		'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => '{ date, rates { currency: units for 1 EUR } }' ] ],
		'count' => [ 'type' => 'number', 'info' => 'the days given' ],
		'total' => [ 'type' => 'number', 'info' => 'the days with rates in the range' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most (the newest are left out)' ],
		'base' => [ 'type' => 'string', 'info' => 'EUR' ],
	    ] ];
	return [ 'type' => 'object', 'info' => '{ date, requested, base, rates }', 'fields' => [
	    'date' => [ 'type' => 'string', 'info' => 'the day of the rates' ],
	    'requested' => [ 'type' => 'string', 'info' => 'the day asked (empty: the latest)' ],
	    'base' => [ 'type' => 'string', 'info' => 'EUR' ],
	    'rates' => [ 'type' => 'object', 'info' => 'currency => units for 1 EUR' ],
	] ];
    }

    //the currencies asked (upper case); [] : all
    protected static function wanted($value) {
	$want = array_values(array_unique(array_map(fn($c) => strtoupper(trim((string)$c)), array_filter(self::texts($value, true), 'is_scalar'))));
	foreach($want as $c)
	    if (!preg_match('/^[A-Z]{3}$/', $c))
		throw new \RuntimeException('Currencies: not a currency code: `' . mb_substr($c, 0, 10) . '`');
	return $want;
    }

    //the rates of $want only (all but EUR when none: EUR itself only when asked)
    protected static function only(array $rates, array $want, $date) {
	if (!$want)
	    return array_diff_key($rates, [ 'EUR' => true ]);
	$missing = array_diff($want, array_keys($rates));
	if ($missing)
	    throw new \RuntimeException('Currencies: the ECB has no rate of ' . implode(', ', $missing) . " on {$date}");
	return array_intersect_key($rates, array_flip($want));
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$today = (new \DateTime('now', new \DateTimeZone(self::TZ)))->format('Y-m-d');
	if ($operation === 'history') {
	    $from = $this->dateOf($text, 'from', 'From day');
	    if ($from === null)
		throw new \RuntimeException('From day: none given');
	    $to = $this->dateOf($text, 'to', 'To day', $today);
	    if ($to < $from)
		throw new \RuntimeException("To day ({$to}) is before From day ({$from})");
	    $want = self::wanted($value('currencies'));
	    $days = Ecb::between($from, $to, $this->left());
	    $all = [];
	    foreach($days as $d => $rates)
		$all[] = [ 'date' => $d, 'rates' => self::only($rates, $want, $d) ];
	    $items = array_slice($all, 0, $this->maxItems());
	    return [ [ 'items' => $items, 'count' => count($items), 'total' => count($all), 'truncated' => count($all) > count($items), 'base' => 'EUR', 'from' => $from, 'to' => $to ],
		\util\Format::plural(count($items), 'day') . " from {$from} to {$to}" . (count($all) > count($items) ? ' of ' . count($all) : '') ];
	}
	$requested = $this->dateOf($text, 'date', 'On date');
	if ($requested !== null && $requested > $today)
	    throw new \RuntimeException("On date: {$requested} is in the future");
	[ $date, $rates ] = Ecb::on($requested, $this->left());
	if ($operation === 'convert') {
	    $cur = function($key, $label) use ($text, $rates, $date) {
		$c = strtoupper($text($key));
		if (!preg_match('/^[A-Z]{3}$/', $c))
		    throw new \RuntimeException("{$label}: not a currency code: `" . mb_substr($c, 0, 10) . '`');
		if (!isset($rates[$c]))
		    throw new \RuntimeException("{$label}: the ECB has no rate of {$c} on {$date}");
		return $c;
	    };
	    $from = $cur('fromCurrency', 'From currency');
	    $to = $cur('toCurrency', 'To currency');
	    $raw = str_replace([ ' ', ',' ], [ '', '.' ], $text('amount'));
	    if (!is_numeric($raw))
		throw new \RuntimeException('Amount: not a number: `' . mb_substr($raw, 0, 32) . '`');
	    $raw2 = $text('decimals');
	    $decimals = $raw2 === '' ? 2 : (int)$raw2;
	    if (!preg_match('/^\d$/', $raw2 === '' ? '2' : $raw2) || $decimals > 8)
		throw new \RuntimeException('Decimals: from 0 to 8');
	    $rate = $rates[$to] / $rates[$from];
	    $result = round((float)$raw * $rate, $decimals);
	    return [ [ 'amount' => (float)$raw, 'from' => $from, 'to' => $to, 'rate' => round($rate, 8), 'result' => $result, 'date' => $date, 'requested' => (string)$requested ],
		"{$raw} {$from} = {$result} {$to} (ECB {$date})" ];
	}
	$out = self::only($rates, self::wanted($value('currencies')), $date);
	return [ [ 'date' => $date, 'requested' => (string)$requested, 'base' => 'EUR', 'rates' => $out ],
	    \util\Format::plural(count($out), 'rate') . " of {$date}" . (isset($out['USD']) ? " (USD {$out['USD']})" : '') ];
    }
}
