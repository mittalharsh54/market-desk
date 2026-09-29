<?php
/* =====================================================================
   Market Desk — data + orchestration (market.php)
   ---------------------------------------------------------------------
   The page (index.html) asks api.php for mkt_* actions; api.php checks the
   login and hands them to mkt_dispatch() here. The maths lives in market_engine.php; this file only fetches and
   assembles.

   Sources (all free, no key needed):
     Yahoo Finance  prices for NSE/BSE stocks, Indian & world indices, FX,
                    commodities, US yields (v8 chart); fundamentals and analyst
                    views (v10 quoteSummary, needs a cookie+crumb it fetches itself)
     NSE India      FII/DII cash flows and the Nifty option chain (PCR, max pain) —
                    NSE often blocks datacentre IPs, so both are best-effort and
                    the page lets you type flows in by hand instead
     RSS            Economic Times, Moneycontrol, Mint, Business Standard,
                    Google News — headlines scored for sentiment and topic
     You            India macro numbers (RBI rate/stance, CPI, GDP, PMI, GST,
                    monsoon…) typed into the page, since no free live feed exists

   Caching: every fetch is kept in data/ for a TTL that is short while NSE is
   open and long when it is shut, so a page refresh never hammers a source.

   Actions:
     mkt_status      market clock + which keys are set
     mkt_macro       the whole national + international picture (regime score,
                     global cues, sectors, flows, option chain, news, events)
     mkt_analyze     one stock in depth: intraday / swing / long-term calls with
                     entry, stop, targets, size; technicals, fundamentals, sector,
                     macro fit, backtests, news
     mkt_scan        rank a universe (Nifty 50, sectors, your watchlist)
     mkt_search      symbol lookup
     mkt_inputs(_set) India macro inputs + manual FII/DII
     mkt_ai          Claude writes a research note from the computed data
   ===================================================================== */

require_once __DIR__ . '/market_engine.php';
require_once __DIR__ . '/sources.php';
require_once __DIR__ . '/intraday.php';
require_once __DIR__ . '/momentum.php';
/* the volume gate the daily learning run settled on (rules.json) */
if (function_exists('md_rules')) $GLOBALS['MK_VOL_MULT'] = (float) md_rules()['intraday']['vol_mult'];

const MKT_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

/* ---------- universes ---------- */
function mkt_universes() {
  return [
    'nifty50' => ['label' => 'Nifty 50', 'symbols' => ['ADANIENT', 'ADANIPORTS', 'APOLLOHOSP', 'ASIANPAINT', 'AXISBANK', 'BAJAJ-AUTO', 'BAJFINANCE', 'BAJAJFINSV', 'BEL', 'BHARTIARTL',
      'CIPLA', 'COALINDIA', 'DRREDDY', 'EICHERMOT', 'ETERNAL', 'GRASIM', 'HCLTECH', 'HDFCBANK', 'HDFCLIFE', 'HINDALCO', 'HINDUNILVR', 'ICICIBANK', 'INDIGO', 'INFY', 'ITC',
      'JIOFIN', 'JSWSTEEL', 'KOTAKBANK', 'LT', 'M&M', 'MARUTI', 'MAXHEALTH', 'NESTLEIND', 'NTPC', 'ONGC', 'POWERGRID', 'RELIANCE', 'SBILIFE', 'SBIN', 'SHRIRAMFIN',
      'SUNPHARMA', 'TATACONSUM', 'TMPV', 'TATASTEEL', 'TCS', 'TECHM', 'TITAN', 'TRENT', 'ULTRACEMCO', 'WIPRO']],
    'bank' => ['label' => 'Banks', 'symbols' => ['HDFCBANK', 'ICICIBANK', 'SBIN', 'KOTAKBANK', 'AXISBANK', 'INDUSINDBK', 'BANKBARODA', 'PNB', 'CANBK', 'FEDERALBNK', 'IDFCFIRSTB', 'AUBANK']],
    'it' => ['label' => 'IT', 'symbols' => ['TCS', 'INFY', 'HCLTECH', 'WIPRO', 'TECHM', 'LTIM', 'PERSISTENT', 'COFORGE', 'MPHASIS', 'OFSS']],
    'auto' => ['label' => 'Auto', 'symbols' => ['MARUTI', 'M&M', 'TMPV', 'BAJAJ-AUTO', 'EICHERMOT', 'HEROMOTOCO', 'TVSMOTOR', 'ASHOKLEY', 'BOSCHLTD', 'MOTHERSON']],
    'pharma' => ['label' => 'Pharma & healthcare', 'symbols' => ['SUNPHARMA', 'DRREDDY', 'CIPLA', 'DIVISLAB', 'LUPIN', 'AUROPHARMA', 'TORNTPHARM', 'ZYDUSLIFE', 'MANKIND', 'ALKEM', 'APOLLOHOSP', 'MAXHEALTH']],
    'fmcg' => ['label' => 'FMCG', 'symbols' => ['HINDUNILVR', 'ITC', 'NESTLEIND', 'BRITANNIA', 'TATACONSUM', 'DABUR', 'GODREJCP', 'MARICO', 'COLPAL', 'VBL']],
    'metal' => ['label' => 'Metals & mining', 'symbols' => ['TATASTEEL', 'JSWSTEEL', 'HINDALCO', 'VEDL', 'SAIL', 'NMDC', 'JINDALSTEL', 'NATIONALUM', 'HINDZINC', 'COALINDIA']],
    'energy' => ['label' => 'Oil, gas & power', 'symbols' => ['RELIANCE', 'ONGC', 'NTPC', 'POWERGRID', 'BPCL', 'IOC', 'GAIL', 'TATAPOWER', 'ADANIGREEN', 'OIL', 'HINDPETRO', 'ADANIPOWER']],
    'psu' => ['label' => 'PSU & defence', 'symbols' => ['BEL', 'HAL', 'MAZDOCK', 'BDL', 'IRFC', 'RVNL', 'PFC', 'RECLTD', 'SBIN', 'BHEL', 'NHPC', 'COCHINSHIP']],
    'midcap' => ['label' => 'Midcap momentum', 'symbols' => ['DIXON', 'PERSISTENT', 'COFORGE', 'POLYCAB', 'CUMMINSIND', 'LODHA', 'GODREJPROP', 'PHOENIXLTD', 'INDHOTEL', 'BSE', 'CDSL', 'ANGELONE', 'KALYANKJIL', 'SUZLON', 'VOLTAS']],
  ];
}

/* RELIANCE -> RELIANCE.NS; keeps .BO, indices (^) and FX/futures symbols as typed */
function mkt_norm_symbol($s) {
  $s = strtoupper(trim((string) $s));
  $s = preg_replace('/^(NSE|BSE):/', '', $s);
  if ($s === '' || !preg_match('/^[A-Z0-9\^&\-\._=]{1,25}$/', $s)) return null;
  if ($s[0] === '^' || strpos($s, '=') !== false || preg_match('/\.(NS|BO|SS|SZ|HK|L|T)$/', $s) || strpos($s, '-USD') !== false) return $s;
  if ($s === 'NIFTY' || $s === 'NIFTY50') return '^NSEI';
  if ($s === 'BANKNIFTY') return '^NSEBANK';
  if ($s === 'SENSEX') return '^BSESN';
  return $s . '.NS';
}

