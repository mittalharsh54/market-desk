<?php
/* Market Desk — offline dev server with synthetic data.
     php -S 127.0.0.1:8792 tools/dev-server.php
   then open http://127.0.0.1:8792/  (dev mode: no password, any login works)

   Serves the app's static files and runs the real api.php / market.php /
   market_engine.php, with every outside call (Yahoo, NSE, RSS, Claude) replaced
   by deterministic synthetic data. Storage goes to a temp folder. Only runs
   under PHP's built-in server. */

if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/') $path = '/index.html';
if ($path !== '/api.php') {
  $f = realpath($root . $path);
  if ($f && strpos($f, $root) === 0 && is_file($f) && preg_match('/\.(html|js|css|png|svg|ico)$/', $f)) return false; // static
  http_response_code(404); echo 'not found'; return true;
}
define('MD_DEV', true);
$GLOBALS['MD_CLAUDE_MOCK'] = function ($system, $user) {
  return ['model' => 'dev-mock', 'note' => "## Bottom line\n- **Dev server**: placeholder note. With a real key in config.php, Claude writes it from the computed data.\n- Data sent: " . strlen($user) . " bytes.\n\nNot investment advice — rule-based analysis; verify before trading."];
};

/* ---------- synthetic markets ---------- */
function dev_seed($s) { return crc32($s) & 0x7fffffff; }
function dev_last_session() { $t = time(); while (in_array((int) gmdate('N', $t + 19800), [6, 7], true)) $t -= 86400; return $t; }
function dev_series($sym, $n, $stepSec, $isIntraday) {
  $seed = dev_seed($sym); mt_srand($seed);
  $level = ['^NSEI' => 25000, '^BSESN' => 82000, '^NSEBANK' => 55000, '^INDIAVIX' => 14, '^VIX' => 16, '^TNX' => 4.2, '^IRX' => 4.0, 'INR=X' => 86.5, 'EURINR=X' => 99, 'DX-Y.NYB' => 99,
            'BZ=F' => 72, 'CL=F' => 68, 'GC=F' => 3400, 'SI=F' => 38, 'HG=F' => 4.6, 'NG=F' => 3.1, 'BTC-USD' => 95000, '^GSPC' => 6400, '^IXIC' => 21000, '^DJI' => 45000][$sym] ?? (100 + $seed % 4000);
  $drift = (($seed % 7) - 3) * 0.0004; $vol = strpos($sym, 'VIX') !== false ? 0.04 : 0.013;
  if ($isIntraday) { $drift /= 20; $vol /= 7; }
  $end = dev_last_session();
  $out = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
  if ($isIntraday) {
    $perDay = (int) round(22500 / $stepSec); $days = (int) ceil($n / $perDay); $ts = [];
    $d = $end; $list = [];
    while (count($list) < $days) { if ((int) gmdate('N', $d + 19800) < 6) $list[] = gmdate('Y-m-d', $d + 19800); $d -= 86400; }
    $list = array_reverse($list);
    $now = time();
    foreach ($list as $day) { $open = strtotime($day . ' 03:45:00 UTC'); for ($k = 0; $k < $perDay; $k++) { $t = $open + $k * $stepSec; if ($t > $now) break; $ts[] = $t; } }
  } else { $ts = []; $d = $end; while (count($ts) < $n) { if ((int) gmdate('N', $d + 19800) < 6) $ts[] = strtotime(gmdate('Y-m-d', $d + 19800) . ' 04:00:00 UTC'); $d -= 86400; } $ts = array_reverse($ts); }
  $p = $level * (1 - $drift * count($ts));
  foreach ($ts as $t) {
    $r = $drift + $vol * ((mt_rand() / mt_getrandmax()) * 2 - 1);
    $o = $p; $c = max(0.01, $p * (1 + $r)); $h = max($o, $c) * (1 + $vol * 0.3 * mt_rand(0, 100) / 100); $l = min($o, $c) * (1 - $vol * 0.3 * mt_rand(0, 100) / 100);
    $out['t'][] = $t; $out['o'][] = $o; $out['h'][] = $h; $out['l'][] = $l; $out['c'][] = $c;
    $out['v'][] = ($sym[0] === '^' || strpos($sym, '=') !== false) ? 0 : (int) (($isIntraday ? 2e4 : 1.5e6) * (1 + ($r > 0 ? 0.3 : 0) + mt_rand(0, 60) / 100)); $p = $c;
  }
  return $out;
}
function dev_chart($url) {
  if (!preg_match('~/chart/([^?]+)\?range=(\w+)&interval=(\w+)~', $url, $m)) return [404, ''];
  $sym = rawurldecode($m[1]); $range = $m[2]; $int = $m[3];
  if ($sym === 'NOSUCH.NS') return [404, '{"chart":{"result":null,"error":{"code":"Not Found"}}}'];
  $bars = ['2y' => 500, '1y' => 250, '6mo' => 125, '3mo' => 63][$range] ?? 250; $step = 86400; $intra = false;
  if ($int === '5m') { $step = 300; $bars = 75 * (int) $range; $intra = true; }
  if ($int === '15m') { $step = 900; $bars = 25 * (int) $range; $intra = true; }
  $C = dev_series($sym, $bars, $step, $intra);
  if ($intra) { /* land intraday bars on the daily close so timeframes agree, as real data does */
    $D = dev_series($sym, 500, 86400, false); $k = end($D['c']) / end($C['c']);
    foreach (['o', 'h', 'l', 'c'] as $f) $C[$f] = array_map(function ($x) use ($k) { return $x * $k; }, $C[$f]);
  }
  $name = ['RELIANCE.NS' => 'Reliance Industries Limited', 'TCS.NS' => 'Tata Consultancy Services Limited', 'HDFCBANK.NS' => 'HDFC Bank Limited'][$sym] ?? (preg_replace('/\.NS$/', '', $sym) . ' Limited');
  return [200, json_encode(['chart' => ['result' => [['meta' => ['symbol' => $sym, 'currency' => 'INR', 'longName' => $name, 'fullExchangeName' => 'NSE', 'regularMarketPrice' => end($C['c']), 'regularMarketTime' => end($C['t'])],
    'timestamp' => $C['t'], 'indicators' => ['quote' => [['open' => $C['o'], 'high' => $C['h'], 'low' => $C['l'], 'close' => $C['c'], 'volume' => $C['v']]]]]]]])];
}
function dev_rss($name) {
  $H = ['Sensex, Nifty climb as FIIs turn buyers; banks lead gains', 'Rupee slips to record low as crude oil prices surge', 'RBI keeps repo rate unchanged, stance neutral; inflation eases',
        'IT stocks rally after strong US tech earnings', 'Metal shares tumble as China demand fears grow', 'Fed signals rate cut path; Wall Street advances', 'Crude oil jumps on Middle East tensions',
        'Auto sales robust in festive season; Maruti, M&M gain', 'SEBI tightens F&O rules for retail traders', 'Q2 results: Reliance profit rises, beats estimates', 'Pharma stocks gain on weak rupee', 'GST collections grow 10% in September'];
  mt_srand(dev_seed($name)); $items = '';
  foreach ($H as $i => $h) if (mt_rand(0, 2)) $items .= '<item><title><![CDATA[' . $h . ']]></title><link>https://example.com/' . $i . '</link><pubDate>' . gmdate('D, d M Y H:i:s', time() - $i * 3600 - mt_rand(0, 3000)) . ' GMT</pubDate></item>';
  return [200, '<?xml version="1.0"?><rss version="2.0"><channel><title>' . htmlspecialchars($name) . '</title>' . $items . '</channel></rss>'];
}
$GLOBALS['MKT_HTTP_MOCK'] = function ($url) {
  $mk = function ($p) { return ['code' => $p[0], 'body' => $p[1], 'error' => '', 'headers' => "HTTP/1.1 200 OK\r\nset-cookie: A3=dev; Path=/\r\n"]; };
  if (strpos($url, '/v8/finance/chart/') !== false) return $mk(dev_chart($url));
  if (strpos($url, 'getcrumb') !== false) return $mk([200, 'devCrumb']);
  if (strpos($url, 'fc.yahoo.com') !== false || $url === 'https://www.nseindia.com/') return $mk([200, '<html></html>']);
  if (strpos($url, 'quoteSummary') !== false) {
    preg_match('~quoteSummary/([^?]+)~', $url, $m); $s = rawurldecode($m[1]); mt_srand(dev_seed($s)); $r = function ($a, $b) { return $a + ($b - $a) * mt_rand() / mt_getrandmax(); };
    return $mk([200, json_encode(['quoteSummary' => ['result' => [[
      'price' => ['longName' => preg_replace('/\.NS$/', '', $s) . ' Limited', 'marketCap' => ['raw' => $r(5e11, 2e13)]],
      'summaryDetail' => ['trailingPE' => ['raw' => $r(12, 60)], 'forwardPE' => ['raw' => $r(10, 50)], 'dividendYield' => ['raw' => $r(0, 0.03)], 'beta' => ['raw' => $r(0.6, 1.4)]],
      'defaultKeyStatistics' => ['priceToBook' => ['raw' => $r(1, 12)], 'pegRatio' => ['raw' => $r(0.6, 3)], 'heldPercentInsiders' => ['raw' => $r(0.1, 0.7)], 'heldPercentInstitutions' => ['raw' => $r(0.1, 0.5)]],
      'financialData' => ['returnOnEquity' => ['raw' => $r(0.05, 0.3)], 'returnOnAssets' => ['raw' => $r(0.01, 0.15)], 'operatingMargins' => ['raw' => $r(0.05, 0.3)], 'profitMargins' => ['raw' => $r(0.02, 0.2)],
        'revenueGrowth' => ['raw' => $r(-0.05, 0.25)], 'earningsGrowth' => ['raw' => $r(-0.2, 0.4)], 'debtToEquity' => ['raw' => $r(5, 150)], 'currentRatio' => ['raw' => $r(0.8, 2.5)],
        'freeCashflow' => ['raw' => $r(-1e10, 5e10)], 'targetMeanPrice' => ['raw' => 0], 'recommendationMean' => ['raw' => $r(1.5, 3.5)], 'recommendationKey' => 'buy', 'numberOfAnalystOpinions' => ['raw' => (int) $r(5, 40)]],
      'assetProfile' => ['sector' => 'Energy', 'industry' => 'Oil & Gas Refining & Marketing', 'longBusinessSummary' => 'Synthetic company used by the offline dev server.'],
      'calendarEvents' => ['earnings' => ['earningsDate' => [['raw' => time() + 12 * 86400]]]],
      'recommendationTrend' => ['trend' => [['strongBuy' => 8, 'buy' => 12, 'hold' => 6, 'sell' => 2, 'strongSell' => 1]]]]]]])]);
  }
  if (strpos($url, 'fiidiiTradeReact') !== false) return $mk([200, json_encode([['category' => 'DII **', 'date' => gmdate('d-M-Y'), 'netValue' => '2140.55'], ['category' => 'FII/FPI **', 'date' => gmdate('d-M-Y'), 'netValue' => '-1320.10']])]);
  if (strpos($url, 'option-chain-indices') !== false) {
    $rows = []; foreach (range(24000, 26000, 100) as $k) $rows[] = ['strikePrice' => $k, 'expiryDate' => '30-Sep-2026', 'CE' => ['openInterest' => max(100, 9000 - abs($k - 25500) * 8)], 'PE' => ['openInterest' => max(100, 9500 - abs($k - 24800) * 8)]];
    return $mk([200, json_encode(['records' => ['data' => $rows, 'underlyingValue' => 25000, 'expiryDates' => ['30-Sep-2026']]])]);
  }
  if (strpos($url, '/v1/finance/search') !== false) return $mk([200, json_encode(['quotes' => [['symbol' => 'RELIANCE.NS', 'longname' => 'Reliance Industries Limited', 'exchDisp' => 'NSE'], ['symbol' => 'RELIANCE.BO', 'longname' => 'Reliance Industries Limited', 'exchDisp' => 'Bombay']]])]);
  if (strpos($url, 'rss') !== false || strpos($url, '.xml') !== false || strpos($url, '.cms') !== false) return $mk(dev_rss($url));
  return $mk([404, '']);
};

require $root . '/api.php';
return true;
