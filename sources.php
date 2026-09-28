<?php
/* =====================================================================
   Market Desk — fallback data sources (sources.php)
   ---------------------------------------------------------------------
   Yahoo Finance is tried first. Many shared-hosting IPs get a permanent
   HTTP 429 from Yahoo, so when that happens the app remembers it for 30
   minutes and fetches from sources that answer servers:

     Upstox public candle API   NSE/BSE stocks and indices: daily bars,
                                5- and 15-minute bars, and today's live bars.
                                Symbols are mapped to Upstox instrument keys
                                with Upstox's public NSE instrument list.
     CNBC quote + chart feeds   world indices, VIX, US yields, dollar, rupee,
                                crude, gold, copper, bitcoin — and basic
                                fundamentals (P/E, ROE, margins, D/E…) for
                                Indian stocks.
     NSE allIndices             Nifty P/E, P/B, dividend yield, advances /
                                declines for every NSE index.

   Every function returns data in the same shape as the Yahoo parser, so
   nothing downstream knows which source answered.
   ===================================================================== */

/* ---------- Yahoo blocked? ---------- */
function mkt_yahoo_blocked() { return (bool) mkt_cache_get('yh_blocked', 1800); }
function mkt_yahoo_mark_blocked() { mkt_cache_set('yh_blocked', 1); }

