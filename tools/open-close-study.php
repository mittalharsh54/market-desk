<?php
/* Open-to-close study: does the Top 10's DIRECTION call make money if you simply
   take it at the open and hold to the close (no intraday signals)?
     php tools/open-close-study.php [stocks=260] [years=3]
   For every past session it rebuilds the pre-open pick list the way the app does
   (daily setup of each stock up to the previous close, same filters, pick score,
   max 3 per sector) — but only from daily data: the live 5-minute look, the stock
   news and the 60-day intraday backtest of the real app are not available
   historically. Then each pick is traded in its direction from the open to the
   close with Indian intraday charges on a Rs 2,000 position.
   The last third of the period is a hold-out. Run it from GitHub Actions. */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../market.php';
ini_set('memory_limit', '2048M'); set_time_limit(0);

$max = (int) ($argv[1] ?? 260); $years = max(2, (int) ($argv[2] ?? 3));
$today = date('Y-m-d', time() + 19800);
$d = function ($n) use ($today) { return date('Y-m-d', strtotime("$today -$n days")); };

/* ---------- data ---------- */
$syms = array_slice(id_universe(), 0, $max);
$keys = ['NIFTY' => 'NSE_INDEX|Nifty 50'];
foreach ($syms as $s) { $k = mkt_upstox_key(mkt_norm_symbol($s) ?: $s); if ($k) $keys[$s] = $k[0]; }
$want = [];
foreach ($keys as $s => $k) for ($y = 0; $y <= $years; $y++)
  $want["$s|$y"] = ['url' => 'https://api.upstox.com/v2/historical-candle/' . rawurlencode($k) . '/day/' . $d($y * 365) . '/' . $d($y * 365 + 364), 'headers' => ['Accept: application/json']];
$res = mkt_http_multi($want, 30, 6);
$D = [];
foreach ($keys as $s => $k) {
  $C = null;
  for ($y = 0; $y <= $years; $y++) { $x = $res["$s|$y"] ?? null; if ($x && $x['code'] === 200) { $P = mkt_upstox_parse($x['body']); if ($P && count($P['c'])) { $P = mk_clean($P); $C = $C ? mkt_candles_merge($P, $C) : $P; } } }
  if ($C && count($C['c']) > 300) $D[$s] = $C;
}
$N = $D['NIFTY'] ?? null; unset($D['NIFTY']);
if (!$N) { fwrite(STDERR, "no Nifty data\n"); exit(1); }
$dates = array_map('mk_ist_date', $N['t']); $T = count($dates);
/* index every stock by date */
$ix = []; foreach ($D as $s => $C) foreach ($C['t'] as $i => $t) $ix[$s][mk_ist_date($t)] = $i;
$start = 260; $split = $start + (int) (2 * ($T - $start) / 3);
fwrite(STDERR, count($D) . " stocks, test " . $dates[$start] . " → " . $dates[$T - 1] . ", hold-out from " . $dates[$split] . "\n");

function oc_slice(array $C, $end, $len = 260) { $a = max(0, $end - $len + 1); $o = []; foreach ($C as $k => $v) $o[$k] = array_slice($v, $a, $end - $a + 1); return $o; }
$cost = function ($v) { return mk_charges([['B', $v], ['S', $v]]) / $v * 100; }; // round trip, % of a position
$COST = $cost(2000);

