<?php
/* ETF swing lab: short-term dip-buying on index ETFs, where charges are far lower than on shares
   (no STT on ETF buys, 0.001% on sales, vs 0.1% each way on shares).
     php tools/etf-swing.php [years=15]
   For each ETF (Nifty 50, Nifty Bank, Midcap 150, Next 50, gold, Nasdaq 100) and each textbook rule,
   a Rs 10,000 account buys at the NEXT day's open after a signal and sells at the next open after the
   exit signal (no hindsight), paying ETF charges, the Rs 15.93 DP charge per sale and 0.05% spread each
   way. Idle cash earns 6% a year. Settings are the published ones, fixed in advance. Last third = hold-out.
   Prints one JSON line per ETF x rule, plus buy-and-hold of each ETF. */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../market.php';
require_once __DIR__ . '/_fetch.php';
ini_set('memory_limit', '1024M'); set_time_limit(0);

$years = max(5, (int) ($argv[1] ?? 15));
$today = date('Y-m-d', time() + 19800);
$d = function ($n) use ($today) { return date('Y-m-d', strtotime("$today -$n days")); };
define('ES_CASH', 0.06);

$M = mkt_upstox_master();
$norm = function ($s) { return preg_replace('/[^a-z0-9]/', '', strtolower((string) $s)); };
$idx = function (array $names) use ($M, $norm) { foreach ($M['idx'] as $n => $k) foreach ($names as $want) if ($norm($n) === $want) return $k; return null; };
$ASSETS = [ // er = yearly fund cost taken off an index (an ETF price already has it)
  'Nifty 50'         => ['key' => 'NSE_INDEX|Nifty 50', 'er' => 0.0005],
  'Nifty Bank'       => ['key' => 'NSE_INDEX|Nifty Bank', 'er' => 0.002],
  'Nifty Midcap 150' => ['key' => $idx(['niftymidcap150']), 'er' => 0.002],
  'Nifty Next 50'    => ['key' => $idx(['niftynext50']), 'er' => 0.002],
  'Gold (GOLDBEES)'  => ['key' => $M['eq']['GOLDBEES'][0] ?? null, 'er' => 0],
  'Nasdaq 100 (MON100)' => ['key' => $M['eq']['MON100'][0] ?? null, 'er' => 0],
];
$want = [];
foreach ($ASSETS as $a => $s) if ($s['key']) for ($y = 0; $y <= $years; $y++)
  $want["$a|$y"] = ['url' => 'https://api.upstox.com/v2/historical-candle/' . rawurlencode($s['key']) . '/day/' . $d($y * 365) . '/' . $d($y * 365 + 364), 'headers' => ['Accept: application/json']];
$res = study_fetch($want);

function es_sma(array $c, $i, $n) { if ($i < $n - 1) return null; return array_sum(array_slice($c, $i - $n + 1, $n)) / $n; }
function es_rsi2(array $c) { $o = []; $ag = $al = null; foreach ($c as $i => $v) { $o[$i] = null; if (!$i) continue; $ch = $v - $c[$i - 1]; $g = max(0, $ch); $l = max(0, -$ch);
  $ag = $ag === null ? $g : ($ag + $g) / 2; $al = $al === null ? $l : ($al + $l) / 2; $o[$i] = $al == 0 ? 100 : 100 - 100 / (1 + $ag / $al); } return $o; }
function es_buy_cost($v) { return $v * (0.00015 + 0.0000307 * 1.18 + 0.0005); }
function es_sell_cost($v) { return $v * (0.00001 + 0.0000307 * 1.18 + 0.0005) + 15.93; }

/* rules: entry(i) on day i's close -> buy at the open of i+1; exit(i, entryIndex) on day i's close -> sell at the open of i+1 */
$RULES = [
  'RSI(2) below 10, above 200-DMA; out when close > 5-DMA (max 10 days)' => [
    'in' => function ($X, $i) { return $X['up'][$i] && $X['rsi'][$i] !== null && $X['rsi'][$i] < 10; },
    'out' => function ($X, $i, $e) { return $X['c'][$i] > es_sma($X['c'], $i, 5) || $i - $e >= 10; }],
  '3 lower closes in a row, above 200-DMA; out when close > 5-DMA (max 10 days)' => [
    'in' => function ($X, $i) { $c = $X['c']; return $X['up'][$i] && $i >= 3 && $c[$i] < $c[$i - 1] && $c[$i - 1] < $c[$i - 2] && $c[$i - 2] < $c[$i - 3]; },
    'out' => function ($X, $i, $e) { return $X['c'][$i] > es_sma($X['c'], $i, 5) || $i - $e >= 10; }],
  'Double 7s: close at a 7-day low above 200-DMA; out at a 7-day high close' => [
    'in' => function ($X, $i) { return $X['up'][$i] && $i >= 7 && $X['c'][$i] <= min(array_slice($X['c'], $i - 6, 7)); },
    'out' => function ($X, $i, $e) { return $X['c'][$i] >= max(array_slice($X['c'], $i - 6, 7)) || $i - $e >= 20; }],
  'Fall of 3%+ in 5 days, above 200-DMA; out after 5 days' => [
    'in' => function ($X, $i) { return $X['up'][$i] && $i >= 5 && $X['c'][$i] / $X['c'][$i - 5] - 1 <= -0.03; },
    'out' => function ($X, $i, $e) { return $i - $e >= 5; }],
  'Close in the bottom 20% of the day\'s range (IBS < 0.2), above 200-DMA; out next day' => [
    'in' => function ($X, $i) { $r = $X['h'][$i] - $X['l'][$i]; return $X['up'][$i] && $r > 0 && ($X['c'][$i] - $X['l'][$i]) / $r < 0.2; },
    'out' => function ($X, $i, $e) { return $i - $e >= 1; }],
];

