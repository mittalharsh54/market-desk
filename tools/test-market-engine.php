<?php
/* Offline checks for market_engine.php — no network, no database.
     php tools/test-market-engine.php
   Builds synthetic markets (a steady uptrend, a steady downtrend, a random walk)
   and checks the indicators, scores, signals and backtests behave. */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../market_engine.php';

$fails = 0; $passes = 0;
function ok($cond, $msg) { global $fails, $passes; if ($cond) { $passes++; echo "  ok   $msg\n"; } else { $fails++; echo "  FAIL $msg\n"; } }
function near($a, $b, $tol = 1e-6) { return $a !== null && abs($a - $b) <= $tol; }

/* daily bars: drift per day, noise, seeded so runs repeat */
function synth_daily($n, $drift, $noise, $seed, $start = 1000.0) {
  mt_srand($seed); $C = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
  $p = $start; $t = gmmktime(10, 0, 0, 1, 2, 2024);
  for ($i = 0; $i < $n; $i++) {
    $r = $drift + $noise * ((mt_rand() / mt_getrandmax()) * 2 - 1);
    $o = $p; $c = $p * (1 + $r); $h = max($o, $c) * (1 + 0.004 * mt_rand(0, 100) / 100); $l = min($o, $c) * (1 - 0.004 * mt_rand(0, 100) / 100);
    $C['t'][] = $t + $i * 86400; $C['o'][] = $o; $C['h'][] = $h; $C['l'][] = $l; $C['c'][] = $c; $C['v'][] = 1e6 * (1 + ($r > 0 ? 0.4 : 0) + mt_rand(0, 50) / 100);
    $p = $c;
  }
  return $C;
}
/* 5-minute bars, 75 per session from 9:15 IST */
function synth_intraday($days, $drift, $noise, $seed, $start = 500.0) {
  mt_srand($seed); $C = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []]; $p = $start;
  for ($d = 0; $d < $days; $d++) {
    $day0 = gmmktime(3, 45, 0, 3, 2 + $d, 2026); // 9:15 IST = 03:45 UTC
    for ($k = 0; $k < 75; $k++) {
      $r = $drift + $noise * ((mt_rand() / mt_getrandmax()) * 2 - 1);
      $o = $p; $c = $p * (1 + $r);
      $C['t'][] = $day0 + $k * 300; $C['o'][] = $o; $C['c'][] = $c; $C['h'][] = max($o, $c) * 1.0008; $C['l'][] = min($o, $c) * 0.9992; $C['v'][] = 10000 + mt_rand(0, 5000);
      $p = $c;
    }
  }
  return $C;
}

echo "Indicators\n";
$lin = range(1, 60);
ok(near(mk_last(mk_sma($lin, 10)), 55.5), 'SMA(10) of 1..60 ends at 55.5');
ok(near(mk_last(mk_ema(array_fill(0, 50, 7.0), 9)), 7.0), 'EMA of a constant is the constant');
ok(near(mk_last(mk_rsi($lin, 14)), 100.0), 'RSI of a rising series is 100');
ok(near(mk_last(mk_rsi(array_reverse($lin), 14)), 0.0), 'RSI of a falling series is 0');
$flat = ['t' => range(0, 39), 'o' => array_fill(0, 40, 100.0), 'h' => array_fill(0, 40, 101.0), 'l' => array_fill(0, 40, 99.0), 'c' => array_fill(0, 40, 100.0), 'v' => array_fill(0, 40, 1.0)];
ok(near(mk_last(mk_atr($flat, 14)), 2.0), 'ATR of a constant 2-point range is 2');
$pv = mk_pivots(110, 90, 100);
ok(near($pv['P'], 100) && near($pv['R1'], 110) && near($pv['S1'], 90), 'Floor pivots P/R1/S1');
$bb = mk_boll(array_fill(0, 30, 50.0));
ok(near(mk_last($bb['up']), 50.0) && near(mk_last($bb['lo']), 50.0), 'Bollinger bands collapse on a constant');

