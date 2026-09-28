<?php
/* Strategy lab: several well-known swing / positional techniques, tested the same way.
     php tools/strategy-lab.php [stocks=260] [years=4]
   Every strategy runs on real NSE daily data (Upstox) with FIXED, pre-declared settings,
   inside a Rs 10,000 account (2 or 4 positions, delivery charges incl. the DP charge,
   marked to market daily). The last third of the period is a hold-out: judge on it.
   Baselines: Nifty 50 buy-and-hold, and equal-weight buy-and-hold of the same stocks
   (today's liquid stocks are survivors, which flatters every long strategy — the
   equal-weight baseline has the same bias, so beating it is the fair test).
   Prints one JSON line per strategy x account size. Run it from GitHub Actions. */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../market.php';
ini_set('memory_limit', '2048M'); set_time_limit(0);

$max = (int) ($argv[1] ?? 260); $years = max(3, (int) ($argv[2] ?? 4));
$today = date('Y-m-d', time() + 19800);
$d = function ($n) use ($today) { return date('Y-m-d', strtotime("$today -$n days")); };
function lab_cost_rs($v) { return $v * (0.002 + 0.00015 + 2 * (0.0000297 + 0.000001) * 1.18) + 15.93; } // delivery round trip

/* ---------- data ---------- */
$syms = array_slice(id_universe(), 0, $max);
$keys = ['NIFTY' => 'NSE_INDEX|Nifty 50'];
foreach ($syms as $s) { $k = mkt_upstox_key(mkt_norm_symbol($s) ?: $s); if ($k) $keys[$s] = $k[0]; }
$want = [];
foreach ($keys as $s => $k) for ($y = 0; $y <= $years; $y++)
  $want["$s|$y"] = ['url' => 'https://api.upstox.com/v2/historical-candle/' . rawurlencode($k) . '/day/' . $d($y * 365) . '/' . $d($y * 365 + 364), 'headers' => ['Accept: application/json']];
$res = getenv('LAB_SYNTH') ? [] : mkt_http_multi($want, 30, 6);
$D = [];
foreach ($keys as $s => $k) {
  $C = null;
  for ($y = 0; $y <= $years; $y++) { $x = $res["$s|$y"] ?? null; if ($x && $x['code'] === 200) { $P = mkt_upstox_parse($x['body']); if ($P && count($P['c'])) { $P = mk_clean($P); $C = $C ? mkt_candles_merge($P, $C) : $P; } } }
  if ($C && count($C['c']) > 300) $D[$s] = $C;
}
if (getenv('LAB_SYNTH')) { // offline self-check with random walks
  mt_srand(7); foreach (array_merge(['NIFTY'], array_slice($syms, 0, 30)) as $s) { $C = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []]; $px = 500;
    for ($i = 0; $i < 1100; $i++) { $t = gmmktime(10, 0, 0, 1, 1 + $i + 2 * intdiv($i, 5), 2022); $r = 0.0004 + 0.015 * (mt_rand() / mt_getrandmax() * 2 - 1); $o = $px; $px *= 1 + $r;
      $C['t'][] = $t; $C['o'][] = $o; $C['h'][] = max($o, $px) * 1.006; $C['l'][] = min($o, $px) * 0.994; $C['c'][] = $px; $C['v'][] = 1e6; }
    $D[$s] = $C; }
}
$N = $D['NIFTY'] ?? null; unset($D['NIFTY']);
if (!$N) { fwrite(STDERR, "no Nifty data\n"); exit(1); }
$dates = array_map('mk_ist_date', $N['t']); $di = array_flip($dates); $T = count($dates);
/* per stock, arrays on the Nifty calendar (null where the stock has no bar) */
$P = [];
foreach ($D as $s => $C) {
  $o = array_fill(0, $T, null); $h = $o; $l = $o; $c = $o;
  foreach ($C['t'] as $i => $t) { $k = $di[mk_ist_date($t)] ?? null; if ($k === null) continue; $o[$k] = $C['o'][$i]; $h[$k] = $C['h'][$i]; $l[$k] = $C['l'][$i]; $c[$k] = $C['c'][$i]; }
  for ($k = 1; $k < $T; $k++) if ($c[$k] === null && $c[$k - 1] !== null) { $c[$k] = $c[$k - 1]; } // carry the last close over gaps (for marking only)
  $P[$s] = ['o' => $o, 'h' => $h, 'l' => $l, 'c' => $c, 'C' => $C];
}
$start = 260; if ($T - $start < 250) { fwrite(STDERR, "not enough history\n"); exit(1); }
$split = $start + (int) (2 * ($T - $start) / 3);
fwrite(STDERR, count($P) . " stocks, " . $dates[$start] . " → " . $dates[$T - 1] . ", hold-out from " . $dates[$split] . "\n");

