<?php

/*
    VIES VAT Check - EU VAT numbers checked in VIES, the European Commission's service that asks each member state's register (util\Vies; public, no credential).
    Operations (setting `operation`):
	check    one or more numbers (separated by commas / new lines, or a list: of numbers, or of objects with `vat` / `vatNumber`), with their country's prefix or the
	         Country setting's; optionally the requester's own VAT number (VIES then gives a consultation number, the proof of the check) and the trader's details to
	         compare (the states that do so say whether each matches). The result: { items, count, valid (all are), first, invalid, unavailable }: each item { input,
	         country, number, vatNumber, valid, name, address, requestDate, consultationNumber, match, error }. A number of the wrong shape is not sent (invalid), a state
	         whose register does not answer is not an error (the item's `error`, listed in unavailable); none of the right shape is
	status   whether VIES and each member state's register answer now: { available, countries { AT: "Available", ... }, unavailable }
*/

namespace plugins\RegionalEU\models\node;

use plugins\RegionalEU\util\Vies;

class NodeAppEuVies extends NodeAppEu {
    public static $name = 'eu-vies';
    public static $label = 'VIES VAT Check';
    public static $icon = 'bi-patch-check';
    public static $defaultOperation = 'check';
    public static $defaultVariable = 'vat';

    const OPERATIONS = [
	'check' => 'Check VAT numbers',
	'status' => 'Member states available',
    ];
    const WRITES = [];
    const MAX_NUMBERS = 50;

    protected static function operationFields() {
	$countries = [ '' => '(the number\'s prefix)' ] + array_combine(array_keys(Vies::FORMATS), array_keys(Vies::FORMATS));
	return [
	    self::field('numbers', 'VAT numbers', [ 'check' ], [ 'required' => true, 'help' => 'One or more, separated by commas or new lines (or a list), e.g. RO14399840, DE811115368. At most '
		. self::MAX_NUMBERS . '; one request each', 'ui' => [ 'expression' => 'text', 'placeholder' => 'RO14399840' ] ]),
	    self::field('country', 'Country', [ 'check' ], [ 'type' => 'select', 'choices' => $countries, 'help' => 'Of the numbers without a prefix (Greece: EL, Northern Ireland: XI)',
		'ui' => [ 'width' => 6 ] ]),
	    self::field('requester', 'Your VAT number', [ 'check' ], [ 'help' => 'With its prefix: VIES then gives a consultation number, the proof of the check',
		'ui' => [ 'expression' => 'text', 'width' => 6, 'placeholder' => 'RO14399840' ] ]),
	    self::field('traderName', 'Compare name', [ 'check' ], [ 'help' => 'The trader\'s details to compare (only some member states do): each match, true or false',
		'ui' => [ 'expression' => 'text', 'width' => 6, 'newRow' => true, 'advanced' => true ] ]),
	    self::field('traderCompanyType', 'Compare company type', [ 'check' ], [ 'ui' => [ 'expression' => 'text', 'width' => 6, 'advanced' => true ] ]),
	    self::field('traderStreet', 'Compare street', [ 'check' ], [ 'ui' => [ 'expression' => 'text', 'width' => 6, 'advanced' => true ] ]),
	    self::field('traderPostalCode', 'Compare postal code', [ 'check' ], [ 'ui' => [ 'expression' => 'text', 'width' => 3, 'advanced' => true ] ]),
	    self::field('traderCity', 'Compare city', [ 'check' ], [ 'ui' => [ 'expression' => 'text', 'width' => 3, 'advanced' => true ] ]),
	];
    }