/* ---------- day by day: build the list from yesterday's data, trade today open→close ---------- */
$rows = []; // one per pick-day
$gaps = []; // gap-fade and gap-and-go trades
$nifty_oc = [];
for ($k = $start; $k < $T; $k++) {
  $day = $dates[$k]; $prev = $dates[$k - 1];
  $nb = oc_slice($N, $k - 1);
  $reg = round(mk_tech_score_at(mk_daily_arrays($nb), count($nb['c']) - 1)['score'] * 0.6, 3); // market regime from the Nifty trend
  $nifty_oc[$day] = ($N['c'][$k] / $N['o'][$k] - 1) * 100;
  $cands = [];
  foreach ($D as $s => $C) {
    $ip = $ix[$s][$prev] ?? null; $it = $ix[$s][$day] ?? null; if ($ip === null || $it === null || $ip < 60) continue;
    $st = mk_daily_setup(oc_slice($C, $ip), $nb); if (!$st) continue;
    if ($st['close'] < 50 || $st['turnover_cr'] < 25 || ($st['atr_pct'] ?? 0) < 0.8) continue;
    $P = mk_pick_score($st, null, null, null, null, $reg);
    $o = $C['o'][$it]; $c = $C['c'][$it]; $h = $C['h'][$it]; $l = $C['l'][$it];
    $cands[] = ['sym' => $s, 'sector' => mk_sector_of($s . '.NS'), 'dir' => $P['dir'] === 'LONG' ? 1 : -1, 'q' => $P['quality'], 'o' => $o, 'c' => $c, 'h' => $h, 'l' => $l, 'atr' => $st['atr'], 'gap' => ($o / $st['close'] - 1) * 100, 'pc' => $st['close']];
  }
  usort($cands, function ($a, $b) { return $b['q'] <=> $a['q']; });
  $picked = []; $per = [];
  foreach ($cands as $c) { if (count($picked) >= 10) break; if (abs($c['gap']) > 5) continue; if (($per[$c['sector']] ?? 0) >= 3) continue; $per[$c['sector']] = ($per[$c['sector']] ?? 0) + 1; $picked[] = $c; }
  /* gap trades: the 5 biggest opening gaps between 1.5% and 5% (daily bars: if the stop and the target
     were both touched, assume the stop came first) */
  $G = array_values(array_filter($cands, function ($c) { return abs($c['gap']) >= 1.5 && abs($c['gap']) <= 5; }));
  usort($G, function ($a, $b) { return abs($b['gap']) <=> abs($a['gap']); });
  foreach (array_slice($G, 0, 5) as $c) {
    $up = $c['gap'] > 0;
    /* fade: trade back toward yesterday's close; stop 0.5 ATR beyond the open */
    $stop = $up ? $c['o'] + 0.5 * $c['atr'] : $c['o'] - 0.5 * $c['atr'];
    $sHit = $up ? $c['h'] >= $stop : $c['l'] <= $stop; $tHit = $up ? $c['l'] <= $c['pc'] : $c['h'] >= $c['pc'];
    $fade = $sHit ? -0.5 * $c['atr'] / $c['o'] * 100 : ($tHit ? abs($c['o'] - $c['pc']) / $c['o'] * 100 : ($up ? -1 : 1) * ($c['c'] / $c['o'] - 1) * 100);
    /* go: trade with the gap; stop if the gap fills (price back at yesterday's close) */
    $fill = $up ? $c['l'] <= $c['pc'] : $c['h'] >= $c['pc'];
    $go = $fill ? -abs($c['o'] - $c['pc']) / $c['o'] * 100 : ($up ? 1 : -1) * ($c['c'] / $c['o'] - 1) * 100;
    $gaps[] = ['k' => $k, 'day' => $day, 'up' => $up, 'fade' => $fade - $COST, 'go' => $go - $COST, 'gross_fade' => $fade, 'gross_go' => $go];
  }
  foreach ($picked as $r => $c) {
    $raw = $c['dir'] * ($c['c'] / $c['o'] - 1) * 100;
    /* with a 1-ATR stop from the open (if the day's adverse extreme reached it, assume it was hit first) */
    $stop = $c['o'] - $c['dir'] * $c['atr']; $hit = $c['dir'] > 0 ? $c['l'] <= $stop : $c['h'] >= $stop;
    $rawStop = $hit ? -$c['atr'] / $c['o'] * 100 : $raw;
    $rows[] = ['k' => $k, 'day' => $day, 'rank' => $r + 1, 'dir' => $c['dir'], 'q' => $c['q'], 'net' => $raw - $COST, 'net_stop' => $rawStop - $COST, 'gross' => $raw,
               'right' => $raw > 0 ? 1 : 0, 'long_all' => ($c['c'] / $c['o'] - 1) * 100];
  }
}