echo "Daily uptrend\n";
$up = synth_daily(400, 0.0025, 0.012, 7);
$nifty = synth_daily(400, 0.0008, 0.008, 8, 20000);
$T = mk_tech_report($up, $nifty);
ok($T['score'] > 0.3, 'uptrend technical score is clearly positive (' . $T['score'] . ')');
ok($T['indicators']['supertrend_dir'] === 1, 'Supertrend in buy mode');
ok($T['indicators']['sma50'] > $T['indicators']['sma200'], '50-DMA above 200-DMA');
ok($T['returns']['3m'] > 0, '3-month return positive');
ok(isset($T['risk']['beta']) && $T['risk']['beta'] !== null, 'beta computed against the benchmark');
$SW = mk_swing($T, 100000, 1);
ok(strpos($SW['action'], 'BUY') !== false || strpos($SW['action'], 'LONG') !== false, 'swing verdict is long-side (' . $SW['action'] . ')');
ok($SW['plan'] === null || ($SW['plan']['stop'] < $T['price'] && $SW['plan']['targets'][0] > $T['price']), 'swing plan: stop below, target above');
$LT = mk_longterm($T, null, null, 0.2);
ok(in_array($LT['rating'], ['STRONG BUY', 'BUY', 'ACCUMULATE ON DIPS'], true), 'long-term rating is a buy (' . $LT['rating'] . ')');
ok($LT['stop'] < $T['price'] && $LT['target'] > $T['price'], 'long-term stop below and target above');
$BT = mk_backtest_swing($T['_A']);
ok(isset($BT['trades']), 'swing backtest ran (' . $BT['trades'] . ' trades, win ' . ($BT['win_rate'] ?? '-') . '%)');

echo "Daily downtrend\n";
$dn = synth_daily(400, -0.0025, 0.012, 9);
$T2 = mk_tech_report($dn, $nifty);
ok($T2['score'] < -0.3, 'downtrend technical score is clearly negative (' . $T2['score'] . ')');
$LT2 = mk_longterm($T2, null, null, -0.2);
ok(in_array($LT2['rating'], ['REDUCE', 'SELL / AVOID'], true), 'long-term rating is a sell (' . $LT2['rating'] . ')');
$ST2 = mk_structure(mk_levels($dn), mk_last($dn["c"])); ok($ST2["score"] <= 0, "structure is not called an uptrend (" . $ST2["label"] . ")");

echo "Random walk\n";
$rw = synth_daily(400, 0.0, 0.015, 11);
$T3 = mk_tech_report($rw, $nifty);
ok(abs($T3['score']) <= 1, 'score stays within [-1, 1] (' . $T3['score'] . ')');
ok(count($T3['levels']['support']) + count($T3['levels']['resistance']) > 0, 'support/resistance zones found');

echo "Fundamentals\n";
$Q = ['financialData' => ['returnOnEquity' => ['raw' => 0.24], 'operatingMargins' => ['raw' => 0.26], 'profitMargins' => ['raw' => 0.18], 'revenueGrowth' => ['raw' => 0.16],
        'earningsGrowth' => ['raw' => 0.22], 'debtToEquity' => ['raw' => 12.0], 'currentRatio' => ['raw' => 2.1], 'freeCashflow' => ['raw' => 5e10], 'targetMeanPrice' => ['raw' => 1200],
        'recommendationMean' => ['raw' => 1.9], 'numberOfAnalystOpinions' => ['raw' => 30]],
      'summaryDetail' => ['trailingPE' => ['raw' => 22.0], 'forwardPE' => ['raw' => 19.0]], 'defaultKeyStatistics' => ['priceToBook' => ['raw' => 5.0], 'heldPercentInsiders' => ['raw' => 0.55]]];
$FU = mk_fundamentals($Q, 1000, 'it');
ok($FU['available'] && $FU['score'] > 0.2, 'high-quality IT profile scores well (' . $FU['score'] . ')');
$Qb = ['financialData' => ['returnOnEquity' => ['raw' => 0.04], 'revenueGrowth' => ['raw' => -0.1], 'earningsGrowth' => ['raw' => -0.4], 'debtToEquity' => ['raw' => 250.0], 'profitMargins' => ['raw' => -0.02]],
       'summaryDetail' => ['trailingPE' => ['raw' => 80.0]]];
$FB = mk_fundamentals($Qb, 100, 'auto');
ok($FB['score'] < -0.2, 'weak, leveraged profile scores badly (' . $FB['score'] . ')');
ok(!array_filter($FU['factors'], function ($f) { return $f['key'] === 'de'; }) === false, 'D/E is scored for a non-financial');
$FF = mk_fundamentals($Q, 1000, 'banks');
ok(!array_filter($FF['factors'], function ($f) { return $f['key'] === 'de'; }), 'D/E is skipped for a bank');

