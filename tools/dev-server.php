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
$GLOBALS['MD_TG_MOCK'] = function ($method, $params) { // Telegram: log what would be sent
  if ($method === 'getUpdates') return ['ok' => true, 'result' => [['message' => ['chat' => ['id' => 12345]]]]];
  file_put_contents(sys_get_temp_dir() . '/market-desk-dev-telegram.log', ($params['text'] ?? '') . "\n---\n", FILE_APPEND);
  return ['ok' => true];
};
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
  $blocked = getenv('MD_MOCK_YAHOO_429') === '1';
  if ($blocked && strpos($url, 'yahoo.com') !== false) return $mk([429, 'Too Many Requests']);
  if (strpos($url, '/v8/finance/chart/') !== false) return $mk(dev_chart($url));
  /* Upstox: instrument list + candles, built from the same synthetic series */
  if (strpos($url, 'assets.upstox.com') !== false) {
    $rows = [];
    foreach (['RELIANCE', 'TCS', 'HDFCBANK', 'INFY', 'SBIN', 'ITC', 'M&M', 'BAJAJ-AUTO', 'TMPV', 'LT', 'ICICIBANK', 'AXISBANK', 'KOTAKBANK', 'BHARTIARTL', 'TITAN', 'NTPC', 'NIFTYBEES', 'MID150BEES', 'MON100', 'GOLDBEES', 'LIQUIDBEES'] as $i => $t)
      $rows[] = ['segment' => 'NSE_EQ', 'name' => $t . ' LTD', 'exchange' => 'NSE', 'isin' => 'INE' . sprintf('%06d', $i) . 'A01', 'instrument_type' => 'EQ', 'instrument_key' => 'NSE_EQ|INE' . sprintf('%06d', $i) . 'A01', 'trading_symbol' => $t];
    foreach (['Nifty 50', 'Nifty Bank', 'India VIX', 'Nifty IT', 'Nifty Auto', 'Nifty Pharma', 'Nifty FMCG', 'Nifty Metal', 'Nifty Realty', 'Nifty Energy', 'Nifty PSU Bank', 'Nifty Fin Service', 'Nifty Infra', 'Nifty Media', 'Nifty PSE', 'Nifty Midcap 50'] as $n)
      $rows[] = ['segment' => 'NSE_INDEX', 'name' => $n, 'exchange' => 'NSE', 'instrument_type' => 'INDEX', 'instrument_key' => 'NSE_INDEX|' . $n, 'trading_symbol' => strtoupper($n)];
    $rows[] = ['segment' => 'NSE_FO', 'name' => 'NIFTY FUT', 'instrument_type' => 'FUT', 'instrument_key' => 'NSE_FO|1', 'trading_symbol' => 'NIFTY26OCTFUT'];
    return $mk([200, gzencode(json_encode($rows))]);
  }
  if (strpos($url, 'api.upstox.com') !== false) {
    preg_match('~historical-candle/(intraday/)?([^/]+)/(day|minutes/(\d+))~', $url, $m);
    $key = rawurldecode($m[2]); $intra = $m[1] !== ''; $step = ($m[3] === 'day') ? 86400 : 60 * (int) $m[4];
    $sym = strpos($key, 'NSE_INDEX|Nifty 50') === 0 ? '^NSEI' : (strpos($key, 'India VIX') !== false ? '^INDIAVIX' : $key);
    $bars = $step === 86400 ? 500 : ($intra ? 75 : 75 * 8);
    $C = dev_series($sym, $bars, $step === 86400 ? 86400 : 300, $step !== 86400);
    if ($step === 900) $C = mk_resample($C, 3);
    $today = gmdate('Y-m-d', time() + 19800); $rows = [];
    for ($i = count($C['c']) - 1; $i >= 0; $i--) {
      $d = gmdate('Y-m-d', $C['t'][$i] + 19800);
      if ($intra ? $d !== $today : $d === $today) continue; // history excludes today; intraday is today only
      $rows[] = [gmdate('Y-m-d\TH:i:s', $C['t'][$i] + 19800) . '+05:30', $C['o'][$i], $C['h'][$i], $C['l'][$i], $C['c'][$i], $C['v'][$i], 0];
    }
    return $mk([200, json_encode(['status' => 'success', 'data' => ['candles' => $rows]])]);
  }
  /* CNBC: daily bars + quotes */
  if (strpos($url, 'ts-api.cnbc.com') !== false) {
    preg_match('~symbol=([^&]+)~', $url, $m); $cs = rawurldecode($m[1]); $C = dev_series('cnbc:' . $cs, 260, 86400, false); $bars = [];
    for ($i = 0; $i < count($C['c']); $i++) $bars[] = ['open' => sprintf('%.4f', $C['o'][$i]), 'high' => sprintf('%.4f', $C['h'][$i]), 'low' => sprintf('%.4f', $C['l'][$i]), 'close' => sprintf('%.4f', $C['c'][$i]), 'volume' => 0, 'tradeTimeinMills' => $C['t'][$i] * 1000];
    return $mk([200, json_encode(['barData' => ['priceBars' => $bars]])]);
  }
  if (strpos($url, 'quote.cnbc.com') !== false) {
    preg_match('~symbols=([^&]+)~', $url, $m); $q = [];
    foreach (explode('|', rawurldecode($m[1])) as $cs) $q[] = ['symbol' => $cs, 'code' => 0, 'name' => $cs . ' (CNBC)', 'last' => '1,234.50', 'last_time' => gmdate('Y-m-d\TH:i:s', time() - 3600) . '.000+0000', 'open' => '1,220.00', 'high' => '1,240.00', 'low' => '1,210.00',
      'pe' => '24.10', 'eps' => '51.2', 'mktcapView' => '16.2T', 'dividendyield' => '0.55%', 'beta' => '1.05', 'psales' => '1.4', 'ROETTM' => '17.5%', 'NETPROFTTM' => '11.2%', 'GROSMGNTTM' => '38.0%', 'DEBTEQTYQ' => '42.0%', 'currencyCode' => 'INR'];
    return $mk([200, json_encode(['FormattedQuoteResult' => ['FormattedQuote' => $q]])]);
  }
  if (strpos($url, '/api/allIndices') !== false) return $mk([200, json_encode(['data' => [['index' => 'NIFTY 50', 'last' => 25000, 'percentChange' => -0.4, 'pe' => '22.8', 'pb' => '3.6', 'dy' => '1.25', 'advances' => '21', 'declines' => '29']]])]);
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
