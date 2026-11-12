<?php

/*
    a fake of the EU's services for RegionalEU's checks (checks.php: regionaleu), run by PHP's built-in server: EU_STATE=<a folder> php -S 127.0.0.1:<port> EuTestServer.php
	VIES (/vies/): check-vat-number (RO14399840 valid with its name, address and - with a requester - a consultation number; DE...: MS_UNAVAILABLE; others not valid;
	    <state>/busy.json: how many GLOBAL_MAX_CONCURRENT_REQ next), check-status (DE unavailable)
	ECB (/ecb/): eurofxref-daily.xml (2026-11-12), eurofxref-hist-90d.xml (2026-11-06 to 2026-11-12), eurofxref-hist.xml (2010-01-04 too)
	TED (/ted/): notices/search over <state>/notices.json (each { publication-number, publication-date, buyer-country, notice-title, ... }): publication-number=, publication-date>=,
	    buyer-country IN (...), SORT BY publication-date ASC|DESC, page / limit; an unknown field: 400 as TED's; /ted/pdf/<number>: a PDF
	Each request appended to <state>/requests.jsonl (method, path, body)
*/

$state = getenv('EU_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'body' => $body ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$out = function($v, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};
$xml = function(array $days) {
    header('Content-Type: text/xml');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<gesmes:Envelope xmlns:gesmes=\"http://www.gesmes.org/xml/2002-08-01\" xmlns=\"http://www.ecb.int/vocabulary/2002-08-01/eurofxref\">\n\t<Cube>\n";
    foreach($days as $d => $rates) {
	echo "\t\t<Cube time='{$d}'>\n";
	foreach($rates as $c => $r)
	    echo "\t\t\t<Cube currency='{$c}' rate='{$r}'/>\n";
	echo "\t\t</Cube>\n";
    }
    echo "\t</Cube>\n</gesmes:Envelope>\n";
    exit;
};
$recent = [ '2026-11-12' => [ 'USD' => '1.1081', 'RON' => '5.3473' ], '2026-11-11' => [ 'USD' => '1.1100', 'RON' => '5.3400' ], '2026-11-06' => [ 'USD' => '1.1165', 'RON' => '5.3300' ] ];

switch(true) {
    case $path === '/vies/check-vat-number' && $method === 'POST':
	if (($b = (int)$load('busy', 0)) > 0) {
	    $save('busy', $b - 1);
	    $out([ 'actionSucceed' => false, 'errorWrappers' => [ [ 'error' => 'GLOBAL_MAX_CONCURRENT_REQ' ] ] ]);
	}
	$cc = $body['countryCode'] ?? '';
	if (($body['requesterNumber'] ?? '') === '000')
	    $out([ 'actionSucceed' => false, 'errorWrappers' => [ [ 'error' => 'INVALID_REQUESTER_INFO' ] ] ]);
	if ($cc === 'DE')
	    $out([ 'actionSucceed' => false, 'errorWrappers' => [ [ 'error' => 'MS_UNAVAILABLE' ] ] ]);
	$valid = $cc === 'RO' && ($body['vatNumber'] ?? '') === '14399840';
	$out([ 'countryCode' => $cc, 'vatNumber' => $body['vatNumber'] ?? '', 'requestDate' => '2026-11-12T10:00:00.000Z', 'valid' => $valid,
	    'requestIdentifier' => $valid && isset($body['requesterNumber']) ? 'WAPIAAAA-check' : '', 'name' => $valid ? 'DANTE INTERNATIONAL SA' : '---',
	    'address' => $valid ? "STR. GARA HERĂSTRĂU\nNr. 6" : '---', 'traderNameMatch' => isset($body['traderName']) ? ($body['traderName'] === 'Dante International' ? 'VALID' : 'INVALID') : 'NOT_PROCESSED',
	    'traderCityMatch' => 'NOT_PROCESSED' ]);
    case $path === '/vies/check-status':
	$out([ 'vow' => [ 'available' => true ], 'countries' => [ [ 'countryCode' => 'DE', 'availability' => 'Unavailable' ], [ 'countryCode' => 'RO', 'availability' => 'Available' ] ] ]);
    case $path === '/ecb/eurofxref-daily.xml':
	$xml([ '2026-11-12' => $recent['2026-11-12'] ]);
    case $path === '/ecb/eurofxref-hist-90d.xml':
	$xml($recent);
    case $path === '/ecb/eurofxref-hist.xml':
	$xml($recent + [ '2010-01-04' => [ 'USD' => '1.4389', 'RON' => '4.2240' ] ]);
    case $path === '/ted/notices/search' && $method === 'POST':
	$q = (string)($body['query'] ?? '');
	if (preg_match('/\bnosuch\b/', $q))
	    $out([ 'message' => "Unknown search field 'nosuch' found in expert query", 'error' => [ 'type' => 'QUERY_UNKNOWN_FIELD' ] ], 400);
	$all = $load('notices', []);
	if (preg_match('/publication-number=(\S+)/', $q, $m))
	    $all = array_filter($all, fn($n) => $n['publication-number'] === $m[1]);
	if (preg_match('/publication-date>=(\d{8})/', $q, $m))
	    $all = array_filter($all, fn($n) => str_replace('-', '', substr($n['publication-date'], 0, 10)) >= $m[1]);
	if (preg_match('/buyer-country IN \(([A-Z ]+)\)/', $q, $m))
	    $all = array_filter($all, fn($n) => (bool)array_intersect($n['buyer-country'], explode(' ', $m[1])));
	$all = array_values($all);
	usort($all, fn($a, $b) => strcmp($a['publication-date'], $b['publication-date']) ?: strcmp($a['publication-number'], $b['publication-number']));
	if (str_contains($q, 'SORT BY publication-date DESC'))
	    $all = array_reverse($all);
	$limit = max(1, (int)($body['limit'] ?? 10));
	$page = max(1, (int)($body['page'] ?? 1));
	$notices = array_map(fn($n) => array_intersect_key($n, array_flip((array)($body['fields'] ?? []))) + [ 'links' => [ 'pdf' => [ 'ENG' => "http://127.0.0.1:{$_SERVER['SERVER_PORT']}/ted/pdf/{$n['publication-number']}" ],
	    'html' => [ 'ENG' => "https://ted.europa.eu/en/notice/-/detail/{$n['publication-number']}" ], 'xml' => [ 'MUL' => "http://127.0.0.1:{$_SERVER['SERVER_PORT']}/ted/xml/{$n['publication-number']}" ] ] ],
	    array_slice($all, ($page - 1) * $limit, $limit));
	$out([ 'notices' => $notices, 'totalNoticeCount' => count($all), 'iterationNextToken' => null, 'timedOut' => false ]);
    case (bool)preg_match('~^/ted/(pdf|xml)/(\d+-\d{4})$~', $path, $m):
	header('Content-Type: ' . ($m[1] === 'pdf' ? 'application/pdf' : 'application/xml'));
	echo $m[1] === 'pdf' ? "%PDF-1.4\nnotice {$m[2]}\n%%EOF" : "<ContractNotice>{$m[2]}</ContractNotice>";
	exit;
}
$out([ 'message' => "no route {$method} {$path}" ], 404);