/* ---------- indicators on the calendar ---------- */
function lab_sma(array $c, $n) { $o = []; $s = 0.0; $q = []; foreach ($c as $i => $v) { if ($v === null) { $o[$i] = null; continue; } $q[] = $v; $s += $v; if (count($q) > $n) $s -= array_shift($q); $o[$i] = count($q) === $n ? $s / $n : null; } return $o; }
function lab_rsi2(array $c) { $o = []; $ag = $al = null; $prev = null; foreach ($c as $i => $v) { $o[$i] = null; if ($v === null) continue; if ($prev !== null) { $ch = $v - $prev; $g = max(0, $ch); $lo = max(0, -$ch); $ag = $ag === null ? $g : ($ag + $g) / 2; $al = $al === null ? $lo : ($al + $lo) / 2; $o[$i] = $al == 0 ? 100 : 100 - 100 / (1 + $ag / $al); } $prev = $v; } return $o; }
function lab_atr(array $p) { $o = []; $a = null; for ($i = 0; $i < count($p['c']); $i++) { $o[$i] = null; if ($p['h'][$i] === null || $i === 0 || $p['c'][$i - 1] === null) continue; $tr = max($p['h'][$i] - $p['l'][$i], abs($p['h'][$i] - $p['c'][$i - 1]), abs($p['l'][$i] - $p['c'][$i - 1])); $a = $a === null ? $tr : ($a * 13 + $tr) / 14; $o[$i] = $a; } return $o; }
foreach ($P as $s => &$p) { $p['sma5'] = lab_sma($p['c'], 5); $p['sma50'] = lab_sma($p['c'], 50); $p['sma200'] = lab_sma($p['c'], 200); $p['rsi2'] = lab_rsi2($p['c']); $p['atr'] = lab_atr($p); } unset($p);

/* ---------- strategies: each returns signals [day k (act at next open), sym, score, exit rule] ---------- */
/* An exit rule is a function(sym, entryK, k, entryPx) -> null (hold) or 'close' (sell at this close). A stop is checked on lows. */
$STRATS = [];
/* 1. the app's own swing rule: daily score crosses up through +0.30 (2-ATR stop, 4-ATR target, exit when the score turns negative or after 30 sessions) */
$STRATS['App swing score (live rule)'] = function () use ($P, $N, $di, $start) {
  $sig = [];
  foreach ($P as $s => $p) {
    $bt = mk_backtest_swing(mk_daily_arrays($p['C'], $N), 0.30, 0, true);
    foreach ($bt['all'] ?? [] as $t) { $k = $di[$t['date']] ?? null; $x = $di[$t['exit']] ?? null; if ($k === null || $x === null || $k - 1 < $start) continue;
      $sig[] = ['k' => $k - 1, 'sym' => $s, 'score' => $t['score'], 'fixed_exit' => $x, 'fixed_ret' => $t['ret']]; }
  }
  return $sig;
};
/* 2. 20-day breakout in an uptrend (trend following): close above the prior 20-day high, above the 200-DMA, 50 > 200.
      Exit on a close below the prior 10-day low, a 2-ATR stop, or after 40 sessions. */
$STRATS['20-day breakout in uptrend'] = function () use ($P, $start, $T) {
  $sig = [];
  foreach ($P as $s => $p) for ($k = max($start, 21); $k < $T - 1; $k++) {
    if ($p['o'][$k] === null || $p['sma200'][$k] === null || $p['sma50'][$k] === null || !$p['atr'][$k]) continue;
    $hh = null; for ($j = $k - 20; $j < $k; $j++) if ($p['h'][$j] !== null) $hh = max($hh ?? $p['h'][$j], $p['h'][$j]);
    if ($hh && $p['c'][$k] > $hh && $p['c'][$k] > $p['sma200'][$k] && $p['sma50'][$k] > $p['sma200'][$k])
      $sig[] = ['k' => $k, 'sym' => $s, 'score' => ($p['c'][$k] / $p['sma200'][$k] - 1), 'stop_atr' => 2.0, 'max_days' => 40, 'exit' => 'low10'];
  }
  return $sig;
};
/* 3. short-term pullback in an uptrend (mean reversion): above the 200-DMA and RSI(2) below 10.
      Exit on a close above the 5-DMA, a 3-ATR stop, or after 10 sessions. */
$STRATS['RSI(2) pullback in uptrend'] = function () use ($P, $start, $T) {
  $sig = [];
  foreach ($P as $s => $p) for ($k = $start; $k < $T - 1; $k++) {
    if ($p['c'][$k] === null || $p['sma200'][$k] === null || $p['rsi2'][$k] === null || !$p['atr'][$k] || $p['o'][$k] === null) continue;
    if ($p['c'][$k] > $p['sma200'][$k] && $p['rsi2'][$k] < 10) $sig[] = ['k' => $k, 'sym' => $s, 'score' => 10 - $p['rsi2'][$k], 'stop_atr' => 3.0, 'max_days' => 10, 'exit' => 'above_sma5'];
  }
  return $sig;
};
/* 4. momentum rotation: on the first session of each month, hold the strongest stocks by 12-month
      return skipping the last month, if above their 200-DMA; sell a holding when it drops out of the top 20. */