echo "Intraday\n";
$i5 = synth_intraday(5, 0.0006, 0.0015, 21);
$n5 = synth_intraday(5, 0.0001, 0.001, 22, 24000);
$IS = mk_intraday_signal($i5, $n5, ['daily_score' => 0.4, 'market_score' => 0.2]);
ok(!isset($IS['error']), 'intraday signal computed');
ok(strpos($IS['action'], 'BUY') === 0, 'rising session gives a BUY (' . $IS['action'] . ', ' . $IS['score'] . ')');
ok($IS['plan'] && $IS['plan']['stop'] < $IS['session']['price'] && $IS['plan']['targets'][0] > $IS['session']['price'], 'intraday plan: stop below, target above');
ok($IS['plan']['qty'] > 0 && $IS['plan']['max_loss'] <= 1000 + 1, 'position size respects the 1% risk budget (max loss ₹' . $IS['plan']['max_loss'] . ')');
ok($IS['session']['or_high'] !== null, 'opening range computed');
$dn5 = synth_intraday(5, -0.0006, 0.0015, 23);
$IS2 = mk_intraday_signal($dn5, $n5, []);
ok(strpos($IS2['action'], 'SELL') === 0, 'falling session gives a SELL (' . $IS2['action'] . ')');
$i15 = mk_resample(synth_intraday(40, 0.0, 0.002, 31), 3);
ok(count($i15['c']) === 40 * 25, '5-min → 15-min resample gives 25 bars a session');
$BI = mk_backtest_intraday(mk_intraday_arrays($i15));
ok(isset($BI['trades']), 'intraday backtest ran (' . $BI['trades'] . ' trades, win ' . ($BI['win_rate'] ?? '-') . '%)');

echo "Macro\n";
$ins = [];
foreach (mk_macro_catalog() as $def) {
  $drift = in_array($def['driver'], ['crude', 'us_vix', 'india_vix', 'usdinr', 'dxy', 'us10y'], true) ? 0.004 : -0.003;
  $ins[] = mk_instrument(synth_daily(80, $drift, 0.004, crc32($def['sym'])), $def);
}
$R = mk_regime($ins, []);
ok($R['score'] < -0.12, 'crude/VIX/dollar up + equities down reads as risk-off (' . $R['score'] . ', ' . $R['label'] . ')');
$SV = mk_sector_view($R['drivers'], []);
ok($SV['oil_upstream']['macro'] > 0 && $SV['omc']['macro'] < 0, 'rising crude: upstream helped, OMCs hurt');
ok($SV['it']['macro'] > $SV['realty']['macro'], 'weak rupee + high yields: IT ahead of realty');
$NS = mk_news_score([['title' => 'Sensex, Nifty surge to record high as FIIs return'], ['title' => 'Rupee plunges as crude oil prices soar on war fears'], ['title' => 'RBI cuts repo rate, markets rally']]);
ok($NS['items'][0]['sentiment'] > 0 && $NS['items'][1]['sentiment'] < 0, 'headline sentiment signs are right');
ok(in_array('Crude oil', $NS['items'][1]['tags'], true) && in_array('RBI & rates', $NS['items'][2]['tags'], true), 'headline topics tagged');
$II = mk_india_inputs_score(['cpi' => 3.5, 'gdp' => 7.4, 'pmi_mfg' => 57, 'rbi_stance' => 'Easing']);
ok($II['score'] > 0.3, 'benign India macro inputs score positive (' . $II['score'] . ')');

echo "Mapping & clock\n";
ok(mk_sector_of('BPCL.NS') === 'omc' && mk_sector_of('TCS.NS') === 'it' && mk_sector_of('XYZ.NS', 'Technology', 'Software') === 'it', 'sector mapping');
ok(mk_market_status(gmmktime(5, 0, 0, 9, 23, 2026))['open'] === true, 'Wed 10:30 IST is market hours');
ok(mk_market_status(gmmktime(5, 0, 0, 9, 26, 2026))['open'] === false, 'Saturday is closed');

echo "\n$passes passed, $fails failed\n";
exit($fails ? 1 : 0);