/* ---------- Upstox: instrument keys ---------- */
function mkt_upstox_master() {
  $m = mkt_cache_get('upstox_master', 86400);
  if ($m && !empty($m['eq'])) return $m;
  $r = mkt_http('https://assets.upstox.com/market-quote/instruments/exchange/NSE.json.gz', ['headers' => ['Accept: */*']]);
  $raw = $r['code'] === 200 ? @gzdecode($r['body']) : false;
  if ($raw === false && $r['code'] === 200 && strpos(ltrim($r['body']), '[') === 0) $raw = $r['body']; // already decompressed
  if (!$raw) return mkt_cache_get('upstox_master', null) ?: ['eq' => [], 'idx' => []];
  $eq = []; $idx = [];
  /* the list is huge (futures & options too); pull out only cash equities and indices, one flat object at a time */
  if (preg_match_all('/\{[^{}]*"segment"\s*:\s*"NSE_(?:EQ|INDEX)"[^{}]*\}/', $raw, $mm)) {
    foreach ($mm[0] as $obj) {
      $o = json_decode($obj, true); if (!$o || empty($o['instrument_key'])) continue;
      if ($o['segment'] === 'NSE_EQ') {
        $t = strtoupper((string) ($o['trading_symbol'] ?? '')); $type = (string) ($o['instrument_type'] ?? 'EQ');
        if ($t === '' || !in_array($type, ['EQ', 'BE', 'BZ', 'SM', 'ST'], true)) continue;
        if (!isset($eq[$t]) || $type === 'EQ') $eq[$t] = [$o['instrument_key'], (string) ($o['name'] ?? $t)];
      } else {
        $n = strtolower(trim((string) ($o['name'] ?? $o['trading_symbol'] ?? ''))); if ($n !== '') $idx[$n] = $o['instrument_key'];
        $t = strtolower(trim((string) ($o['trading_symbol'] ?? ''))); if ($t !== '') $idx[$t] = $o['instrument_key'];
      }
    }
  }
  unset($raw, $mm);
  $m = ['eq' => $eq, 'idx' => $idx];
  if ($eq) mkt_cache_set('upstox_master', $m);
  return $m;
}
/* Yahoo-style symbol -> [Upstox key, display name] */
function mkt_upstox_key($sym) {
  $I = ['^NSEI' => ['Nifty 50'], '^NSEBANK' => ['Nifty Bank'], '^INDIAVIX' => ['India VIX'], '^CNXIT' => ['Nifty IT'], '^CNXAUTO' => ['Nifty Auto'],
        '^CNXPHARMA' => ['Nifty Pharma'], '^CNXFMCG' => ['Nifty FMCG'], '^CNXMETAL' => ['Nifty Metal'], '^CNXREALTY' => ['Nifty Realty'], '^CNXENERGY' => ['Nifty Energy'],
        '^CNXPSUBANK' => ['Nifty PSU Bank'], 'NIFTY_FIN_SERVICE.NS' => ['Nifty Fin Service', 'Nifty Financial Services'], '^CNXINFRA' => ['Nifty Infra', 'Nifty Infrastructure'],
        '^CNXMEDIA' => ['Nifty Media'], '^CNXPSE' => ['Nifty PSE'], '^CNXCONSUM' => ['Nifty Consumption', 'Nifty India Consumption'], '^NSEMDCP50' => ['Nifty Midcap 50'],
        '^CNXSC' => ['Nifty Smallcap 100', 'NIFTY SMLCAP 100'], '^CNX100' => ['Nifty 100'], '^CRSLDX' => ['Nifty 500']];
  if ($sym === '^BSESN') return ['BSE_INDEX|SENSEX', 'Sensex'];
  if (isset($I[$sym])) {
    $M = mkt_upstox_master();
    foreach ($I[$sym] as $name) { $k = $M['idx'][strtolower($name)] ?? null; if ($k) return [$k, $name]; }
    return ['NSE_INDEX|' . $I[$sym][0], $I[$sym][0]]; // Upstox keys indices by name; try it as-is
  }
  if (preg_match('/^([A-Z0-9&\-]+)\.(NS|BO)$/', $sym, $m)) {
    $M = mkt_upstox_master(); $e = $M['eq'][$m[1]] ?? null;
    return $e ? [$e[0], $e[1]] : null;
  }
  return null;
}
function mkt_upstox_parse($body) {
  $j = json_decode((string) $body, true); $rows = $j['data']['candles'] ?? null;
  if (!is_array($rows)) return null;
  $C = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
  foreach (array_reverse($rows) as $r) {
    if (!is_array($r) || count($r) < 5) continue; $t = strtotime((string) $r[0]); if (!$t) continue;
    $C['t'][] = $t; $C['o'][] = (float) $r[1]; $C['h'][] = (float) $r[2]; $C['l'][] = (float) $r[3]; $C['c'][] = (float) $r[4]; $C['v'][] = (float) ($r[5] ?? 0);
  }
  return $C;
}
function mkt_candles_merge(array $a, array $b) { // b wins on equal timestamps; result sorted
  $by = [];
  foreach ([$a, $b] as $C) for ($i = 0; $i < count($C['t']); $i++) $by[$C['t'][$i]] = [$C['o'][$i], $C['h'][$i], $C['l'][$i], $C['c'][$i], $C['v'][$i]];
  ksort($by); $o = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
  foreach ($by as $t => $r) { $o['t'][] = $t; $o['o'][] = $r[0]; $o['h'][] = $r[1]; $o['l'][] = $r[2]; $o['c'][] = $r[3]; $o['v'][] = $r[4]; }
  return $o;
}
/* one daily bar out of today's intraday bars */
function mkt_day_bar(array $I) {
  if (!$I || !count($I['c'])) return null;
  $d = mk_ist_date(end($I['t'])); $o = $h = $l = $c = null; $v = 0.0;
  for ($i = 0; $i < count($I['c']); $i++) {
    if (mk_ist_date($I['t'][$i]) !== $d) continue;
    if ($o === null) $o = $I['o'][$i]; $h = $h === null ? $I['h'][$i] : max($h, $I['h'][$i]); $l = $l === null ? $I['l'][$i] : min($l, $I['l'][$i]); $c = $I['c'][$i]; $v += $I['v'][$i];
  }
  if ($o === null) return null;
  return ['t' => [strtotime($d . ' 00:00:00 +05:30')], 'o' => [$o], 'h' => [$h], 'l' => [$l], 'c' => [$c], 'v' => [$v]];
}
function mkt_keep_sessions(array $C, $n) {
  $days = array_values(array_unique(array_map('mk_ist_date', $C['t']))); if (count($days) <= $n) return $C;
  $keep = array_flip(array_slice($days, -$n)); $o = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
  for ($i = 0; $i < count($C['t']); $i++) if (isset($keep[mk_ist_date($C['t'][$i])])) foreach ($o as $f => $_) $o[$f][] = $C[$f][$i];
  return $o;
}