/* ---------- cache (files in data/, see lib.php) ---------- */
function mkt_cache_get($key, $ttl) {
  $raw = md_store_get($key);
  if (!$raw) return null;
  $j = json_decode($raw, true);
  if (!is_array($j) || !isset($j['at'])) return null;
  if ($ttl !== null && time() - $j['at'] > $ttl) return null;
  return $j['d'];
}
function mkt_cache_set($key, $data) { md_store_set($key, json_encode(['at' => time(), 'd' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
function mkt_ttl($kind) {
  $open = mk_market_status()['open'];
  $T = ['intraday' => [60, 1800], 'daily' => [300, 3600], 'macro' => [180, 1800], 'news' => [600, 1800], 'fund' => [43200, 43200], 'nse' => [300, 3600]];
  return $T[$kind][$open ? 0 : 1];
}

/* ---------- HTTP: many requests at once, politely ---------- */
function mkt_http_multi(array $reqs, $timeout = 15, $concurrency = 12) {
  if (isset($GLOBALS['MKT_HTTP_MOCK']) && is_callable($GLOBALS['MKT_HTTP_MOCK'])) {
    $out = []; foreach ($reqs as $k => $r) $out[$k] = call_user_func($GLOBALS['MKT_HTTP_MOCK'], $r['url'], $r); return $out;
  }
  $out = []; $keys = array_keys($reqs);
  foreach (array_chunk($keys, $concurrency) as $chunk) {
    $mh = curl_multi_init(); $hs = [];
    foreach ($chunk as $k) {
      $r = $reqs[$k]; $ch = curl_init($r['url']);
      $hdr = array_merge(['Accept: application/json, text/xml, application/xml, */*', 'Accept-Language: en-US,en;q=0.9'], $r['headers'] ?? []);
      curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => MKT_UA, CURLOPT_HTTPHEADER => $hdr, CURLOPT_ENCODING => '', CURLOPT_HEADER => !empty($r['want_headers'])]);
      if (!empty($r['cookie'])) curl_setopt($ch, CURLOPT_COOKIE, $r['cookie']);
      curl_multi_add_handle($mh, $ch); $hs[$k] = $ch;
    }
    do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
    foreach ($hs as $k => $ch) {
      $body = curl_multi_getcontent($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $hsz = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
      $res = ['code' => $code, 'body' => (string) $body, 'error' => curl_error($ch)];
      if (!empty($reqs[$k]['want_headers'])) { $res['headers'] = substr((string) $body, 0, $hsz); $res['body'] = substr((string) $body, $hsz); }
      $out[$k] = $res; curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
  }
  return $out;
}
function mkt_http($url, $opts = []) { return mkt_http_multi(['x' => ['url' => $url] + $opts])['x']; }
function mkt_cookies_from($headers) {
  $jar = [];
  if (preg_match_all('/^set-cookie:\s*([^=;\s]+)=([^;\r\n]*)/im', (string) $headers, $m, PREG_SET_ORDER)) foreach ($m as $c) $jar[$c[1]] = $c[2];
  return $jar;
}
function mkt_cookie_str(array $jar) { $a = []; foreach ($jar as $k => $v) $a[] = $k . '=' . $v; return implode('; ', $a); }

/* ---------- Yahoo: price history ---------- */
function mkt_chart_url($sym, $range, $interval) {
  return 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($sym) . '?range=' . $range . '&interval=' . $interval . '&includePrePost=false&events=div%2Csplit';
}
function mkt_parse_chart($body) {
  $j = json_decode((string) $body, true); $r = $j['chart']['result'][0] ?? null;
  if (!$r || empty($r['timestamp'])) return null;
  $q = $r['indicators']['quote'][0] ?? [];
  $C = mk_clean(['t' => $r['timestamp'], 'o' => $q['open'] ?? [], 'h' => $q['high'] ?? [], 'l' => $q['low'] ?? [], 'c' => $q['close'] ?? [], 'v' => $q['volume'] ?? []]);
  if (count($C['c']) < 2) return null;
  $m = $r['meta'] ?? [];
  /* the live price is fresher than the last bar's close during the session */
  if (!empty($m['regularMarketPrice']) && !empty($m['regularMarketTime']) && mk_ist_date($m['regularMarketTime']) === mk_ist_date(end($C['t']))) {
    $n = count($C['c']) - 1; $C['c'][$n] = (float) $m['regularMarketPrice'];
    $C['h'][$n] = max($C['h'][$n], $C['c'][$n]); $C['l'][$n] = min($C['l'][$n], $C['c'][$n]);
  }
  $C['meta'] = ['symbol' => $m['symbol'] ?? null, 'currency' => $m['currency'] ?? null, 'name' => $m['longName'] ?? ($m['shortName'] ?? null), 'exchange' => $m['fullExchangeName'] ?? ($m['exchangeName'] ?? null),
                'price' => $m['regularMarketPrice'] ?? null, 'time' => $m['regularMarketTime'] ?? null, 'prev_close' => $m['chartPreviousClose'] ?? ($m['previousClose'] ?? null),
                'type' => $m['instrumentType'] ?? null];
  return $C;
}
/* specs: key => [symbol, range, interval]; returns key => candles|null (cached) */
function mkt_charts(array $specs, $force = false) {
  $out = []; $need = []; $cks = [];
  foreach ($specs as $k => $s) {
    list($sym, $range, $int) = $s; $ck = "chart|$sym|$range|$int"; $cks[$k] = $ck;
    $ttl = in_array($int, ['1m', '2m', '5m', '15m', '30m', '60m'], true) ? mkt_ttl('intraday') : mkt_ttl('daily');
    $hit = $force ? null : mkt_cache_get($ck, $ttl);
    if ($hit) $out[$k] = $hit; else $need[$k] = ['url' => mkt_chart_url($sym, $range, $int)];
  }
  $failed = [];
  if ($need && !mkt_yahoo_blocked()) {
    $res = mkt_http_multi($need);
    $n429 = 0;
    foreach ($need as $k => $r) {
      $C = ($res[$k]['code'] === 200) ? mkt_parse_chart($res[$k]['body']) : null;
      if ($res[$k]['code'] === 429) $n429++;
      if ($C) { $C['meta']['source'] = 'Yahoo'; mkt_cache_set($cks[$k], $C); $out[$k] = $C; } else $failed[$k] = $specs[$k];
    }
    if ($n429 && $n429 >= count($need) / 2) mkt_yahoo_mark_blocked(); // this server is being refused: go straight to the fallbacks for a while
  } else foreach ($need as $k => $r) $failed[$k] = $specs[$k];
  if ($failed) {
    $fb = mkt_fallback_charts($failed);
    foreach ($failed as $k => $s) {
      if (!empty($fb[$k])) { mkt_cache_set($cks[$k], $fb[$k]); $out[$k] = $fb[$k]; continue; }
      $stale = mkt_cache_get($cks[$k], null); $out[$k] = $stale ?: null; if ($stale) $out[$k]['meta']['stale'] = true;
    }
  }
  return $out;
}

/* ---------- Yahoo: fundamentals (cookie + crumb) ---------- */
function mkt_yahoo_auth($force = false) {
  if (!$force) { $a = mkt_cache_get('yh_auth', 6 * 3600); if ($a && !empty($a['crumb'])) return $a; }
  $jar = [];
  foreach (['https://fc.yahoo.com/', 'https://finance.yahoo.com/quote/RELIANCE.NS/'] as $u) {
    $r = mkt_http($u, ['want_headers' => true, 'headers' => ['Accept: text/html']]);
    $jar = array_merge($jar, mkt_cookies_from($r['headers'] ?? ''));
    if (isset($jar['A3']) || isset($jar['B'])) break;
  }
  if (!$jar) return null;
  $cookie = mkt_cookie_str($jar);
  $r = mkt_http('https://query2.finance.yahoo.com/v1/test/getcrumb', ['cookie' => $cookie, 'headers' => ['Accept: text/plain']]);
  $crumb = trim($r['body']);
  if ($r['code'] !== 200 || $crumb === '' || strlen($crumb) > 40 || strpos($crumb, '<') !== false) return null;
  $a = ['cookie' => $cookie, 'crumb' => $crumb]; mkt_cache_set('yh_auth', $a); return $a;
}
function mkt_quote_summary($sym) {
  $ck = "qs|$sym"; $hit = mkt_cache_get($ck, mkt_ttl('fund')); if ($hit) return $hit;
  if (mkt_yahoo_blocked()) return mkt_cnbc_fundamentals($sym) ?: mkt_cache_get($ck, null);
  $mods = 'price,summaryDetail,defaultKeyStatistics,financialData,assetProfile,calendarEvents,recommendationTrend';
  for ($try = 0; $try < 2; $try++) {
    $a = mkt_yahoo_auth($try > 0); if (!$a) break;
    $r = mkt_http('https://query2.finance.yahoo.com/v10/finance/quoteSummary/' . rawurlencode($sym) . '?modules=' . $mods . '&crumb=' . rawurlencode($a['crumb']), ['cookie' => $a['cookie']]);
    if ($r['code'] === 200) { $j = json_decode($r['body'], true); $q = $j['quoteSummary']['result'][0] ?? null; if ($q) { mkt_cache_set($ck, $q); return $q; } }
    if ($r['code'] === 429) { mkt_yahoo_mark_blocked(); break; }
    if ($r['code'] !== 401 && $r['code'] !== 403) break;
  }
  return mkt_cnbc_fundamentals($sym) ?: mkt_cache_get($ck, null);
}

/* ---------- news (RSS) ---------- */
function mkt_market_feeds() {
  return [
    'ET Markets' => 'https://economictimes.indiatimes.com/markets/rssfeeds/1977021501.cms',
    'ET Economy' => 'https://economictimes.indiatimes.com/news/economy/rssfeeds/1373380680.cms',
    'Moneycontrol' => 'https://www.moneycontrol.com/rss/marketreports.xml',
    'Moneycontrol Economy' => 'https://www.moneycontrol.com/rss/economy.xml',
    'Mint Markets' => 'https://www.livemint.com/rss/markets',
    'Business Standard' => 'https://www.business-standard.com/rss/markets-106.rss',
    'Google News India' => 'https://news.google.com/rss/search?q=' . rawurlencode('Sensex OR Nifty OR RBI OR FII when:2d') . '&hl=en-IN&gl=IN&ceid=IN:en',
    'Google News Global' => 'https://news.google.com/rss/search?q=' . rawurlencode('"Federal Reserve" OR "Wall Street" OR "crude oil" OR "Treasury yields" when:2d') . '&hl=en-US&gl=US&ceid=US:en',
  ];
}
function mkt_parse_rss($xml, $source) {
  if (!$xml || stripos($xml, '<rss') === false && stripos($xml, '<feed') === false) return [];
  $prev = libxml_use_internal_errors(true); $x = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA); libxml_use_internal_errors($prev);
  if (!$x) return [];
  $out = []; $items = isset($x->channel->item) ? $x->channel->item : (isset($x->entry) ? $x->entry : []);
  foreach ($items as $it) {
    $title = trim(html_entity_decode(strip_tags((string) $it->title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($title === '') continue;
    $src = $source;
    if (isset($it->source) && (string) $it->source !== '') $src = (string) $it->source;
    elseif (strpos($source, 'Google') === 0 && preg_match('/^(.*) - ([^-]{2,40})$/u', $title, $m)) { $title = $m[1]; $src = $m[2]; }
    $link = (string) $it->link; if ($link === '' && isset($it->link['href'])) $link = (string) $it->link['href'];
    if (!preg_match('~^https?://~i', $link)) $link = ''; // feeds are outside input: no javascript: links
    $ts = strtotime((string) ($it->pubDate ?: $it->updated ?: '')) ?: null;
    $out[] = ['title' => $title, 'link' => $link, 'source' => $src, 'ts' => $ts];
  }
  return $out;
}
function mkt_news(array $feeds, $ck, $limit = 60) {
  $hit = mkt_cache_get($ck, mkt_ttl('news')); if ($hit) return $hit;
  $reqs = []; foreach ($feeds as $name => $u) $reqs[$name] = ['url' => $u, 'headers' => ['Accept: application/rss+xml, application/xml, text/xml']];
  $res = mkt_http_multi($reqs, 10); $all = []; $seen = []; $ok = [];
  foreach ($res as $name => $r) {
    if ($r['code'] !== 200) continue; $items = mkt_parse_rss($r['body'], $name); if ($items) $ok[] = $name;
    foreach ($items as $it) { $k = preg_replace('/[^a-z0-9]/', '', strtolower($it['title'])); $k = substr($k, 0, 60); if (isset($seen[$k])) continue; $seen[$k] = 1; $all[] = $it; }
  }
  $cut = time() - 4 * 86400; $all = array_values(array_filter($all, function ($i) use ($cut) { return !$i['ts'] || $i['ts'] >= $cut; }));
  usort($all, function ($a, $b) { return ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0); });
  $scored = mk_news_score(array_slice($all, 0, $limit)); $scored['sources_ok'] = $ok; $scored['sources_tried'] = array_keys($feeds);
  if ($all) mkt_cache_set($ck, $scored); else { $stale = mkt_cache_get($ck, null); if ($stale) return $stale; }
  return $scored;
}
function mkt_stock_news($name, $sym) {
  $base = preg_replace('/\.(NS|BO)$/', '', $sym);
  $clean = trim(preg_replace('/\b(limited|ltd\.?|corporation|corp\.?|industries|india)\b/i', '', (string) $name)) ?: $base;
  $q = '"' . $clean . '" (share OR stock OR results OR NSE) when:10d';
  return mkt_news(['Google News' => 'https://news.google.com/rss/search?q=' . rawurlencode($q) . '&hl=en-IN&gl=IN&ceid=IN:en'], 'news|' . $sym, 25);
}

/* ---------- NSE (best-effort: blocked from many hosting IPs) ---------- */
function mkt_nse_json($path) {
  $ck = 'nse|' . $path; $hit = mkt_cache_get($ck, mkt_ttl('nse')); if ($hit) return $hit;
  $jarC = mkt_cache_get('nse_cookie', 240);
  if (!$jarC) {
    $r = mkt_http('https://www.nseindia.com/', ['want_headers' => true, 'headers' => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en-IN,en;q=0.9']]);
    $jar = mkt_cookies_from($r['headers'] ?? ''); if (!$jar) return null;
    $jarC = mkt_cookie_str($jar); mkt_cache_set('nse_cookie', $jarC);
  }
  $r = mkt_http('https://www.nseindia.com' . $path, ['cookie' => $jarC, 'headers' => ['Referer: https://www.nseindia.com/', 'X-Requested-With: XMLHttpRequest']]);
  $j = $r['code'] === 200 ? json_decode($r['body'], true) : null;
  if ($j) { mkt_cache_set($ck, $j); return $j; }
  return mkt_cache_get($ck, 86400);
}
function mkt_flows() {
  $j = mkt_nse_json('/api/fiidiiTradeReact'); if (!is_array($j)) return null;
  $o = ['source' => 'NSE'];
  foreach ($j as $row) {
    $cat = strtoupper($row['category'] ?? ''); $net = isset($row['netValue']) ? (float) str_replace(',', '', $row['netValue']) : null;
    if (strpos($cat, 'FII') !== false || strpos($cat, 'FPI') !== false) { $o['fii_net'] = $net; $o['date'] = $row['date'] ?? null; }
    elseif (strpos($cat, 'DII') !== false) $o['dii_net'] = $net;
  }
  return isset($o['fii_net']) ? $o : null;
}
function mkt_option_chain($symbol = 'NIFTY') {
  $j = mkt_nse_json('/api/option-chain-indices?symbol=' . $symbol);
  $rows = $j['records']['data'] ?? []; $under = $j['records']['underlyingValue'] ?? null; $exp = $j['records']['expiryDates'][0] ?? null;
  if (!$rows) {
    $info = mkt_nse_json('/api/option-chain-contract-info?symbol=' . $symbol); $exp = $info['expiryDates'][0] ?? null;
    if ($exp) { $j = mkt_nse_json('/api/option-chain-v3?type=Indices&symbol=' . $symbol . '&expiry=' . rawurlencode($exp)); $rows = $j['records']['data'] ?? []; $under = $j['records']['underlyingValue'] ?? $under; }
  }
  if (!$rows) return null;
  $ce = $pe = []; $totCE = $totPE = 0.0;
  foreach ($rows as $r) {
    if ($exp && isset($r['expiryDate']) && $r['expiryDate'] !== $exp) continue;
    $k = (float) ($r['strikePrice'] ?? 0); if (!$k) continue;
    $c = (float) ($r['CE']['openInterest'] ?? 0); $p = (float) ($r['PE']['openInterest'] ?? 0);
    $ce[(string) $k] = ($ce[(string) $k] ?? 0) + $c; $pe[(string) $k] = ($pe[(string) $k] ?? 0) + $p; $totCE += $c; $totPE += $p;
  }
  if ($totCE <= 0) return null;
  /* max pain: the expiry price at which option writers pay out the least */
  $strikes = array_map('floatval', array_keys($ce)); sort($strikes); $best = null; $bestK = null;
  foreach ($strikes as $s) { $pay = 0.0; foreach ($strikes as $k) { $pay += max(0, $s - $k) * $ce[(string) $k] + max(0, $k - $s) * $pe[(string) $k]; } if ($best === null || $pay < $best) { $best = $pay; $bestK = $s; } }
  arsort($ce); arsort($pe); $pcr = $totPE / $totCE;
  return ['symbol' => $symbol, 'expiry' => $exp, 'underlying' => $under, 'pcr' => round($pcr, 2), 'max_pain' => $bestK,
          'call_wall' => (float) array_key_first($ce), 'put_wall' => (float) array_key_first($pe),
          'read' => $pcr > 1.5 ? 'PCR very high — heavy put writing, market may be overbought short-term.' : ($pcr >= 1.0 ? 'PCR above 1 — put writers confident: supportive/bullish.' : ($pcr >= 0.7 ? 'PCR 0.7–1 — neutral.' : 'PCR below 0.7 — call writers dominate: bearish, though very low PCR can mean oversold.')),
          'score' => round($pcr > 1.6 ? -0.2 : tanh(($pcr - 0.95) / 0.3), 3)];
}

/* ---------- user-entered India macro inputs ---------- */
function mkt_inputs_fields() {
  return [
    'repo' => 'RBI repo rate (%)', 'rbi_stance' => 'RBI stance (Easing / Neutral / Tightening)', 'rbi_next' => 'Next RBI policy date (YYYY-MM-DD)',
    'cpi' => 'CPI inflation YoY (%)', 'gdp' => 'Real GDP growth YoY (%)', 'pmi_mfg' => 'Manufacturing PMI', 'pmi_svc' => 'Services PMI', 'iip' => 'IIP growth YoY (%)',
    'gst' => 'GST collections growth YoY (%)', 'fiscal_deficit' => 'Fiscal deficit (% of GDP)', 'cad' => 'Current account balance (% of GDP, deficit negative)',
    'monsoon' => 'Monsoon rainfall (% of LPA)', 'fii_month' => 'FII net this month (₹ crore)', 'dii_month' => 'DII net this month (₹ crore)',
    'fii_today' => 'FII net, latest day (₹ crore) — used when NSE is unreachable', 'dii_today' => 'DII net, latest day (₹ crore)', 'events' => 'Upcoming events you are watching (one per line: YYYY-MM-DD text)',
  ];
}
function mkt_inputs() { $j = json_decode((string) md_store_get('inputs'), true); return is_array($j) ? $j : []; }

/* ---------- event calendar ---------- */
function mkt_events(array $inputs) {
  $today = mk_ist_date(time()); $ev = [];
  /* US Fed FOMC decision days (second day of each meeting) */
  foreach (['2026-01-28', '2026-03-18', '2026-04-29', '2026-06-17', '2026-07-29', '2026-09-16', '2026-10-28', '2026-12-09'] as $d) $ev[] = ['date' => $d, 'what' => 'US Fed (FOMC) rate decision — result lands after Indian close; hits the next morning', 'kind' => 'global'];
  /* NSE monthly F&O expiry: last Tuesday of the month (since Sep 2025) */
  $t = strtotime(substr($today, 0, 7) . '-01');
  for ($k = 0; $k < 3; $k++) {
    $m = strtotime("+$k month", $t); $last = strtotime('last tuesday of ' . date('F Y', $m));
    $ev[] = ['date' => date('Y-m-d', $last), 'what' => 'NSE monthly F&O expiry (last Tuesday) — expect volatility & rollover moves', 'kind' => 'india'];
  }
  $y = (int) substr($today, 0, 4);
  foreach ([$y, $y + 1] as $yy) {
    $ev[] = ['date' => "$yy-02-01", 'what' => 'Union Budget', 'kind' => 'india'];
    foreach (['01-10' => 'Q3', '04-10' => 'Q4', '07-10' => 'Q1', '10-10' => 'Q2'] as $md => $q) $ev[] = ['date' => "$yy-$md", 'what' => "$q results season begins (IT majors report first)", 'kind' => 'india'];
  }
  if (!empty($inputs['rbi_next'])) $ev[] = ['date' => $inputs['rbi_next'], 'what' => 'RBI monetary policy decision', 'kind' => 'india'];
  foreach (preg_split('/\r?\n/', (string) ($inputs['events'] ?? '')) as $line) if (preg_match('/^\s*(\d{4}-\d{2}-\d{2})\s+(.+)$/', $line, $m)) $ev[] = ['date' => $m[1], 'what' => trim($m[2]), 'kind' => 'yours'];
  $ev = array_values(array_filter($ev, function ($e) use ($today) { return $e['date'] >= $today && $e['date'] <= date('Y-m-d', strtotime($today . ' +60 days')); }));
  usort($ev, function ($a, $b) { return strcmp($a['date'], $b['date']); });
  foreach ($ev as &$e) $e['days'] = (int) round((strtotime($e['date']) - strtotime($today)) / 86400); unset($e);
  return array_slice($ev, 0, 12);
}

/* =====================================================================
   MACRO — the full national + international picture
   ===================================================================== */
function mkt_macro($force = false) {
  if (!$force) { $hit = mkt_cache_get('macro_v1', mkt_ttl('macro')); if ($hit) { $hit['cached'] = true; return $hit; } }
  @set_time_limit(120);
  $cat = mk_macro_catalog(); $specs = [];
  foreach ($cat as $i => $d) $specs['m' . $i] = [$d['sym'], '6mo', '1d'];
  foreach (mk_sector_indices() as $k => $s) $specs['s_' . $k] = [$s[0], '6mo', '1d'];
  $specs['nifty1y'] = ['^NSEI', '2y', '1d'];
  $U = mkt_universes()['nifty50']['symbols'];
  foreach ($U as $s) $specs['b_' . $s] = [mkt_norm_symbol($s), '6mo', '1d'];
  $D = mkt_charts($specs, $force);
  /* instruments */
  $ins = []; $missing = [];
  foreach ($cat as $i => $d) { $C = $D['m' . $i] ?? null; if ($C) $ins[] = mk_instrument($C, $d); else $missing[] = $d['label']; }
  $ins = array_values(array_filter($ins));
  /* domestic trend: the Nifty's own technical score */
  $nifty = $D['nifty1y'] ?? null; $niftyTech = null;
  if ($nifty) { $T = mk_tech_report($nifty); unset($T['_A']); $niftyTech = $T; }
  /* breadth across the Nifty 50 */
  $adv = $dec = $a20 = $a50 = $tot = 0; $movers = [];
  foreach ($U as $s) {
    $C = $D['b_' . $s] ?? null; if (!$C || count($C['c']) < 51) continue; $c = $C['c']; $n = count($c); $tot++;
    $chg = ($c[$n - 1] / $c[$n - 2] - 1) * 100; if ($chg > 0) $adv++; elseif ($chg < 0) $dec++;
    if ($c[$n - 1] > array_sum(array_slice($c, -20)) / 20) $a20++;
    if ($c[$n - 1] > array_sum(array_slice($c, -50)) / 50) $a50++;
    $movers[] = ['symbol' => $s, 'chg' => round($chg, 2), 'price' => round($c[$n - 1], 2)];
  }
  usort($movers, function ($a, $b) { return $b['chg'] <=> $a['chg']; });
  $breadth = $tot ? ['advances' => $adv, 'declines' => $dec, 'total' => $tot, 'pct_above_20dma' => round($a20 / $tot * 100), 'pct_above_50dma' => round($a50 / $tot * 100),
                     'ad_ratio' => $dec ? round($adv / $dec, 2) : null, 'top' => array_slice($movers, 0, 5), 'bottom' => array_reverse(array_slice($movers, -5)),
                     'score' => round(mk_clamp((($a50 / $tot) - 0.5) * 1.6 + (($adv - $dec) / max(1, $tot)) * 0.5), 3)] : null;
  /* sectors */
  $srs = []; $sectorsIdx = [];
  foreach (mk_sector_indices() as $k => $s) {
    $C = $D['s_' . $k] ?? null; if (!$C || !$nifty) continue; $rs = mk_sector_rs($C, $nifty); if (!$rs) continue;
    $srs[$k] = $rs; $sectorsIdx[] = ['key' => $k, 'label' => $s[1], 'sym' => $s[0], 'last' => round(end($C['c']), 2)] + $rs;
  }
  usort($sectorsIdx, function ($a, $b) { return $b['score'] <=> $a['score']; });
  /* flows, options, news, inputs */
  $inputs = mkt_inputs();
  $flows = mkt_flows();
  if (!$flows && isset($inputs['fii_today']) && is_numeric($inputs['fii_today'])) $flows = ['source' => 'entered by hand', 'fii_net' => (float) $inputs['fii_today'], 'dii_net' => is_numeric($inputs['dii_today'] ?? null) ? (float) $inputs['dii_today'] : null, 'date' => $inputs['updated_at'] ?? null];
  $oc = mkt_option_chain('NIFTY');
  $news = mkt_news(mkt_market_feeds(), 'news|market');
  $india = mk_india_inputs_score($inputs);
  /* regime */
  $extra = [];
  if ($niftyTech) $extra[] = ['key' => 'nifty_trend', 'label' => 'Nifty trend (technical)', 'weight' => 0.10, 'score' => $niftyTech['score']];
  if ($breadth) $extra[] = ['key' => 'breadth', 'label' => 'Market breadth (Nifty 50)', 'weight' => 0.05, 'score' => $breadth['score']];
  if ($flows && $flows['fii_net'] !== null) $extra[] = ['key' => 'flows', 'label' => 'FII/DII flows (latest day)', 'weight' => 0.06, 'score' => round(tanh(($flows['fii_net'] + 0.5 * ($flows['dii_net'] ?? 0)) / 4000), 3)];
  if ($oc) $extra[] = ['key' => 'pcr', 'label' => 'Nifty options (PCR)', 'weight' => 0.03, 'score' => $oc['score']];
  if ($news['scored'] ?? 0) $extra[] = ['key' => 'news', 'label' => 'News sentiment', 'weight' => 0.05, 'score' => $news['score']];
  if ($india['score'] !== null) $extra[] = ['key' => 'india_macro', 'label' => 'India macro (RBI, CPI, GDP, PMI…)', 'weight' => 0.08, 'score' => $india['score']];
  $nseIdx = mkt_nse_indices(); $nv = $nseIdx['NIFTY 50'] ?? null;
  if ($nv && $nv['pe']) $extra[] = ['key' => 'valuation', 'label' => 'Nifty valuation (P/E ' . round($nv['pe'], 1) . ')', 'weight' => 0.05, 'score' => round(tanh((21.5 - $nv['pe']) / 3), 3)];
  $R = mk_regime($ins, $extra);
  if (count($ins) < 6) { // flows, options and news alone are not a market read
    $R['label'] = 'Not enough price data'; $R['insufficient'] = true;
    $R['advice'] = 'Live prices could not be fetched from any source just now, so no regime call is made. The flows, options and news below are still current.';
  }
  $sectors = mk_sector_view($R['drivers'], $srs);
  $vix = null; foreach ($ins as $x) if ($x['driver'] === 'india_vix') $vix = $x['last'];
  $out = ['as_of' => time(), 'market' => mk_market_status(), 'regime' => $R, 'instruments' => $ins, 'missing' => $missing,
          'nifty' => $niftyTech ? ['price' => $niftyTech['price'], 'change_pct' => $niftyTech['change_pct'], 'score' => $niftyTech['score'], 'factors' => $niftyTech['factors'],
                                   'levels' => $niftyTech['levels'], 'pivots' => $niftyTech['pivots_daily'], 'structure' => $niftyTech['structure'], 'returns' => $niftyTech['returns'], 'indicators' => $niftyTech['indicators']] : null,
          'india_vix' => $vix, 'breadth' => $breadth, 'nifty_valuation' => $nv,
          'sources' => array_count_values(array_filter(array_map(function ($C) { return $C['meta']['source'] ?? null; }, array_filter($D)))), 'sector_indices' => $sectorsIdx, 'sectors' => array_values($sectors), 'flows' => $flows, 'options' => $oc,
          'news' => $news, 'india_inputs' => ['values' => $inputs, 'scored' => $india], 'events' => mkt_events($inputs)];
  if ($ins) mkt_cache_set('macro_v1', $out); // an all-failed fetch is shown, never cached
  return $out;
}

/* =====================================================================
   ANALYZE — one stock, every angle
   ===================================================================== */
function mkt_analyze($symIn, $capital = 100000, $riskPct = 1.0, $force = false) {
  $sym = mkt_norm_symbol($symIn); if (!$sym) throw new Exception('Enter an NSE symbol like RELIANCE, TCS or HDFCBANK.');
  @set_time_limit(120);
  $D = mkt_charts(['d' => [$sym, '2y', '1d'], 'n' => ['^NSEI', '2y', '1d'], 'i5' => [$sym, '5d', '5m'], 'n5' => ['^NSEI', '5d', '5m'], 'i15' => [$sym, '60d', '15m']], $force);
  if (empty($D['d']) || count($D['d']['c']) < 60) {
    if (substr($sym, -3) === '.NS') { $alt = substr($sym, 0, -3) . '.BO'; $D2 = mkt_charts(['d' => [$alt, '2y', '1d'], 'i5' => [$alt, '5d', '5m'], 'i15' => [$alt, '60d', '15m']], $force); if (!empty($D2['d'])) { $sym = $alt; $D = array_merge($D, $D2); } }
    if (empty($D['d']) || count($D['d']['c']) < 60) throw new Exception("No price history for $sym. Check the symbol (NSE code, e.g. RELIANCE) — or Yahoo Finance may be unreachable from this server.");
  }
  $daily = $D['d']; $nifty = $D['n'] ?? null; $meta = $daily['meta'] ?? [];
  $isIndex = $sym[0] === '^';
  $Q = $isIndex ? null : mkt_quote_summary($sym);
  $tech = mk_tech_report($daily, $isIndex ? null : $nifty);
  $sectorKey = mk_sector_of($sym, $Q['assetProfile']['sector'] ?? null, $Q['assetProfile']['industry'] ?? null);
  $fund = $Q ? mk_fundamentals($Q, $tech['price'], $sectorKey) : null;
  $macro = mkt_cache_get('macro_v1', 6 * 3600) ?: mkt_macro();
  $regimeScore = $macro['regime']['score'] ?? null; $sector = null;
  foreach (($macro['sectors'] ?? []) as $s) if ($s['key'] === $sectorKey) $sector = $s;
  $name = $fund['values']['name'] ?? ($meta['name'] ?? $sym);
  /* intraday */
  $earnSoon = null;
  if (!empty($fund['values']['next_earnings'])) { $dd = (strtotime($fund['values']['next_earnings']) - time()) / 86400; if ($dd >= -1 && $dd <= 7) $earnSoon = $fund['values']['next_earnings']; }
  $intraday = !empty($D['i5']) ? mk_intraday_signal($D['i5'], $isIndex ? null : ($D['n5'] ?? null), ['capital' => $capital, 'risk_pct' => $riskPct, 'daily_score' => $tech['score'],
               'market_score' => $regimeScore, 'india_vix' => $macro['india_vix'] ?? null, 'earnings_soon' => $earnSoon]) : ['error' => 'No intraday data.'];
  $swing = mk_swing($tech, $capital, $riskPct, $regimeScore);
  $long = mk_longterm($tech, $fund, $sector, $regimeScore, $capital, $riskPct);
  if ($earnSoon) $long['cons'][] = 'Results due ' . $earnSoon . ' — the price can gap either way; consider waiting or sizing down.';
  $bt = ['swing' => mk_backtest_swing($tech['_A'])];
  if (!empty($D['i15']) && count($D['i15']['c']) > 200) $bt['intraday'] = mk_backtest_intraday(mk_intraday_arrays($D['i15']));
  $chart = mk_chart_series($tech['_A'], 180); unset($tech['_A']);
  $news = $isIndex ? null : mkt_stock_news($name, $sym);
  /* how the big macro drivers hit THIS stock's sector */
  $exposure = [];
  $sens = mk_sector_sensitivity()[$sectorKey] ?? null;
  if ($sens) foreach ($sens['drivers'] as $d => $beta) {
    $m = $macro['regime']['drivers'][$d]['move'] ?? null;
    $exposure[] = ['driver' => mk_driver_name($d), 'sensitivity' => $beta, 'driver_move' => $m === null ? null : round($m, 2), 'effect' => $m === null ? null : round($beta * $m, 3),
                   'note' => ($beta > 0 ? 'Benefits when ' : 'Hurt when ') . mk_driver_name($d) . ' rises' . ($m === null ? '.' : '; it is currently ' . ($m > 0.1 ? 'rising' : ($m < -0.1 ? 'falling' : 'flat')) . '.')];
  }
  $out = ['data_source' => ['prices' => $meta['source'] ?? null, 'fundamentals' => $Q ? ($Q['_source'] ?? 'Yahoo') : null], 'symbol' => $sym, 'name' => $name, 'exchange' => $meta['exchange'] ?? null, 'currency' => $meta['currency'] ?? 'INR', 'as_of' => time(), 'market' => mk_market_status(),
          'stale' => !empty($meta['stale']), 'sector' => ['key' => $sectorKey, 'label' => $sens['label'] ?? $sectorKey, 'story' => $sens['story'] ?? null, 'view' => $sector, 'exposure' => $exposure,
                                                         'yahoo_sector' => $fund['values']['sector'] ?? null, 'industry' => $fund['values']['industry'] ?? null],
          'verdicts' => ['intraday' => $intraday, 'swing' => $swing, 'long' => $long],
          'technical' => $tech, 'fundamental' => $fund, 'backtest' => $bt, 'chart' => $chart, 'news' => $news,
          'macro' => ['score' => $regimeScore, 'label' => $macro['regime']['label'] ?? null, 'advice' => $macro['regime']['advice'] ?? null, 'india_vix' => $macro['india_vix'] ?? null, 'as_of' => $macro['as_of'] ?? null],
          'capital' => $capital, 'risk_pct' => $riskPct];
  if ($intraday && isset($intraday['chart'])) { $out['intraday_chart'] = $intraday['chart']; unset($out['verdicts']['intraday']['chart']); }
  return $out;
}

/* =====================================================================
   SCAN — rank a universe
   ===================================================================== */
function mkt_scan($universe, array $custom, $capital, $riskPct, $force = false) {
  @set_time_limit(150);
  $U = mkt_universes();
  if ($universe === 'custom') { $syms = array_values(array_unique(array_filter(array_map('mkt_norm_symbol', $custom)))); $label = 'Your watchlist'; }
  else { $u = $U[$universe] ?? $U['nifty50']; $syms = array_map('mkt_norm_symbol', $u['symbols']); $label = $u['label']; }
  $syms = array_slice($syms, 0, 60);
  if (!$syms) throw new Exception('Add some symbols to your watchlist first.');
  $specs = ['n' => ['^NSEI', '1y', '1d'], 'n5' => ['^NSEI', '5d', '5m']];
  foreach ($syms as $s) { $specs['d|' . $s] = [$s, '1y', '1d']; $specs['i|' . $s] = [$s, '5d', '5m']; }
  $D = mkt_charts($specs, $force);
  $macro = mkt_cache_get('macro_v1', 6 * 3600); $regime = $macro['regime']['score'] ?? null; $sectors = [];
  foreach (($macro['sectors'] ?? []) as $s) $sectors[$s['key']] = $s;
  $rows = []; $failed = [];
  foreach ($syms as $s) {
    $C = $D['d|' . $s] ?? null; if (!$C || count($C['c']) < 60) { $failed[] = $s; continue; }
    $T = mk_tech_report($C, $D['n'] ?? null); unset($T['_A']);
    $sk = mk_sector_of($s); $sec = $sectors[$sk] ?? null;
    $I = !empty($D['i|' . $s]) ? mk_intraday_signal($D['i|' . $s], $D['n5'] ?? null, ['capital' => $capital, 'risk_pct' => $riskPct, 'daily_score' => $T['score'], 'market_score' => $regime, 'india_vix' => $macro['india_vix'] ?? null]) : null;
    $SW = mk_swing($T, $capital, $riskPct, $regime);
    $LT = mk_longterm($T, null, $sec, $regime, $capital, $riskPct);
    $top = $T['factors']; usort($top, function ($a, $b) { return abs($b['score'] * $b['weight']) <=> abs($a['score'] * $a['weight']); });
    $rows[] = ['symbol' => preg_replace('/\.NS$/', '', $s), 'yahoo' => $s, 'name' => $C['meta']['name'] ?? null, 'price' => $T['price'], 'change_pct' => $T['change_pct'],
      'sector' => mk_sector_sensitivity()[$sk]['label'] ?? $sk, 'sector_score' => $sec['score'] ?? null,
      'tech_score' => $T['score'], 'rsi' => $T['indicators']['rsi'], 'adx' => $T['indicators']['adx'], 'vol_ratio' => $T['indicators']['volume_ratio'],
      'ret_1m' => $T['returns']['1m'], 'ret_3m' => $T['returns']['3m'], 'from_52w_high' => $T['range52']['high'] ? round(($T['price'] / $T['range52']['high'] - 1) * 100, 1) : null,
      'intraday' => $I && !isset($I['error']) ? ['action' => $I['action'], 'score' => $I['score'], 'confidence' => $I['confidence'], 'plan' => $I['plan'], 'rvol' => $I['session']['rvol'], 'vwap' => $I['session']['vwap']] : null,
      'swing' => ['action' => $SW['action'], 'score' => $SW['score'], 'plan' => $SW['plan']],
      'long' => ['rating' => $LT['rating'], 'score' => $LT['score'], 'target' => $LT['target'], 'stop' => $LT['stop']],
      'why' => array_map(function ($f) { return $f['label'] . ': ' . $f['note']; }, array_slice($top, 0, 3)),
      'patterns' => array_column($T['patterns'], 'name')];
  }
  return ['universe' => $universe, 'label' => $label, 'as_of' => time(), 'market' => mk_market_status(), 'rows' => $rows, 'failed' => $failed,
          'macro' => $macro ? ['score' => $regime, 'label' => $macro['regime']['label']] : null,
          'note' => 'Scanner skips fundamentals for speed; open a stock for the full fundamental + backtest view.'];
}

function mkt_search($q) {
  $q = trim((string) $q); if ($q === '') return [];
  $local = []; $uq = strtoupper($q);
  foreach (mkt_universes() as $u) foreach ($u['symbols'] as $s) if (strpos($s, $uq) === 0) $local[$s] = ['symbol' => $s, 'yahoo' => mkt_norm_symbol($s), 'name' => null, 'exchange' => 'NSE'];
  if (mkt_yahoo_blocked()) { foreach (mkt_search_master($q) as $x) $local[$x['symbol']] = $x; return array_slice(array_values($local), 0, 12); }
  $r = mkt_http('https://query2.finance.yahoo.com/v1/finance/search?q=' . rawurlencode($q) . '&quotesCount=12&newsCount=0&listsCount=0');
  if ($r['code'] === 429) { mkt_yahoo_mark_blocked(); foreach (mkt_search_master($q) as $x) $local[$x['symbol']] = $x; }
  $j = json_decode($r['body'], true);
  foreach (($j['quotes'] ?? []) as $x) {
    $s = $x['symbol'] ?? ''; if (!preg_match('/\.(NS|BO)$|^\^/', $s)) continue;
    $k = preg_replace('/\.NS$/', '', $s);
    $local[$k] = ['symbol' => $k, 'yahoo' => $s, 'name' => $x['longname'] ?? ($x['shortname'] ?? null), 'exchange' => $x['exchDisp'] ?? null];
  }
  return array_slice(array_values($local), 0, 12);
}

/* ---------- Claude research note (key in config.php) ---------- */
function mkt_ai_note($sym, $capital, $riskPct) {
  if (md_config()['anthropic_key'] === '' && !isset($GLOBALS['MD_CLAUDE_MOCK'])) throw new Exception('Add your Anthropic API key to config.php ($ANTHROPIC_API_KEY) to get written notes.');
  $macro = mkt_cache_get('macro_v1', 6 * 3600) ?: mkt_macro();
  $slimMacro = ['regime' => array_diff_key($macro['regime'], ['drivers' => 1]), 'instruments' => array_map(function ($i) { unset($i['spark']); return $i; }, $macro['instruments']),
    'breadth' => $macro['breadth'], 'flows' => $macro['flows'], 'options' => $macro['options'], 'sectors' => array_slice($macro['sectors'], 0, 30), 'india_inputs' => $macro['india_inputs'],
    'events' => $macro['events'], 'headlines' => array_map(function ($n) { return $n['title'] . ' [' . $n['sentiment'] . ']'; }, array_slice($macro['news']['items'] ?? [], 0, 30))];
  if ($sym) {
    $A = mkt_analyze($sym, $capital, $riskPct);
    unset($A['chart'], $A['intraday_chart']);
    if (isset($A['news']['items'])) $A['news'] = array_map(function ($n) { return $n['title'] . ' [' . $n['sentiment'] . ']'; }, array_slice($A['news']['items'], 0, 15));
    $data = ['stock' => $A, 'market' => $slimMacro];
    $ask = 'Write a research note on ' . $A['name'] . ' (' . $A['symbol'] . ') with these sections: 1) Bottom line (intraday, swing and long-term calls in one line each, with levels); '
      . '2) Intraday game plan (entry trigger, stop, targets, what would invalidate it); 3) Swing view; 4) Long-term thesis — business quality, valuation, growth, what the price already assumes; '
      . '5) How today\'s national and international factors (global cues, crude, rupee, yields, FII flows, RBI/inflation, sector rotation, news) affect this stock specifically; '
      . '6) Key risks and upcoming events; 7) Levels to watch.';
  } else {
    $data = ['market' => $slimMacro];
    $ask = 'Write today\'s Indian market outlook with sections: 1) Bottom line for Nifty/Bank Nifty today and for the next few weeks; 2) Global cues and what they mean at the open; '
      . '3) Rupee, crude, bond yields and flows; 4) Domestic macro and policy (RBI, inflation, growth); 5) Sectors to favour and to avoid, and why; 6) Key levels, events and risks; 7) A simple playbook for intraday and positional traders.';
  }
  $system = 'You are a senior sell-side equity strategist covering Indian markets (NSE/BSE). You write clear, specific, numbers-first notes for active traders and long-term investors. '
    . 'Use ONLY the JSON data provided — it was computed minutes ago from live market data, scored by a rule-based engine (scores run -1 bearish to +1 bullish). '
    . 'Do not invent prices, news or numbers not in the data; if something important is missing say so. Explain the reasoning, give levels in ₹, and be honest about uncertainty and conflicting signals. '
    . 'Format in Markdown with ## headings and short bullet points. End with one line: "Not investment advice — rule-based analysis; verify before trading." Keep it under 900 words.';
  return md_claude($system, $ask . "\n\nDATA:\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/* ---------- diagnostics: what each data source returns to THIS server ---------- */
function mkt_diag() {
  $probe = function ($url, $opts = []) {
    $r = mkt_http($url, $opts + ['want_headers' => true]);
    $h = []; foreach (['retry-after', 'content-type', 'set-cookie', 'location'] as $k) if (preg_match('/^' . $k . ':\s*([^\r\n]{0,80})/im', (string) ($r['headers'] ?? ''), $m)) $h[$k] = $m[1];
    return ['url' => preg_replace('/crumb=[^&]+/', 'crumb=…', $url), 'code' => $r['code'], 'bytes' => strlen($r['body']), 'error' => $r['error'],
            'headers' => $h, 'body' => mb_substr(preg_replace('/\s+/', ' ', strip_tags($r['body'])), 0, 160)];
  };
  $out = ['php' => PHP_VERSION, 'curl' => curl_version()['version'] ?? null, 'ssl' => curl_version()['ssl_version'] ?? null];
  $out['yahoo_q1_chart'] = $probe(mkt_chart_url('RELIANCE.NS', '5d', '1d'));
  $out['yahoo_q2_chart'] = $probe(str_replace('query1.', 'query2.', mkt_chart_url('RELIANCE.NS', '5d', '1d')));
  $out['yahoo_cookie'] = $probe('https://fc.yahoo.com/', ['headers' => ['Accept: text/html']]);
  $a = mkt_yahoo_auth(true);
  $out['yahoo_crumb'] = $a ? 'ok' : 'failed';
  if ($a) $out['yahoo_q2_chart_with_cookie'] = $probe(str_replace('query1.', 'query2.', mkt_chart_url('RELIANCE.NS', '5d', '1d')) . '&crumb=' . rawurlencode($a['crumb']), ['cookie' => $a['cookie']]);
  $out['stooq_csv'] = $probe('https://stooq.com/q/d/l/?s=reliance.in&i=d');
  $out['stooq_spx'] = $probe('https://stooq.com/q/d/l/?s=%5Espx&i=d');
  /* NSE endpoints, with the same cookie the flows call uses */
  mkt_flows(); $ck = mkt_cache_get('nse_cookie', null);
  $to = date('d-m-Y'); $from = date('d-m-Y', strtotime('-60 days'));
  $nse = ['/api/quote-equity?symbol=RELIANCE', '/api/quote-equity?symbol=RELIANCE&section=trade_info', '/api/allIndices',
          '/api/historical/cm/equity?symbol=RELIANCE&series=%5B%22EQ%22%5D&from=' . $from . '&to=' . $to,
          '/api/historical/securityArchives?from=' . $from . '&to=' . $to . '&symbol=RELIANCE&dataType=priceVolumeDeliverable&series=ALL',
          '/api/chart-databyindex?index=RELIANCEEQN', '/api/chart-databyindex?index=NIFTY%2050&indices=true',
          '/api/historical/indicesHistory?indexType=NIFTY%2050&from=' . $from . '&to=' . $to, '/api/equity-stockIndices?index=NIFTY%2050', '/api/search/autocomplete?q=infos'];
  foreach ($nse as $pth) $out['nse ' . $pth] = $probe('https://www.nseindia.com' . $pth, ['cookie' => $ck, 'headers' => ['Referer: https://www.nseindia.com/get-quotes/equity?symbol=RELIANCE', 'X-Requested-With: XMLHttpRequest']]);
  /* FRED (US Federal Reserve) daily series: no key needed */
  foreach (['DGS10', 'DEXINUS', 'DCOILBRENTEU', 'SP500', 'VIXCLS', 'NIKKEI225'] as $id) $out['fred ' . $id] = $probe('https://fred.stlouisfed.org/graph/fredgraph.csv?id=' . $id);
  /* Upstox public candle API (Indian stocks & indices) */
  $to = date('Y-m-d'); $fromD = date('Y-m-d', strtotime('-400 days')); $fromI = date('Y-m-d', strtotime('-20 days'));
  $out['upstox v2 day'] = $probe('https://api.upstox.com/v2/historical-candle/' . rawurlencode('NSE_EQ|INE002A01018') . '/day/' . $to . '/' . $fromD, ['headers' => ['Accept: application/json']]);
  $out['upstox v3 15min'] = $probe('https://api.upstox.com/v3/historical-candle/' . rawurlencode('NSE_EQ|INE002A01018') . '/minutes/15/' . $to . '/' . $fromI, ['headers' => ['Accept: application/json']]);
  $out['upstox v3 5min'] = $probe('https://api.upstox.com/v3/historical-candle/' . rawurlencode('NSE_EQ|INE002A01018') . '/minutes/5/' . $to . '/' . date('Y-m-d', strtotime('-7 days')), ['headers' => ['Accept: application/json']]);
  $out['upstox nifty day'] = $probe('https://api.upstox.com/v2/historical-candle/' . rawurlencode('NSE_INDEX|Nifty 50') . '/day/' . $to . '/' . $fromD, ['headers' => ['Accept: application/json']]);
  $out['upstox instruments'] = $probe('https://assets.upstox.com/market-quote/instruments/exchange/NSE.json.gz');
  /* CNBC quotes + chart bars (global cues) */
  $out['cnbc quote'] = $probe('https://quote.cnbc.com/quote-html-webservice/restQuote/symbolType/symbol?symbols=' . rawurlencode('.SPX|.VIX|@LCO.1|US10Y|.N225|INR=') . '&requestMethod=itv&noform=1&partnerId=2&fund=1&exthrs=1&output=json&events=1');
  $out['cnbc chart'] = $probe('https://ts-api.cnbc.com/harmony/app/charts/1Y.json?symbol=.SPX');
  foreach (['RELIANCE-IN', 'TCS-IN', 'HDFCBANK-IN', '.NSEI', '.BSESN', 'BTC.CB=', '.SSEC', '@HG.1', 'US3M', '.DXY'] as $cs) {
    $r = mkt_http('https://quote.cnbc.com/quote-html-webservice/restQuote/symbolType/symbol?symbols=' . rawurlencode($cs) . '&requestMethod=itv&noform=1&partnerId=2&fund=1&exthrs=1&output=json&events=1');
    $out['cnbc q ' . $cs] = ['code' => $r['code'], 'body' => mb_substr($r['body'], 0, $cs === 'RELIANCE-IN' ? 3000 : 400)];
  }
  $r = mkt_http('https://ts-api.cnbc.com/harmony/app/charts/1Y.json?symbol=US10Y'); $out['cnbc chart US10Y'] = ['code' => $r['code'], 'body' => mb_substr($r['body'], 0, 300)];
  $r = mkt_http('https://ts-api.cnbc.com/harmony/app/charts/1Y.json?symbol=RELIANCE-IN'); $out['cnbc chart RELIANCE-IN'] = ['code' => $r['code'], 'body' => mb_substr($r['body'], 0, 300)];
  $r = mkt_http('https://api.upstox.com/v3/historical-candle/intraday/' . rawurlencode('NSE_EQ|INE002A01018') . '/minutes/5'); $out['upstox intraday today'] = ['code' => $r['code'], 'body' => mb_substr($r['body'], 0, 300)];
  $r = mkt_http('https://api.upstox.com/v2/historical-candle/' . rawurlencode('BSE_INDEX|SENSEX') . '/day/' . date('Y-m-d') . '/' . date('Y-m-d', strtotime('-10 days'))); $out['upstox sensex'] = ['code' => $r['code'], 'body' => mb_substr($r['body'], 0, 200)];
  $r = mkt_http('https://api.upstox.com/v2/historical-candle/' . rawurlencode('NSE_EQ|INE002A01018') . '/day/' . date('Y-m-d') . '/' . date('Y-m-d', strtotime('-740 days'))); $out['upstox 2y'] = ['code' => $r['code'], 'bytes' => strlen($r['body']), 'body' => mb_substr($r['body'], -200)];
  $out['google_finance'] = $probe('https://www.google.com/finance/quote/RELIANCE:NSE');
  return $out;
}

/* ---------- router ---------- */
function mkt_dispatch($action) {
  $b = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
  $g = function ($k, $d = null) use ($b) { return isset($b[$k]) ? $b[$k] : (isset($_GET[$k]) ? $_GET[$k] : $d); };
  $force = (string) $g('refresh', '') === '1';
  $DS = id_settings(); $capital = max(0, (float) $g('capital', $DS['capital'])); $risk = min(5, max(0.1, (float) $g('risk', $DS['risk_pct'])));
  try {
    switch ($action) {
      case 'mkt_status': $out = ['market' => mk_market_status(), 'universes' => array_map(function ($u) { return ['label' => $u['label'], 'count' => count($u['symbols'])]; }, mkt_universes()), 'fields' => mkt_inputs_fields()]; break;
      case 'mkt_macro': $out = mkt_macro($force); break;
      case 'mkt_analyze': $out = mkt_analyze((string) $g('symbol', ''), $capital, $risk, $force); break;
      case 'mkt_scan': $c = $g('symbols', ''); $out = mkt_scan((string) $g('universe', 'nifty50'), is_array($c) ? $c : preg_split('/[\s,]+/', (string) $c), $capital, $risk, $force); break;
      case 'mkt_search': $out = ['results' => mkt_search((string) $g('q', ''))]; break;
      case 'mkt_inputs': $out = ['values' => mkt_inputs(), 'fields' => mkt_inputs_fields()]; break;
      case 'mkt_inputs_set':
        $in = []; foreach (mkt_inputs_fields() as $k => $label) { $v = trim((string) ($b[$k] ?? '')); if ($v !== '') $in[$k] = mb_substr($v, 0, $k === 'events' ? 2000 : 60); }
        $in['updated_at'] = gmdate('Y-m-d H:i', time() + MK_IST) . ' IST';
        md_store_set('inputs', json_encode($in, JSON_UNESCAPED_UNICODE));
        md_store_set('macro_v1', ''); // next Market Pulse load re-scores with the new numbers
        $out = ['values' => $in]; break;
      case 'mkt_diag': $out = mkt_diag(); break;
      case 'mkt_top10': $out = id_top10($force, $capital, $risk); break;
      case 'mkt_momentum': $out = mom_view(); break;
      /* read-only paper check: replay today's stored list over today's bars, without rebuilding or locking it */
      case 'mkt_paper':
        $d = id_day_get((string) $g('date', id_today()));
        if (!$d || empty($d['picks'])) throw new Exception('No stored list for that date.');
        $lv = id_live($d, $capital, $risk);
        $out = ['date' => $d['date'], 'built_at_ist' => gmdate('Y-m-d H:i', ($d['repicked_at'] ?? $d['built_at']) + MK_IST), 'locked' => !empty($d['locked']), 'entries_from' => $lv['entries_from'], 'book' => $lv['book'],
                'picks' => array_map(function ($p) { return ['symbol' => $p['symbol'], 'dir' => $p['dir'], 'status' => $p['state']['status'], 'day_r' => $p['state']['day_r'],
                  'price' => $p['state']['live']['price'] ?? null, 'chg_pct' => $p['state']['live']['chg_pct'] ?? null,
                  'trades' => array_map(function ($t) { return array_intersect_key($t, array_flip(['side', 'entry', 'entry_time', 'exit', 'exit_time', 'reason', 'r', 'pnl', 'qty', 'skipped'])); }, $p['state']['trades'] ?? []),
                  'position' => $p['state']['position'] ? array_intersect_key($p['state']['position'], array_flip(['side', 'entry', 'time', 'stop', 't1', 't2', 'qty', 'open_pnl', 't1_hit'])) : null,
                  'events' => array_map(function ($e) { return $e['time'] . ' ' . $e['type'] . ($e['price'] ? ' @' . $e['price'] : ''); }, $p['state']['events'] ?? [])]; }, $lv['picks'])];
        break;
      case 'mkt_tick': $out = id_tick(); break;
      case 'mkt_tg_status': $out = id_tg_status(); break;
      case 'mkt_tg_test':
        $st = id_tg_status();
        if (!$st['configured']) throw new Exception('No Telegram bot token yet — add the TELEGRAM_BOT_TOKEN secret in GitHub and run the deploy.');
        id_tg_chat(true);
        $r = id_tg_send(id_tg_compose(function ($l) { return $l === 'hi'
          ? "✅ <b>Market Desk अलर्ट जुड़ गए हैं।</b>\nबाज़ार के समय यहाँ खरीदें / बेचें / स्टॉप-लॉस / टारगेट के मैसेज आएँगे, और हर महीने के पहले ट्रेडिंग दिन मासिक चयन।"
          : "✅ <b>Market Desk alerts are connected.</b>\nYou will get BUY / SELL / stop-loss / target messages here during market hours, and the monthly picks on the first trading day of each month."; }));
        if (empty($r['ok'])) throw new Exception('Telegram: ' . ($r['description'] ?? 'send failed'));
        $out = ['sent' => true]; break;
      case 'mkt_settings': $out = ['settings' => id_settings(), 'pos_budget' => round(id_pos_budget(id_settings()))]; break;
      case 'mkt_settings_set':
        $old = id_settings(); $new = id_settings_set($b);
        /* a new budget or trade type changes which stocks qualify: rebuild a list that is not locked yet (not for a language change) */
        $moved = false; foreach (['capital', 'risk_pct', 'leverage', 'long_only'] as $k) if ($old[$k] != $new[$k]) $moved = true;
        $ph = id_phase(); $dd = id_day_get($ph['date']); if ($moved && $dd && empty($dd['locked'])) md_store_set('id_day_' . str_replace('-', '', $ph['date']), '');
        $out = ['settings' => $new, 'pos_budget' => round(id_pos_budget($new))]; break;
      case 'mkt_ai': $out = mkt_ai_note((string) $g('symbol', ''), $capital, $risk); break;
      default: fail(400, 'Unknown market action.');
    }
  } catch (Exception $e) { fail(400, $e->getMessage()); }
  echo json_encode(['ok' => true] + $out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
}