function es_run(array $X, $in, $out, $er, $start, $n) {
  $cash = 10000.0; $u = 0.0; $e = null; $eq = []; $tr = []; $pend = null; $entryV = 0;
  for ($i = $start; $i < $n; $i++) {
    $cash *= 1 + ES_CASH / 252; if ($u) $u *= 1 - $er / 252;
    if ($pend === 'buy' && !$u) { $v = $cash / (1 + 0.0007); $c = es_buy_cost($v); $u = $v / $X['o'][$i]; $cash -= $v + $c; $e = $i; $entryV = $v + $c; }
    elseif ($pend === 'sell' && $u) { $v = $u * $X['o'][$i]; $c = es_sell_cost($v); $cash += $v - $c; $tr[] = ['k' => $e, 'ret' => ($v - $c) / $entryV - 1]; $u = 0; $e = null; }
    $pend = null;
    if ($u && $out($X, $i, $e)) $pend = 'sell'; elseif (!$u && $in($X, $i)) $pend = 'buy';
    $eq[$i] = $cash + $u * $X['c'][$i];
  }
  return ['eq' => $eq, 'trades' => $tr];
}
function es_stats(array $eq, $a, $b, array $dates) {
  $v0 = $eq[$a]; $v1 = $eq[$b]; $yrs = max(0.1, (strtotime($dates[$b]) - strtotime($dates[$a])) / (365.25 * 86400));
  $pk = $v0; $dd = 0.0; $r = [];
  for ($k = $a; $k <= $b; $k++) { $pk = max($pk, $eq[$k]); $dd = min($dd, $eq[$k] / $pk - 1); if ($k > $a) $r[] = log($eq[$k] / $eq[$k - 1]); }
  $m = array_sum($r) / max(1, count($r)); $v = 0.0; foreach ($r as $x) $v += ($x - $m) ** 2; $vol = sqrt($v / max(1, count($r)) * 252); $cagr = pow($v1 / $v0, 1 / $yrs) - 1;
  return ['cagr_pct' => round($cagr * 100, 1), 'max_dd_pct' => round($dd * 100, 1), 'return_per_risk' => $vol > 0 ? round(($cagr - ES_CASH) / $vol, 2) : null];
}
function es_tr(array $T, $a, $b) { $t = array_values(array_filter($T, function ($x) use ($a, $b) { return $x['k'] >= $a && $x['k'] < $b; })); $n = count($t);
  $g = array_sum(array_filter(array_column($t, 'ret'), function ($x) { return $x > 0; })); $l = -array_sum(array_filter(array_column($t, 'ret'), function ($x) { return $x < 0; }));
  return ['trades' => $n, 'win_pct' => $n ? round(count(array_filter($t, function ($x) { return $x['ret'] > 0; })) / $n * 100, 1) : null,
    'avg_net_pct' => $n ? round(array_sum(array_column($t, 'ret')) / $n * 100, 2) : null, 'pf' => $l > 0 ? round($g / $l, 2) : null]; }