/* ---------- summaries ---------- */
function oc_stats(array $R, $field = 'net') {
  $n = count($R); if (!$n) return null;
  $v = array_column($R, $field); $days = [];
  foreach ($R as $r) $days[$r['day']][] = $r[$field];
  $dayAvg = array_map(function ($x) { return array_sum($x) / count($x); }, $days);
  $eq = 1.0; $pk = 1.0; $dd = 0.0; foreach ($dayAvg as $x) { $eq *= 1 + $x / 100; $pk = max($pk, $eq); $dd = min($dd, $eq / $pk - 1); }
  $g = array_sum(array_filter($v, function ($x) { return $x > 0; })); $l = -array_sum(array_filter($v, function ($x) { return $x < 0; }));
  return ['trades' => $n, 'days' => count($days), 'win_pct' => round(count(array_filter($v, function ($x) { return $x > 0; })) / $n * 100, 1),
    'avg_pct_per_trade' => round(array_sum($v) / $n, 3), 'pf' => $l > 0 ? round($g / $l, 2) : null,
    'green_days_pct' => round(count(array_filter($dayAvg, function ($x) { return $x > 0; })) / max(1, count($dayAvg)) * 100, 1),
    'account_growth_pct' => round(($eq - 1) * 100, 1), 'max_dd_pct' => round($dd * 100, 1)];
}
$sets = [
  'Top 10, open→close' => function ($r) { return true; },
  'Top 5, open→close' => function ($r) { return $r['rank'] <= 5; },
  'Top 3, open→close' => function ($r) { return $r['rank'] <= 3; },
  'Top 10, longs only' => function ($r) { return $r['dir'] > 0; },
  'Top 10, shorts only' => function ($r) { return $r['dir'] < 0; },
];
echo json_encode(['note' => 'charges per round trip on a Rs 2,000 position', 'cost_pct' => round($COST, 3), 'from' => $dates[$start], 'holdout_from' => $dates[$split], 'to' => $dates[$T - 1]]), "\n";
foreach ($sets as $name => $f) {
  $R = array_values(array_filter($rows, $f));
  $tr = array_values(array_filter($R, function ($r) use ($split) { return $r['k'] < $split; }));
  $ho = array_values(array_filter($R, function ($r) use ($split) { return $r['k'] >= $split; }));
  foreach (['net' => 'open→close', 'net_stop' => 'with 1-ATR stop'] as $fld => $how) {
    if ($fld === 'net_stop' && strpos($name, 'only') !== false) continue;
    echo json_encode(['strategy' => $name . ($fld === 'net_stop' ? ' + 1-ATR stop' : ''), 'direction_right_pct' => round(array_sum(array_column($R, 'right')) / max(1, count($R)) * 100, 1),
      'gross_avg_pct' => round(array_sum(array_column($R, 'gross')) / max(1, count($R)), 3), 'tuning' => oc_stats($tr, $fld), 'holdout' => oc_stats($ho, $fld)]), "\n";
  }
}
foreach (['fade' => 'Gap fade (5 biggest gaps 1.5–5%, target = yesterday\'s close, 0.5-ATR stop)', 'go' => 'Gap and go (5 biggest gaps 1.5–5%, stop if the gap fills)'] as $f => $name) {
  $R = array_map(function ($g) use ($f) { return ['k' => $g['k'], 'day' => $g['day'], 'net' => $g[$f], 'gross' => $g['gross_' . $f], 'right' => $g['gross_' . $f] > 0 ? 1 : 0]; }, $gaps);
  $tr = array_values(array_filter($R, function ($r) use ($split) { return $r['k'] < $split; })); $ho = array_values(array_filter($R, function ($r) use ($split) { return $r['k'] >= $split; }));
  echo json_encode(['strategy' => $name, 'win_before_costs_pct' => round(array_sum(array_column($R, 'right')) / max(1, count($R)) * 100, 1), 'gross_avg_pct' => round(array_sum(array_column($R, 'gross')) / max(1, count($R)), 3), 'tuning' => oc_stats($tr), 'holdout' => oc_stats($ho)]), "\n";
}
/* baselines: buying every qualifying pick-day stock at the open (market drift), and the Nifty open→close */
$nv = array_values($nifty_oc);
echo json_encode(['strategy' => 'Baseline: Nifty open→close (no costs)', 'avg_pct' => round(array_sum($nv) / count($nv), 3), 'up_days_pct' => round(count(array_filter($nv, function ($x) { return $x > 0; })) / count($nv) * 100, 1)]), "\n";
$la = array_column($rows, 'long_all');
echo json_encode(['strategy' => 'Baseline: same picks, always LONG (no costs)', 'avg_pct' => round(array_sum($la) / max(1, count($la)), 3)]), "\n";