$STRATS['Monthly momentum rotation'] = function () use ($P, $dates, $start, $T) {
  $sig = [];
  for ($k = $start; $k < $T - 1; $k++) {
    if (substr($dates[$k], 0, 7) === substr($dates[$k - 1], 0, 7)) continue; // first session of a month
    $rank = [];
    foreach ($P as $s => $p) { $c = $p['c']; if ($c[$k] === null || $c[$k - 252] === null || $c[$k - 21] === null || $p['sma200'][$k] === null || $c[$k] <= $p['sma200'][$k]) continue; $rank[$s] = $c[$k - 21] / $c[$k - 252] - 1; }
    arsort($rank); $top = array_slice(array_keys($rank), 0, 20);
    foreach ($top as $r => $s) $sig[] = ['k' => $k, 'sym' => $s, 'score' => 100 - $r, 'exit' => 'rotation', 'rank_top' => $top];
  }
  return $sig;
};

/* 5. the same rotation with a market filter: hold stocks only while the Nifty is above its 200-DMA
      (checked at each month start); otherwise sell everything and sit in cash. Aims to cut the deep drawdowns. */
$nSma = lab_sma($N['c'], 200);
$STRATS['Momentum rotation + market filter'] = function () use ($P, $N, $nSma, $dates, $start, $T) {
  $sig = [];
  for ($k = $start; $k < $T - 1; $k++) {
    if (substr($dates[$k], 0, 7) === substr($dates[$k - 1], 0, 7)) continue;
    if ($nSma[$k] === null || $N['c'][$k] <= $nSma[$k]) { $sig[] = ['k' => $k, 'sym' => null, 'score' => -1, 'exit' => 'rotation', 'rank_top' => []]; continue; }
    $rank = [];
    foreach ($P as $s => $p) { $c = $p['c']; if ($c[$k] === null || $c[$k - 252] === null || $c[$k - 21] === null || $p['sma200'][$k] === null || $c[$k] <= $p['sma200'][$k]) continue; $rank[$s] = $c[$k - 21] / $c[$k - 252] - 1; }
    arsort($rank); $top = array_slice(array_keys($rank), 0, 20);
    foreach ($top as $r => $s) $sig[] = ['k' => $k, 'sym' => $s, 'score' => 100 - $r, 'exit' => 'rotation', 'rank_top' => $top];
  }
  return $sig;
};