$GRID = ($argv[2] ?? '') === 'grid';
if ($GRID) { /* robustness grid for the dip rule: fall size x look-back x holding days x trend filter */
  $RULES = [];
  foreach ([0.02, 0.03, 0.04, 0.05] as $f) foreach ([3, 5, 10] as $lb) foreach ([3, 5, 10] as $hd) foreach ([true, false] as $flt)
    $RULES[sprintf('dip %d%% in %d days, hold %d days%s', $f * 100, $lb, $hd, $flt ? ', above 200-DMA' : '')] = [
      'in' => function ($X, $i) use ($f, $lb, $flt) { return (!$flt || $X['up'][$i]) && $i >= $lb && $X['c'][$i] / $X['c'][$i - $lb] - 1 <= -$f; },
      'out' => function ($X, $i, $e) use ($hd) { return $i - $e >= $hd; }, 'grid' => [$f, $lb, $hd, $flt]];
  unset($ASSETS['Gold (GOLDBEES)'], $ASSETS['Nasdaq 100 (MON100)']); // the rule is about Indian equity indices
}
$POOL = [];
foreach ($ASSETS as $a => $s) {
  $C = null;
  for ($y = 0; $y <= $years; $y++) { $x = $res["$a|$y"] ?? null; if ($x && $x['code'] === 200) { $Q = mkt_upstox_parse($x['body']); if ($Q && count($Q['c'])) { $Q = mk_clean($Q); $C = $C ? mkt_candles_merge($Q, $C) : $Q; } } }
  if (!$C || count($C['c']) < 700) { echo json_encode(['asset' => $a, 'error' => 'not enough data', 'bars' => $C ? count($C['c']) : 0]), "\n"; continue; }
  $n = count($C['c']); $dates = array_map('mk_ist_date', $C['t']);
  $X = ['o' => $C['o'], 'h' => $C['h'], 'l' => $C['l'], 'c' => $C['c'], 'rsi' => es_rsi2($C['c']), 'up' => []];
  for ($i = 0; $i < $n; $i++) { $m = es_sma($C['c'], $i, 200); $X['up'][$i] = $m !== null && $C['c'][$i] > $m; }
  $start = 210; $split = $start + (int) (2 * ($n - $start) / 3);
  $bh = []; for ($i = $start; $i < $n; $i++) $bh[$i] = 10000 * $C['c'][$i] / $C['c'][$start] * pow(1 - $s['er'], ($i - $start) / 252);
  $B = ['train' => es_stats($bh, $start, $split, $dates), 'holdout' => es_stats($bh, $split, $n - 1, $dates)];
  echo json_encode(['asset' => $a, 'rule' => 'buy & hold', 'baseline' => true, 'train' => $B['train'], 'holdout' => $B['holdout'], 'from' => $dates[$start], 'holdout_from' => $dates[$split], 'to' => $dates[$n - 1]]), "\n";
  foreach ($RULES as $name => $R) {
    $run = es_run($X, $R['in'], $R['out'], $s['er'], $start, $n);
    $tr = es_stats($run['eq'], $start, $split, $dates) + es_tr($run['trades'], $start, $split);
    $ho = es_stats($run['eq'], $split, $n - 1, $dates) + es_tr($run['trades'], $split, $n);
    /* pass: profitable after charges in both periods on enough trades, and better return per unit of risk than holding the ETF in both */
    $pass = $tr['trades'] >= 20 && $ho['trades'] >= 10 && $tr['avg_net_pct'] > 0 && $ho['avg_net_pct'] > 0 && ($tr['pf'] ?? 0) >= 1.2 && ($ho['pf'] ?? 0) >= 1.1
      && $tr['return_per_risk'] > $B['train']['return_per_risk'] && $ho['return_per_risk'] > $B['holdout']['return_per_risk'];
    if ($GRID) { foreach ($run['trades'] as $t) $POOL[$name][$t['k'] < $split ? 'train' : 'holdout'][] = $t; continue; }
    echo json_encode(['asset' => $a, 'rule' => $name, 'train' => $tr, 'holdout' => $ho, 'passed' => $pass]), "\n";
  }
}

if ($GRID) { /* pooled over the four index ETFs: every setting's trades, tuning years vs hold-out */
  $ok = 0; $rows = [];
  foreach ($POOL as $name => $P) {
    $st = function ($T) { $n = count($T); $r = array_column($T, 'ret'); $g = array_sum(array_filter($r, function ($x) { return $x > 0; })); $l = -array_sum(array_filter($r, function ($x) { return $x < 0; }));
      return ['trades' => $n, 'avg_net_pct' => $n ? round(array_sum($r) / $n * 100, 2) : null, 'win_pct' => $n ? round(count(array_filter($r, function ($x) { return $x > 0; })) / $n * 100, 1) : null, 'pf' => $l > 0 ? round($g / $l, 2) : null]; };
    $a = $st($P['train'] ?? []); $b = $st($P['holdout'] ?? []);
    $good = $a['trades'] >= 30 && $b['trades'] >= 15 && $a['avg_net_pct'] > 0 && $b['avg_net_pct'] > 0; if ($good) $ok++;
    $rows[] = ['setting' => $name, 'tuning' => $a, 'holdout' => $b, 'profitable_both' => $good];
  }
  foreach ($rows as $r) echo json_encode($r), "\n";
  echo json_encode(['summary' => "$ok of " . count($rows) . ' settings made money after charges in both periods (pooled over Nifty, Bank, Midcap 150, Next 50)']), "\n";
}