    protected static function resultOf($operation) {
	if ($operation === 'status')
	    return [ 'type' => 'object', 'info' => '{ available, countries, unavailable }', 'fields' => [
		'available' => [ 'type' => 'boolean', 'info' => 'VIES answers' ],
		'countries' => [ 'type' => 'object', 'info' => 'country => Available, Unavailable, Monitoring Disabled' ],
		'unavailable' => [ 'type' => 'array', 'info' => 'the countries whose register does not answer now' ],
	    ] ];
	return [ 'type' => 'object', 'info' => '{ items, count, valid, first, invalid, unavailable }', 'fields' => [
	    'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'a number checked: input, country, number, vatNumber, valid, name, address, requestDate, consultationNumber, match, error', 'fields' => [
		'vatNumber' => [ 'type' => 'string', 'info' => 'country + number, e.g. RO14399840' ],
		'valid' => [ 'type' => 'boolean', 'info' => 'null when the register did not answer' ],
		'name' => [ 'type' => 'string', 'info' => 'null when the state does not give it' ],
		'address' => [ 'type' => 'string' ],
		'consultationNumber' => [ 'type' => 'string', 'info' => 'with Your VAT number' ],
		'match' => [ 'type' => 'object', 'info' => '{ name, street, postalCode, city, companyType }: true / false; null when not compared' ],
		'error' => [ 'type' => 'string', 'info' => 'e.g. MS_UNAVAILABLE; null when checked' ],
	    ] ] ],
	    'count' => [ 'type' => 'number', 'info' => 'the numbers checked' ],
	    'valid' => [ 'type' => 'boolean', 'info' => 'every number is valid' ],
	    'first' => [ 'type' => 'object', 'info' => 'the first item, or null' ],
	    'invalid' => [ 'type' => 'array', 'info' => 'the texts that are not VAT numbers (not sent)' ],
	    'unavailable' => [ 'type' => 'array', 'info' => 'the numbers whose register did not answer' ],
	] ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if ($operation === 'status') {
	    [ $available, $countries ] = Vies::status($this->left());
	    $down = array_keys(array_filter($countries, fn($a) => $a !== 'Available'));
	    return [ [ 'available' => $available, 'countries' => $countries, 'unavailable' => $down ],
		($available ? 'VIES answers' : 'VIES does not answer') . ($down ? ', not available: ' . implode(', ', $down) : ', every member state available') ];
	}
	$default = (string)$this->cfg('country', '');
	$numbers = [];
	$invalid = [];
	foreach(self::texts($value('numbers')) as $p) {
	    if (is_array($p))
		$p = $p['vatNumber'] ?? $p['vat'] ?? $p['VAT'] ?? '';
	    $t = trim(is_scalar($p) ? (string)$p : '');
	    if ($t === '')
		continue;
	    $s = Vies::split($t, $default);
	    if ($s === null)
		$invalid[] = mb_substr($t, 0, 40);
	    elseif (!isset($numbers[$s[0] . $s[1]]))
		$numbers[$s[0] . $s[1]] = [ $t, $s[0], $s[1] ];
	}
	if (!$numbers)
	    throw new \RuntimeException($invalid ? 'VAT numbers: none valid (`' . implode('`, `', array_slice($invalid, 0, 5)) . '`)' : 'VAT numbers: none given');
	if (count($numbers) > self::MAX_NUMBERS)
	    throw new \RuntimeException('VAT numbers: more than ' . self::MAX_NUMBERS);
	$more = [];
	if (($req = $text('requester')) !== '') {
	    $r = Vies::split($req);
	    if ($r === null)
		throw new \RuntimeException('Your VAT number: not an EU VAT number with its prefix: `' . mb_substr($req, 0, 40) . '`');
	    $more = [ 'requesterMemberStateCode' => $r[0], 'requesterNumber' => $r[1] ];
	}
	foreach([ 'traderName', 'traderCompanyType', 'traderStreet', 'traderPostalCode', 'traderCity' ] as $k)
	    $more[$k] = $text($k);
	$items = [];
	$unavailable = [];
	foreach($numbers as $full => [ $input, $country, $number ]) {
	    $c = Vies::check($country, $number, $more, $this->left());
	    $item = [ 'input' => $input, 'country' => $country, 'number' => $number, 'vatNumber' => $full ];
	    if (isset($c['error'])) {
		$unavailable[] = $full;
		$item += [ 'valid' => null, 'name' => null, 'address' => null, 'requestDate' => null, 'consultationNumber' => null, 'match' => null, 'error' => $c['error'], 'message' => $c['message'] ];
	    } else
		$item += $c + [ 'error' => null ];
	    $items[] = $item;
	}
	$valid = array_filter($items, fn($i) => $i['valid'] === true);
	$result = [ 'items' => $items, 'count' => count($items), 'valid' => count($valid) === count($items), 'first' => $items[0], 'invalid' => $invalid, 'unavailable' => $unavailable ];
	//an AI step's tool: the first item is not given twice
	if ($this->toolCall)
	    unset($result['first']);
	$summary = count($items) === 1 && !$unavailable
	    ? $items[0]['vatNumber'] . ($items[0]['valid'] ? ' valid' . ($items[0]['name'] ? " ({$items[0]['name']})" : '') : ' not valid')
	    : \util\Format::plural(count($items), 'number') . ' checked, ' . count($valid) . ' valid';
	return [ $result, $summary . ($unavailable ? ', ' . count($unavailable) . ' not answered (' . implode(', ', array_slice($unavailable, 0, 3)) . ')' : '')
	    . ($invalid ? ', ' . count($invalid) . ' not VAT numbers' : '') ];
    }
}