/* ---------- CNBC ---------- */
function mkt_cnbc_symbol($sym) {
  $M = ['^GSPC' => '.SPX', '^IXIC' => '.IXIC', '^DJI' => '.DJI', '^FTSE' => '.FTSE', '^GDAXI' => '.GDAXI', '^N225' => '.N225', '^HSI' => '.HSI', '000001.SS' => '.SSEC',
        '^KS11' => '.KS11', '^VIX' => '.VIX', '^TNX' => 'US10Y', '^IRX' => 'US3M', 'DX-Y.NYB' => '.DXY', 'INR=X' => 'INR=', 'EURINR=X' => 'EURINR=', 'BZ=F' => '@LCO.1',
        'CL=F' => '@CL.1', 'NG=F' => '@NG.1', 'GC=F' => '@GC.1', 'SI=F' => '@SI.1', 'HG=F' => '@HG.1', 'BTC-USD' => 'BTC.CB=', '^NSEI' => '.NSEI', '^NSEBANK' => '.NSEBANK'];
  if (isset($M[$sym])) return $M[$sym];
  if (preg_match('/^([A-Z0-9&\-]+)\.(NS|BO)$/', $sym, $m)) return $m[1] . '-IN';
  return null;
}
function mkt_num($s) { if ($s === null) return null; $s = trim(str_replace([',', '%'], '', (string) $s)); if ($s === '' || !is_numeric($s)) return null; return (float) $s; }
function mkt_num_suffix($s) { // "16.207T" -> 1.6207e13
  if ($s === null) return null; $s = trim(str_replace(',', '', (string) $s));
  if (!preg_match('/^(-?[\d.]+)\s*([KMBT]?)$/i', $s, $m)) return null;
  $mult = ['' => 1, 'K' => 1e3, 'M' => 1e6, 'B' => 1e9, 'T' => 1e12][strtoupper($m[2])];
  return (float) $m[1] * $mult;
}
function mkt_cnbc_parse_chart($body) {
  $j = json_decode((string) $body, true); $bars = $j['barData']['priceBars'] ?? null;
  if (!is_array($bars) || !$bars) return null;
  $C = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
  foreach ($bars as $b) {
    $c = mkt_num($b['close'] ?? null); if ($c === null || $c <= 0) continue;
    $t = isset($b['tradeTimeinMills']) ? (int) floor($b['tradeTimeinMills'] / 1000) : strtotime(substr((string) ($b['tradeTime'] ?? ''), 0, 8));
    $C['t'][] = $t; $C['c'][] = $c; $C['o'][] = mkt_num($b['open'] ?? null) ?: $c; $C['h'][] = mkt_num($b['high'] ?? null) ?: $c; $C['l'][] = mkt_num($b['low'] ?? null) ?: $c; $C['v'][] = (float) ($b['volume'] ?? 0);
  }
  return count($C['c']) > 1 ? $C : null;
}
function mkt_cnbc_quotes(array $syms) {
  if (!$syms) return [];
  $out = [];
  foreach (array_chunk(array_values(array_unique($syms)), 25) as $chunk) {
    $r = mkt_http('https://quote.cnbc.com/quote-html-webservice/restQuote/symbolType/symbol?symbols=' . rawurlencode(implode('|', $chunk)) . '&requestMethod=itv&noform=1&partnerId=2&fund=1&exthrs=1&output=json&events=1');
    $j = json_decode($r['body'], true); $q = $j['FormattedQuoteResult']['FormattedQuote'] ?? [];
    if (isset($q['symbol'])) $q = [$q];
    foreach ($q as $row) if (!empty($row['symbol']) && (int) ($row['code'] ?? 0) === 0) $out[$row['symbol']] = $row;
  }
  return $out;
}
/* bring a daily series up to the latest quote */
function mkt_patch_with_quote(array $C, array $q) {
  $last = mkt_num($q['last'] ?? null); if ($last === null || !count($C['c'])) return $C;
  $qt = !empty($q['last_time']) ? strtotime($q['last_time']) : time(); if (!$qt) return $C;
  $n = count($C['c']) - 1; $qd = gmdate('Y-m-d', $qt + MK_IST); $ld = mk_ist_date($C['t'][$n]);
  if ($qd === $ld) { $C['c'][$n] = $last; $C['h'][$n] = max($C['h'][$n], $last); $C['l'][$n] = min($C['l'][$n], $last); }
  elseif ($qd > $ld) {
    $o = mkt_num($q['open'] ?? null) ?: $last;
    $C['t'][] = $qt; $C['o'][] = $o; $C['h'][] = mkt_num($q['high'] ?? null) ?: max($o, $last); $C['l'][] = mkt_num($q['low'] ?? null) ?: min($o, $last); $C['c'][] = $last; $C['v'][] = mkt_num($q['volume'] ?? null) ?: 0;
  }
  return $C;
}