/* ---------- account simulation ---------- */
function lab_run(array $sig, array $P, $T, $start, $slots, array $dates) {
  $by = []; foreach ($sig as $x) $by[$x['k']][] = $x;
  foreach ($by as &$l) usort($l, function ($a, $b) { return $b['score'] <=> $a['score']; }); unset($l);
  $cash = 10000.0; $pos = []; $eq = []; $trades = []; $monthTop = null;
  for ($k = $start; $k < $T; $k++) {
    /* exits (decided on this bar) */
    foreach ($pos as $id => $q) {
      $p = $P[$q['sym']]; $x = null;
      if (isset($q['fixed_exit'])) { if ($k >= $q['fixed_exit']) $x = $q['e'] * (1 + $q['fixed_ret'] / 100); }
      else {
        if ($p['l'][$k] !== null && isset($q['stop']) && $p['l'][$k] <= $q['stop']) $x = min($p['o'][$k] ?? $q['stop'], $q['stop']);
        elseif ($q['exit'] === 'low10' && $p['c'][$k] !== null) { $ll = null; for ($j = $k - 10; $j < $k; $j++) if ($p['l'][$j] !== null) $ll = min($ll ?? $p['l'][$j], $p['l'][$j]); if ($ll && $p['c'][$k] < $ll) $x = $p['c'][$k]; }
        elseif ($q['exit'] === 'above_sma5' && $p['c'][$k] !== null && $p['sma5'][$k] !== null && $p['c'][$k] > $p['sma5'][$k]) $x = $p['c'][$k];
        if ($x === null && isset($q['max_days']) && $k - $q['k'] >= $q['max_days'] && $p['c'][$k] !== null) $x = $p['c'][$k];
        /* rotation: yesterday's month-start ranking decides, sell at today's open (no look-ahead) */
        if ($x === null && $q['exit'] === 'rotation' && isset($by[$k - 1]) && $by[$k - 1][0]['exit'] === 'rotation' && !in_array($q['sym'], $by[$k - 1][0]['rank_top'], true) && $p['o'][$k] !== null) $x = $p['o'][$k];
      }
      if ($x !== null) { $v = $q['n'] * $x; $cash += $v - lab_cost_rs($q['n'] * $q['e']) ; $trades[] = ['ret' => ($x / $q['e'] - 1) * 100 - lab_cost_rs($q['n'] * $q['e']) / ($q['n'] * $q['e']) * 100, 'k' => $q['k']]; unset($pos[$id]); }
    }
    /* entries at this bar's open for signals from the previous bar */
    $mtm = $cash; foreach ($pos as $q) $mtm += $q['n'] * ($P[$q['sym']]['c'][$k - 1] ?? $q['e']);
    foreach ($by[$k - 1] ?? [] as $x) {
      if (count($pos) >= $slots) break;
      if ($x['sym'] === null) continue; // "go to cash" marker
      foreach ($pos as $q) if ($q['sym'] === $x['sym']) continue 2;
      $p = $P[$x['sym']]; $e = $p['o'][$k]; if (!$e) continue;
      $budget = min($cash, $mtm / $slots); $n = floor($budget / $e); if ($n < 1 || $n * $e < 1000) continue;
      $q = ['sym' => $x['sym'], 'k' => $k, 'e' => $e, 'n' => $n] + $x;
      if (!empty($x['stop_atr']) && $p['atr'][$k - 1]) $q['stop'] = $e - $x['stop_atr'] * $p['atr'][$k - 1];
      if (isset($x['fixed_exit'])) $q['fixed_exit'] = $x['fixed_exit'];
      $cash -= $n * $e; $pos[] = $q;
    }
    $v = $cash; foreach ($pos as $q) $v += $q['n'] * ($P[$q['sym']]['c'][$k] ?? $q['e']); $eq[$k] = $v;
  }
  return ['eq' => $eq, 'trades' => $trades];
}
function lab_curve_stats(array $eq, $a, $b, array $dates) { // CAGR and max drawdown between calendar indices a..b
  $v0 = $eq[$a]; $v1 = $eq[$b]; $yrs = max(0.1, (strtotime($dates[$b]) - strtotime($dates[$a])) / (365.25 * 86400));
  $pk = $v0; $dd = 0.0; for ($k = $a; $k <= $b; $k++) { $pk = max($pk, $eq[$k]); $dd = min($dd, $eq[$k] / $pk - 1); }
  return ['cagr_pct' => round((pow($v1 / $v0, 1 / $yrs) - 1) * 100, 1), 'max_dd_pct' => round($dd * 100, 1), 'growth_pct' => round(($v1 / $v0 - 1) * 100, 1)];
}

/* baselines */
$nEq = []; for ($k = $start; $k < $T; $k++) $nEq[$k] = 10000 * $N['c'][$k] / $N['c'][$start];
$live = array_filter(array_keys($P), function ($s) use ($P, $start) { return $P[$s]['c'][$start] !== null; });
$ewEq = []; for ($k = $start; $k < $T; $k++) { $v = 0.0; foreach ($live as $s) $v += $P[$s]['c'][$k] / $P[$s]['c'][$start]; $ewEq[$k] = 10000 * $v / count($live); }
$B = ['Nifty 50 buy & hold' => $nEq, 'Equal-weight buy & hold of the same stocks' => $ewEq];
foreach ($B as $name => $e) echo json_encode(['strategy' => $name, 'baseline' => true, 'train' => lab_curve_stats($e, $start, $split, $dates), 'holdout' => lab_curve_stats($e, $split, $T - 1, $dates), 'from' => $dates[$start], 'holdout_from' => $dates[$split], 'to' => $dates[$T - 1]]), "\n";

foreach ($STRATS as $name => $fn) {
  $sig = $fn();
  foreach ([2, 4, 6] as $slots) {
    $R = lab_run($sig, $P, $T, $start, $slots, $dates);
    $tr = function ($a, $b) use ($R) { $t = array_values(array_filter($R['trades'], function ($x) use ($a, $b) { return $x['k'] >= $a && $x['k'] < $b; })); $n = count($t);
      return ['trades' => $n, 'win_pct' => $n ? round(count(array_filter($t, function ($x) { return $x['ret'] > 0; })) / $n * 100, 1) : null, 'avg_net_ret_pct' => $n ? round(array_sum(array_column($t, 'ret')) / $n, 2) : null]; };
    echo json_encode(['strategy' => $name, 'slots' => $slots, 'signals' => count($sig),
      'train' => lab_curve_stats($R['eq'], $start, $split, $dates) + $tr($start, $split), 'holdout' => lab_curve_stats($R['eq'], $split, $T - 1, $dates) + $tr($split, $T),
      'end_value' => round(end($R['eq']))]), "\n";
  }
}
