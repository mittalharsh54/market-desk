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
function mkt_join(array $a, array $b) { foreach ($a as $k => $v) $a[$k] = array_merge($v, $b[$k]); return $a; }
function synth_intraday($days, $drift, $noise, $seed, $start = 500.0) {
  mt_srand($seed); $C = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []]; $p = $start;
  for ($d = 0; $d < $days; $d++) {
    $day0 = gmmktime(3, 45, 0, 3, 2 + $d, 2026); // 9:15 IST = 03:45 UTC
    for ($k = 0; $k < 75; $k++) {
      $r = $drift + $noise * ((mt_rand() / mt_getrandmax()) * 2 - 1);
      $o = $p; $c = $p * (1 + $r);
      $C['t'][] = $day0 + $k * 300; $C['o'][] = $o; $C['c'][] = $c; $C['h'][] = max($o, $c) * 1.0008; $C['l'][] = min($o, $c) * 0.9992; $C['v'][] = (10000 + mt_rand(0, 5000)) * (0.7 + 1.2 * abs($r) / max(abs($drift) + $noise, 1e-9)); // bigger moves trade more volume, as in real markets
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

echo "Intraday Top 10 engine\n";
$dUp = synth_daily(300, 0.002, 0.012, 41); $dN = synth_daily(300, 0.0005, 0.008, 42, 20000);
$ST = mk_daily_setup($dUp, $dN);
ok($ST && $ST['trend'] > 0 && $ST['turnover_cr'] > 0 && $ST['atr_pct'] > 0, 'daily setup features computed (trend ' . $ST['trend'] . ', ATR ' . $ST['atr_pct'] . '%)');
ok(isset($ST['nr7'], $ST['inside'], $ST['close_loc'], $ST['cpr_width']), 'setup flags present');
$PS = mk_pick_score($ST, ['trades' => 20, 'win_rate' => 55, 'profit_factor' => 1.6, 'long_win_rate' => 58, 'short_win_rate' => 50], ['score' => 0.5, 'gap' => 0.6, 'rvol' => 1.8, 'price' => 105, 'open' => 102], null, 0.2, 0.1);
ok($PS['dir'] === 'LONG' && $PS['quality'] > 0.2, 'strong uptrend + live strength ranks as a quality LONG (' . $PS['quality'] . ')');
$PS2 = mk_pick_score(mk_daily_setup(synth_daily(300, -0.002, 0.012, 43), $dN), null, ['score' => -0.5, 'gap' => -0.5, 'rvol' => 1.5, 'price' => 98, 'open' => 99]);
ok($PS2['dir'] === 'SHORT', 'downtrend + live weakness ranks as a SHORT');
/* replay: 4 quiet sessions, then a trending day */
$pre = synth_intraday(4, 0.0, 0.0012, 51, 500); $day = synth_intraday(1, 0.0009, 0.0010, 52, end($pre['c']));
foreach ($day['t'] as $k => $t) $day['t'][$k] = $t + 4 * 86400; // make it the 5th session
$five = mkt_join($pre, $day);
$R = mk_intraday_replay($five, null, ['now' => end($five['t']) + 3600, 'bias' => 'LONG', 'capital' => 100000, 'risk_pct' => 1]);
ok(count($R['trades']) >= 1 && $R['trades'][0]['side'] === 'LONG', 'trending-up session triggers a LONG (' . count($R['trades']) . ' trade(s), day ' . $R['day_r'] . 'R)');
ok(($R['events'][0]['type'] ?? '') === 'BUY' && strpos($R['status'], 'DONE') === 0, 'events start with BUY, session ends DONE (' . $R['status'] . ')');
$firstBuy = $R['events'][0]['time'] ?? ''; ok($firstBuy >= '09:35', 'no entry before the opening range completes (first ' . $firstBuy . ')');
$dayDn = synth_intraday(1, -0.0009, 0.0010, 53, end($pre['c'])); foreach ($dayDn['t'] as $k => $t) $dayDn['t'][$k] = $t + 4 * 86400;
$R2 = mk_intraday_replay(mkt_join($pre, $dayDn), null, ['now' => end($dayDn['t']) + 3600, 'bias' => 'SHORT']);
ok(count($R2['trades']) >= 1 && $R2['trades'][0]['side'] === 'SHORT', 'trending-down session triggers a SHORT (' . $R2['day_r'] . 'R)');
/* mid-session: an open position is reported with live P&L */
$mid = mkt_join($pre, ['t' => array_slice($day['t'], 0, 40), 'o' => array_slice($day['o'], 0, 40), 'h' => array_slice($day['h'], 0, 40), 'l' => array_slice($day['l'], 0, 40), 'c' => array_slice($day['c'], 0, 40), 'v' => array_slice($day['v'], 0, 40)]);
$R3 = mk_intraday_replay($mid, null, ['now' => $mid['t'][count($mid['t']) - 1] + 120, 'bias' => 'LONG']);
ok($R3['live'] && $R3['live']['price'] > 0 && $R3['levels']['orh'] !== null, 'mid-session snapshot has live price and levels (' . $R3['status'] . ')');
ok($R3['status'] !== 'LONG ACTIVE' || ($R3['position']['stop'] < $R3['position']['entry'] || $R3['position']['t1_hit']), 'active long has its stop below entry (or at cost after T1)');
/* same data, same answer */
$R4 = mk_intraday_replay($five, null, ['now' => end($five['t']) + 3600, 'bias' => 'LONG']);
ok(json_encode($R4['events']) === json_encode($R['events']), 'replay is deterministic');
$R2b = mk_intraday_replay(mkt_join($pre, $dayDn), null, ['now' => end($dayDn['t']) + 3600, 'bias' => 'SHORT', 'long_only' => true]);
ok(count(array_filter($R2b['trades'], function ($t) { return $t['side'] === 'SHORT'; })) === 0, 'Buy only: no short trades on a falling day');
/* volume: entries need participation; thin-volume triggers are refused */
ok(strpos($R['events'][0]['note'] ?? '', 'x normal volume') !== false, 'BUY signal states the volume behind it');
$thin = $five; $nv = count($thin['v']); for ($k = $nv - 75; $k < $nv; $k++) $thin['v'][$k] = 12000.0; // flat volume all day
$R5 = mk_intraday_replay($thin, null, ['now' => end($thin['t']) + 3600, 'bias' => 'LONG', 'capital' => 100000, 'risk_pct' => 1]);
ok(count($R5['trades']) === 0, 'no entry when no bar trades above normal volume (' . count($R5['trades']) . ' trades)');
$A6 = mk_intraday_arrays($five); $F6 = mk_intraday_score_at($A6, count($five['c']) - 1)['factors'];
$vp = array_values(array_filter($F6, function ($f) { return $f['key'] === 'volp'; }));
ok($vp && $vp[0]['score'] >= -1 && $vp[0]['score'] <= 1, 'volume-pressure factor is scored in -1..+1');
$up = ['t' => [0, 1], 'o' => [100, 100], 'h' => [102, 102], 'l' => [99, 99], 'c' => [102, 99], 'v' => [1000, 1000]];
$Au = mk_intraday_arrays($up); ok($Au['sv'][0] > 0 && $Au['sv'][1] < 0, 'close at the high counts as buying volume, at the low as selling');

echo "\n$passes passed, $fails failed\n";
exit($fails ? 1 : 0);