/* ---------- the fallback fetcher: specs Yahoo could not serve ---------- */
function mkt_fallback_charts(array $specs) {
  $out = []; $today = gmdate('Y-m-d', time() + MK_IST);
  $days = function ($range) { return ['5d' => 10, '1mo' => 35, '3mo' => 100, '6mo' => 190, '1y' => 370, '2y' => 740, '5y' => 1830][$range] ?? 370; };
  /* 1) Indian symbols -> Upstox */
  $up = []; $cn = [];
  foreach ($specs as $k => $s) { $key = mkt_upstox_key($s[0]); if ($key) $up[$k] = $s + [3 => $key]; elseif (mkt_cnbc_symbol($s[0])) $cn[$k] = $s; }
  if ($up) {
    $reqs = []; $base = 'https://api.upstox.com';
    $symsToday = [];
    foreach ($up as $k => $s) {
      list($sym, $range, $int, $key) = $s; $ek = rawurlencode($key[0]); $symsToday[$sym] = $ek;
      if ($int === '1d') $reqs["$k|h"] = ['url' => "$base/v2/historical-candle/$ek/day/$today/" . gmdate('Y-m-d', time() + MK_IST - $days($range) * 86400)];
      elseif ($int === '5m') $reqs["$k|h"] = ['url' => "$base/v3/historical-candle/$ek/minutes/5/$today/" . gmdate('Y-m-d', time() + MK_IST - 9 * 86400)];
      elseif ($int === '15m') {
        $reqs["$k|h"] = ['url' => "$base/v3/historical-candle/$ek/minutes/15/$today/" . gmdate('Y-m-d', time() + MK_IST - 29 * 86400)];
        $reqs["$k|h2"] = ['url' => "$base/v3/historical-candle/$ek/minutes/15/" . gmdate('Y-m-d', time() + MK_IST - 30 * 86400) . '/' . gmdate('Y-m-d', time() + MK_IST - 58 * 86400)];
      }
    }
    foreach ($symsToday as $sym => $ek) $reqs["today|$sym"] = ['url' => "$base/v3/historical-candle/intraday/$ek/minutes/5"];
    foreach ($reqs as &$r) $r['headers'] = ['Accept: application/json']; unset($r);
    $res = mkt_http_multi($reqs, 20, 8);
    $todayBars = [];
    foreach ($symsToday as $sym => $ek) { $b = $res["today|$sym"] ?? null; $todayBars[$sym] = ($b && $b['code'] === 200) ? mkt_upstox_parse($b['body']) : null; }
    foreach ($up as $k => $s) {
      list($sym, $range, $int, $key) = $s; $h = $res["$k|h"] ?? null;
      $C = ($h && $h['code'] === 200) ? mkt_upstox_parse($h['body']) : null;
      if ($int === '15m' && isset($res["$k|h2"]) && $res["$k|h2"]['code'] === 200) { $C2 = mkt_upstox_parse($res["$k|h2"]['body']); if ($C2) $C = $C ? mkt_candles_merge($C2, $C) : $C2; }
      $T = $todayBars[$sym];
      if ($T && count($T['c'])) {
        if ($int === '1d') { $db = mkt_day_bar($T); if ($db) $C = $C ? mkt_candles_merge($C, $db) : $db; }
        elseif ($int === '5m') $C = $C ? mkt_candles_merge($C, $T) : $T;
        elseif ($int === '15m') { $T15 = mk_resample($T, 3); $C = $C ? mkt_candles_merge($C, $T15) : $T15; }
      }
      if (!$C || count($C['c']) < 2) { if (mkt_cnbc_symbol($sym)) $cn[$k] = $s; continue; }
      if ($int === '5m') $C = mkt_keep_sessions($C, 5);
      $C = mk_clean($C);
      $C['meta'] = ['symbol' => $sym, 'currency' => 'INR', 'name' => $key[1], 'exchange' => 'NSE', 'price' => end($C['c']), 'time' => end($C['t']), 'source' => 'Upstox'];
      $out[$k] = $C;
    }
  }
  /* 2) everything else (and Indian symbols Upstox missed) -> CNBC daily bars + live quote */
  $cn = array_filter($cn, function ($s) { return $s[2] === '1d'; }); // CNBC has no free intraday history
  if ($cn) {
    $reqs = []; foreach ($cn as $k => $s) $reqs[$k] = ['url' => 'https://ts-api.cnbc.com/harmony/app/charts/' . (in_array($s[1], ['2y', '5y'], true) ? '5Y' : '1Y') . '.json?symbol=' . rawurlencode(mkt_cnbc_symbol($s[0]))];
    $res = mkt_http_multi($reqs, 20, 8);
    $quotes = mkt_cnbc_quotes(array_map(function ($s) { return mkt_cnbc_symbol($s[0]); }, $cn));
    foreach ($cn as $k => $s) {
      $C = ($res[$k]['code'] ?? 0) === 200 ? mkt_cnbc_parse_chart($res[$k]['body']) : null;
      if (!$C) continue;
      $cs = mkt_cnbc_symbol($s[0]); $q = $quotes[$cs] ?? null;
      if ($q) $C = mkt_patch_with_quote($C, $q);
      $n = count($C['c']); if (time() - $C['t'][$n - 1] > 10 * 86400) continue; // stale feed: better missing than wrong
      $keep = ['5d' => 5, '1mo' => 23, '3mo' => 66, '6mo' => 130, '1y' => 260, '2y' => 520][$s[1]] ?? 520;
      foreach (['t', 'o', 'h', 'l', 'c', 'v'] as $f) $C[$f] = array_slice($C[$f], -$keep);
      $C = mk_clean($C);
      $C['meta'] = ['symbol' => $s[0], 'currency' => $q['currencyCode'] ?? null, 'name' => $q['name'] ?? $cs, 'exchange' => $q['exchange'] ?? null, 'price' => end($C['c']), 'time' => end($C['t']), 'source' => 'CNBC'];
      $out[$k] = $C;
    }
  }
  return $out;
}

/* ---------- fundamentals from CNBC, shaped like Yahoo's quoteSummary ---------- */
function mkt_cnbc_fundamentals($sym) {
  $cs = mkt_cnbc_symbol($sym); if (!$cs || substr($cs, -3) !== '-IN') return null;
  $ck = "cnbcf|$sym"; $hit = mkt_cache_get($ck, mkt_ttl('fund')); if ($hit) return $hit;
  $q = mkt_cnbc_quotes([$cs])[$cs] ?? null; if (!$q) return null;
  $raw = function ($v) { return $v === null ? null : ['raw' => $v]; };
  $pct = function ($v) { $n = mkt_num($v); return $n === null ? null : $n / 100; };
  $Q = [
    'price' => ['longName' => $q['name'] ?? null, 'marketCap' => $raw(mkt_num_suffix($q['mktcapView'] ?? null))],
    'summaryDetail' => ['trailingPE' => $raw(mkt_num($q['pe'] ?? null)), 'dividendYield' => $raw($pct($q['dividendyield'] ?? null)), 'beta' => $raw(mkt_num($q['beta'] ?? null)),
                        'priceToSalesTrailing12Months' => $raw(mkt_num($q['psales'] ?? null))],
    'defaultKeyStatistics' => ['trailingEps' => $raw(mkt_num($q['eps'] ?? null))],
    'financialData' => ['returnOnEquity' => $raw($pct($q['ROETTM'] ?? null)), 'profitMargins' => $raw($pct($q['NETPROFTTM'] ?? null)),
                        'grossMargins' => $raw($pct($q['GROSMGNTTM'] ?? null)), 'debtToEquity' => $raw(mkt_num($q['DEBTEQTYQ'] ?? null))],
    '_source' => 'CNBC',
  ];
  foreach ($Q as $mod => &$fields) if (is_array($fields)) $fields = array_filter($fields, function ($v) { return $v !== null; }); unset($fields);
  mkt_cache_set($ck, $Q);
  return $Q;
}

/* ---------- NSE index snapshot: P/E, P/B, breadth for every index ---------- */
function mkt_nse_indices() {
  $j = mkt_nse_json('/api/allIndices'); $rows = $j['data'] ?? null; if (!is_array($rows)) return null;
  $out = [];
  foreach ($rows as $r) {
    $name = $r['index'] ?? ($r['indexSymbol'] ?? null); if (!$name) continue;
    $out[strtoupper($name)] = ['name' => $name, 'last' => mkt_num($r['last'] ?? null), 'chg_pct' => mkt_num($r['percentChange'] ?? null), 'pe' => mkt_num($r['pe'] ?? null),
      'pb' => mkt_num($r['pb'] ?? null), 'dy' => mkt_num($r['dy'] ?? null), 'advances' => isset($r['advances']) ? (int) $r['advances'] : null, 'declines' => isset($r['declines']) ? (int) $r['declines'] : null,
      'chg_30d' => mkt_num($r['perChange30d'] ?? null), 'chg_365d' => mkt_num($r['perChange365d'] ?? null), 'year_high' => mkt_num($r['yearHigh'] ?? null), 'year_low' => mkt_num($r['yearLow'] ?? null)];
  }
  return $out ?: null;
}

/* ---------- symbol search without Yahoo ---------- */
function mkt_search_master($q) {
  $M = mkt_upstox_master(); $uq = strtoupper(trim($q)); $out = [];
  if ($uq === '' || !$M['eq']) return [];
  foreach ($M['eq'] as $sym => $e) { if (strpos($sym, $uq) === 0) $out[$sym] = ['symbol' => $sym, 'yahoo' => $sym . '.NS', 'name' => $e[1], 'exchange' => 'NSE']; if (count($out) >= 8) break; }
  if (count($out) < 12 && strlen($uq) >= 3) foreach ($M['eq'] as $sym => $e) { if (stripos($e[1], $uq) !== false) $out[$sym] = ['symbol' => $sym, 'yahoo' => $sym . '.NS', 'name' => $e[1], 'exchange' => 'NSE']; if (count($out) >= 12) break; }
  return array_values($out);
}
