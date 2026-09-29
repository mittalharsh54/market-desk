<?php
/* Professional playbook lab: what business schools, financial advisors, economists and
   bankers recommend, tested the same honest way as everything else, on real NSE data.
     php tools/pro-lab.php [years=10]
   Portfolios of index funds / ETFs — Nifty 50, Nifty Midcap 150, Nasdaq 100 (MON100),
   gold (GOLDBEES) and NSE's factor indices for momentum, low volatility, quality and
   value — rebalanced at each month start with a 5-point drift band, inside a Rs 10,000
   account: delivery charges on every trade, the Rs 15.93 DP charge on every sale, fund
   expense ratios, idle cash / the debt sleeve at 6% a year. Every setting is the textbook
   one, fixed in advance; nothing is tuned. The last third of the period is a hold-out.
   Indices are price indices (no dividends) for the strategies and the Nifty baseline alike.
   Prints one JSON line per strategy. Run it from GitHub Actions (needs Upstox). */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../market.php';
require_once __DIR__ . '/_fetch.php';
ini_set('memory_limit', '1024M'); set_time_limit(0);

$years = max(5, (int) ($argv[1] ?? 10));
$today = date('Y-m-d', time() + 19800);
$d = function ($n) use ($today) { return date('Y-m-d', strtotime("$today -$n days")); };
define('PL_CASH', 0.06);   // liquid fund / short-term debt, a year
define('PL_BAND', 0.05);   // rebalance a sleeve only when it drifts 5 points from target (or the signal flips)

/* ---------- instruments ---------- */
$norm = function ($s) { return preg_replace('/[^a-z0-9]/', '', strtolower((string) $s)); };
$M = getenv('PL_SYNTH') ? ['idx' => [], 'eq' => []] : mkt_upstox_master();
$IDX = []; foreach ($M['idx'] as $name => $key) $IDX[$norm($name)] = [$key, $name];
/* NSE index names are abbreviated differently in different lists ("NIFTY200 MOMENTM 30"), so match on fragments */
function pl_find_index(array $IDX, array $need, array $not = []) {
  $best = null;
  foreach ($IDX as $n => $v) {
    foreach ($need as $t) if (strpos($n, $t) === false) continue 2;
    foreach ($not as $t) if (strpos($n, $t) !== false) continue 2;
    if ($best === null || strlen($n) < strlen($best[2])) $best = [$v[0], $v[1], $n];
  }
  return $best;
}
$SPEC = [ // er = yearly fund cost deducted from an index (an ETF's own price already has it)
  'NIFTY'  => ['label' => 'Nifty 50 (index fund)', 'key' => 'NSE_INDEX|Nifty 50', 'er' => 0.0005],
  'MID'    => ['label' => 'Nifty Midcap 150', 'find' => [['midcap150'], ['mom', 'qual', 'value', 'lowvol', 'alpha', 'tr']], 'er' => 0.002],
  'MOM'    => ['label' => 'Nifty200 Momentum 30', 'find' => [['200', 'mom', '30'], ['tr']], 'er' => 0.003],
  'LOWVOL' => ['label' => 'Nifty100 Low Volatility 30', 'find' => [['100', 'lowvol', '30'], ['tr']], 'er' => 0.003],
  'QUAL'   => ['label' => 'Nifty200 Quality 30', 'find' => [['200', 'qual', '30'], ['tr']], 'er' => 0.003],
  'VALUE'  => ['label' => 'Nifty50 Value 20', 'find' => [['50', 'value', '20'], ['tr']], 'er' => 0.003],
  'ALPLV'  => ['label' => 'Nifty Alpha Low-Volatility 30', 'find' => [['alpha', 'lowvol', '30'], ['tr', 'quality']], 'er' => 0.004],
  'NASDAQ' => ['label' => 'Nasdaq 100 ETF (MON100)', 'eq' => 'MON100', 'er' => 0],
  'GOLD'   => ['label' => 'Gold ETF (GOLDBEES)', 'eq' => 'GOLDBEES', 'er' => 0],
];
$keys = []; $found = [];
foreach ($SPEC as $a => $s) {
  $k = null; $nm = null;
  if (isset($s['key'])) { $k = $s['key']; $nm = $s['label']; }
  elseif (isset($s['eq'])) { $e = $M['eq'][$s['eq']] ?? null; if ($e) { $k = $e[0]; $nm = $s['eq']; } }
  else { $f = pl_find_index($IDX, $s['find'][0], $s['find'][1]); if ($f) { $k = $f[0]; $nm = $f[1]; } }
  if ($k) { $keys[$a] = $k; $found[$a] = $nm; }
}
fwrite(STDERR, 'instruments: ' . json_encode($found) . "\n");
$want = [];
foreach ($keys as $a => $k) for ($y = 0; $y <= $years; $y++)
  $want["$a|$y"] = ['url' => 'https://api.upstox.com/v2/historical-candle/' . rawurlencode($k) . '/day/' . $d($y * 365) . '/' . $d($y * 365 + 364), 'headers' => ['Accept: application/json']];
$res = getenv('PL_SYNTH') ? [] : study_fetch($want);
$D = [];
foreach ($keys as $a => $k) {
  $C = null;
  for ($y = 0; $y <= $years; $y++) { $x = $res["$a|$y"] ?? null; if ($x && $x['code'] === 200) { $Q = mkt_upstox_parse($x['body']); if ($Q && count($Q['c'])) { $Q = mk_clean($Q); $C = $C ? mkt_candles_merge($Q, $C) : $Q; } } }
  if ($C && count($C['c']) > 300) $D[$a] = $C;
}
if (getenv('PL_SYNTH')) { // offline self-check with random walks
  mt_srand(11); foreach (array_keys($SPEC) as $j => $a) { $C = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []]; $px = 1000;
    for ($i = 0; $i < 2500; $i++) { $t = gmmktime(10, 0, 0, 1, 1 + $i + 2 * intdiv($i, 5), 2016); $px *= 1 + 0.0004 + 0.011 * (mt_rand() / mt_getrandmax() * 2 - 1);
      foreach (['o', 'h', 'l', 'c'] as $f) $C[$f][] = $px; $C['t'][] = $t; $C['v'][] = 1; }
    $D[$a] = $C; $found[$a] = $SPEC[$a]['label']; }
}
if (empty($D['NIFTY'])) { fwrite(STDERR, "no Nifty data\n"); exit(1); }
$missing = array_values(array_diff(array_keys($SPEC), array_keys($D)));
if ($missing) fwrite(STDERR, 'no data for: ' . implode(', ', $missing) . "\n");

/* ---------- one calendar (Nifty sessions); each asset's close carried over its own gaps ---------- */
$N = $D['NIFTY']; $dates = array_map('mk_ist_date', $N['t']); $di = array_flip($dates); $T = count($dates);
$A = []; $first = [];
foreach ($D as $a => $C) {
  $c = array_fill(0, $T, null);
  foreach ($C['t'] as $i => $t) { $k = $di[mk_ist_date($t)] ?? null; if ($k !== null) $c[$k] = $C['c'][$i]; }
  for ($k = 1; $k < $T; $k++) if ($c[$k] === null && $c[$k - 1] !== null) $c[$k] = $c[$k - 1];
  $A[$a] = ['c' => $c, 'er' => $SPEC[$a]['er']];
  foreach ($c as $k => $v) if ($v !== null) { $first[$a] = $dates[$k]; break; }
}
$start = 260; if ($T - $start < 500) { fwrite(STDERR, "not enough history\n"); exit(1); }
$split = $start + (int) (2 * ($T - $start) / 3);
fwrite(STDERR, count($A) . " assets, " . $dates[$start] . " → " . $dates[$T - 1] . ", hold-out from " . $dates[$split] . "\n");

/* ---------- helpers (all look at closes up to day k only) ---------- */
$has = function ($a, $k, $back = 252) use ($A) { return isset($A[$a]) && $k - $back >= 0 && $A[$a]['c'][$k] !== null && $A[$a]['c'][$k - $back] !== null; };
$ret = function ($a, $k, $n) use ($A) { return $A[$a]['c'][$k] / $A[$a]['c'][$k - $n] - 1; };
$sma = function ($a, $k, $n = 200) use ($A) { $s = 0.0; for ($j = $k - $n + 1; $j <= $k; $j++) { if ($A[$a]['c'][$j] === null) return null; $s += $A[$a]['c'][$j]; } return $s / $n; };
$up = function ($a, $k) use ($has, $sma, $A) { if (!$has($a, $k, 200)) return false; $m = $sma($a, $k); return $m !== null && $A[$a]['c'][$k] > $m; }; // above its ~10-month average
$vol = function ($a, $k, $n) use ($A) { $r = []; for ($j = $k - $n + 1; $j <= $k; $j++) if ($A[$a]['c'][$j] !== null && $A[$a]['c'][$j - 1]) $r[] = log($A[$a]['c'][$j] / $A[$a]['c'][$j - 1]);
  if (count($r) < $n * 0.8) return null; $m = array_sum($r) / count($r); $v = 0.0; foreach ($r as $x) $v += ($x - $m) ** 2; return sqrt($v / count($r) * 252); };
$live = function (array $w, $k) use ($has) { $o = []; foreach ($w as $a => $x) if ($x > 0 && $has($a, $k, 1)) $o[$a] = $x; return $o; }; // drop assets without a price yet

/* ---------- the playbooks: each returns target weights for day k (the rest sits in debt / cash) ---------- */
$S = [];
$S['Advisor classic: 60% Nifty / 20% gold / 20% debt'] = ['who' => 'financial advisors (strategic asset allocation, rebalancing)',
  'fn' => function ($k) use ($live) { return $live(['NIFTY' => 0.6, 'GOLD' => 0.2], $k); }];
$S['Advisor growth: 50% Nifty / 20% midcap / 10% Nasdaq / 10% gold / 10% debt'] = ['who' => 'wealth managers (core-satellite, global diversification)',
  'fn' => function ($k) use ($live) { return $live(['NIFTY' => 0.5, 'MID' => 0.2, 'NASDAQ' => 0.1, 'GOLD' => 0.1], $k); }];
$S['Trend following: Nifty, midcap, Nasdaq, gold, each 25% only above its 10-month average'] = ['who' => 'Faber (2007) / Moskowitz-Ooi-Pedersen time-series momentum',
  'fn' => function ($k) use ($up) { $w = []; foreach (['NIFTY', 'MID', 'NASDAQ', 'GOLD'] as $a) if ($up($a, $k)) $w[$a] = 0.25; return $w; }];
$S['Dual momentum: best of Nifty / midcap / Nasdaq by 12-month return, else gold, else debt'] = ['who' => 'Antonacci dual momentum (relative + absolute)',
  'fn' => function ($k) use ($has, $ret) { $best = null; $br = null;
    foreach (['NIFTY', 'MID', 'NASDAQ'] as $a) if ($has($a, $k)) { $r = $ret($a, $k, 252); if ($br === null || $r > $br) { $best = $a; $br = $r; } }
    if ($best && $br > PL_CASH) return [$best => 1.0];
    if ($has('GOLD', $k) && $ret('GOLD', $k, 252) > PL_CASH) return ['GOLD' => 1.0];
    return []; }];
$S['Multi-factor: momentum + low-vol + quality + value, 25% each'] = ['who' => 'business-school factor research (Fama-French, Carhart, Novy-Marx, AQR)',
  'fn' => function ($k) use ($live) { return $live(['MOM' => 0.25, 'LOWVOL' => 0.25, 'QUAL' => 0.25, 'VALUE' => 0.25], $k); }];
$S['Multi-factor + trend: each factor 25% only above its 10-month average'] = ['who' => 'factor research + trend following',
  'fn' => function ($k) use ($up) { $w = []; foreach (['MOM', 'LOWVOL', 'QUAL', 'VALUE'] as $a) if ($up($a, $k)) $w[$a] = 0.25; return $w; }];
$S['Factor momentum: the 2 strongest factors over 6 months, if above their 10-month average'] = ['who' => 'factor momentum (Gupta-Kelly 2019, Arnott et al.)',
  'fn' => function ($k) use ($has, $ret, $up) { $r = []; foreach (['MOM', 'LOWVOL', 'QUAL', 'VALUE'] as $a) if ($has($a, $k, 126)) $r[$a] = $ret($a, $k, 126);
    arsort($r); $w = []; foreach (array_slice(array_keys($r), 0, 2) as $a) if ($up($a, $k)) $w[$a] = 0.5; return $w; }];
$S['Risk parity: Nifty, midcap, Nasdaq, gold weighted by 1 / volatility'] = ['who' => 'Dalio all-weather / bank risk budgeting',
  'fn' => function ($k) use ($vol, $has) { $iv = []; foreach (['NIFTY', 'MID', 'NASDAQ', 'GOLD'] as $a) if ($has($a, $k)) { $v = $vol($a, $k, 252); if ($v) $iv[$a] = 1 / $v; }
    $s = array_sum($iv); $w = []; foreach ($iv as $a => $x) $w[$a] = $x / $s; return $w; }];
$S['Volatility target: Nifty sized to 12% yearly volatility, rest in debt'] = ['who' => 'bank risk desks (Moreira-Muir 2017 volatility-managed portfolios)',
  'fn' => function ($k) use ($vol) { $v = $vol('NIFTY', $k, 63); return $v ? ['NIFTY' => min(1.0, 0.12 / $v)] : []; }];
$S['Momentum 30 index + market filter (Nifty above its 200-DMA)'] = ['who' => 'momentum research; the index version of our monthly picks',
  'fn' => function ($k) use ($up, $has) { return ($up('NIFTY', $k) && $has('MOM', $k, 1)) ? ['MOM' => 1.0] : []; }];
$S['Momentum 30 index + market filter, sized to 15% volatility'] = ['who' => 'Barroso & Santa-Clara (2015) risk-managed momentum',
  'fn' => function ($k) use ($up, $has, $vol) { if (!$up('NIFTY', $k) || !$has('MOM', $k, 126)) return []; $v = $vol('MOM', $k, 126); return $v ? ['MOM' => min(1.0, 0.15 / $v)] : []; }];
$S['Factor dual momentum: 70% in the strongest of Momentum / Alpha-LowVol / Quality / Nifty (12-1), 15% gold, rest debt; equity to debt when Nifty < 200-DMA'] = ['who' => 'research synthesis: dual momentum on factor ETFs + gold + trend filter',
  'fn' => function ($k) use ($up, $has, $A) { $w = $has('GOLD', $k, 1) ? ['GOLD' => 0.15] : [];
    if (!$up('NIFTY', $k)) return $w; $best = null; $br = null;
    foreach (['MOM', 'ALPLV', 'QUAL', 'NIFTY'] as $a) if ($has($a, $k)) { $r = $A[$a]['c'][$k - 21] / $A[$a]['c'][$k - 252] - 1; if ($br === null || $r > $br) { $best = $a; $br = $r; } }
    if ($best) $w[$best] = 0.7; return $w; }];
/* robustness: are the diversified portfolios only good because Nasdaq had a great decade? */
$S['Risk parity without Nasdaq: Nifty, midcap, gold by 1 / volatility'] = ['who' => 'robustness check',
  'fn' => function ($k) use ($vol, $has) { $iv = []; foreach (['NIFTY', 'MID', 'GOLD'] as $a) if ($has($a, $k)) { $v = $vol($a, $k, 252); if ($v) $iv[$a] = 1 / $v; }
    $s = array_sum($iv); $w = []; foreach ($iv as $a => $x) $w[$a] = $x / $s; return $w; }];
$S['Risk parity, Nifty + gold only'] = ['who' => 'robustness check',
  'fn' => function ($k) use ($vol, $has) { $iv = []; foreach (['NIFTY', 'GOLD'] as $a) if ($has($a, $k)) { $v = $vol($a, $k, 252); if ($v) $iv[$a] = 1 / $v; }
    $s = array_sum($iv); $w = []; foreach ($iv as $a => $x) $w[$a] = $x / $s; return $w; }];
$S['Advisor growth without Nasdaq: 60% Nifty / 20% midcap / 10% gold / 10% debt'] = ['who' => 'robustness check',
  'fn' => function ($k) use ($live) { return $live(['NIFTY' => 0.6, 'MID' => 0.2, 'GOLD' => 0.1], $k); }];
$S['Equal weight: Nifty, midcap, Nasdaq, gold 25% each'] = ['who' => 'economists (1/N diversification, DeMiguel-Garlappi-Uppal 2009) + advisors (spread and rebalance)',
  'fn' => function ($k) use ($live) { return $live(['NIFTY' => 0.25, 'MID' => 0.25, 'NASDAQ' => 0.25, 'GOLD' => 0.25], $k); }];
/* the collective: average the target weights of several independent schools of thought */
$blend = function (array $names) use (&$S) { return function ($k) use ($names, &$S) { $w = [];
  foreach ($names as $n) foreach (($S[$n]['fn'])($k) as $a => $x) $w[$a] = ($w[$a] ?? 0) + $x / count($names); return $w; }; };
$names = array_keys($S);
$S['Council (balanced): advisors + trend + dual momentum + factors + risk desk, averaged'] = ['who' => 'all of the above, equal say',
  'fn' => $blend([$names[0], $names[2], $names[3], $names[5], $names[8]])];
$S['Council (growth): trend + dual momentum + factors + factor momentum + momentum 30, averaged'] = ['who' => 'the return-seeking schools, equal say',
  'fn' => $blend([$names[2], $names[3], $names[5], $names[6], $names[9]])];
$S['Council (all 12 playbooks averaged)'] = ['who' => 'every school above, equal say', 'fn' => $blend(array_slice($names, 0, 12))];

/* ---------- account simulation ---------- */
/* ETF delivery charges: no STT on buys and 0.001% on equity-ETF sales (0.1% each way on shares), stamp duty 0.015% on buys,
   exchange + SEBI fees with GST, the Rs 15.93 DP charge per sale, and 0.05% each way for the bid-ask spread / premium to NAV */
function pl_buy_cost($v) { return $v * (0.00015 + (0.0000297 + 0.000001) * 1.18 + 0.0005); }
function pl_sell_cost($v) { return $v * (0.00001 + (0.0000297 + 0.000001) * 1.18 + 0.0005) + 15.93; }
function pl_run(callable $wfn, array $A, array $dates, $start, $T) {
  $cash = 10000.0; $u = []; $eq = []; $n = 0; $costs = 0.0; $switches = 0;
  for ($k = $start; $k < $T; $k++) {
    $cash *= 1 + PL_CASH / 252;
    foreach ($u as $a => $q) $u[$a] = $q * (1 - $A[$a]['er'] / 252);
    if ($k === $start || substr($dates[$k], 0, 7) !== substr($dates[$k - 1], 0, 7)) {
      $w = $wfn($k - 1);                       // decided on yesterday's close, traded at today's close
      $val = $cash; $hv = [];
      foreach ($u as $a => $q) { $hv[$a] = $q * $A[$a]['c'][$k]; $val += $hv[$a]; }
      $ord = [];
      foreach (array_unique(array_merge(array_keys($w), array_keys($u))) as $a) {
        if ($A[$a]['c'][$k] === null) continue;
        $tw = $w[$a] ?? 0.0; $cw = ($hv[$a] ?? 0.0) / $val;
        $flip = ($tw > 0.001) !== ($cw > 0.001);
        if (!$flip && abs($tw - $cw) < PL_BAND) continue;
        $ord[$a] = ['dv' => $tw * $val - ($hv[$a] ?? 0.0), 'exit' => $tw <= 0.001];
        if ($flip) $switches++;
      }
      foreach ($ord as $a => $o) if ($o['dv'] < 0) {
        $px = $A[$a]['c'][$k]; $amt = $o['exit'] ? $u[$a] * $px : -$o['dv']; if (!$o['exit'] && $amt < 500) continue;
        $c = pl_sell_cost($amt); $cash += $amt - $c; $costs += $c; $n++;
        if ($o['exit']) unset($u[$a]); else $u[$a] -= $amt / $px;
      }
      foreach ($ord as $a => $o) if ($o['dv'] > 0) {
        $px = $A[$a]['c'][$k]; $amt = min($o['dv'], $cash / 1.0012); if ($amt < 500) continue;
        $c = pl_buy_cost($amt); $cash -= $amt + $c; $costs += $c; $n++; $u[$a] = ($u[$a] ?? 0) + $amt / $px;
      }
    }
    $v = $cash; foreach ($u as $a => $q) $v += $q * $A[$a]['c'][$k]; $eq[$k] = $v;
  }
  return ['eq' => $eq, 'trades' => $n, 'costs' => $costs, 'switches' => $switches, 'weights_now' => $wfn($T - 1)];
}
function pl_stats(array $eq, $a, $b, array $dates) {
  $v0 = $eq[$a]; $v1 = $eq[$b]; $yrs = max(0.1, (strtotime($dates[$b]) - strtotime($dates[$a])) / (365.25 * 86400));
  $pk = $v0; $dd = 0.0; $r = [];
  for ($k = $a; $k <= $b; $k++) { $pk = max($pk, $eq[$k]); $dd = min($dd, $eq[$k] / $pk - 1); if ($k > $a) $r[] = log($eq[$k] / $eq[$k - 1]); }
  $m = array_sum($r) / max(1, count($r)); $v = 0.0; foreach ($r as $x) $v += ($x - $m) ** 2; $vol = sqrt($v / max(1, count($r)) * 252);
  $cagr = pow($v1 / $v0, 1 / $yrs) - 1;
  return ['cagr_pct' => round($cagr * 100, 1), 'max_dd_pct' => round($dd * 100, 1), 'vol_pct' => round($vol * 100, 1),
    'return_per_risk' => $vol > 0 ? round(($cagr - PL_CASH) / $vol, 2) : null, 'growth_pct' => round(($v1 / $v0 - 1) * 100, 1)];
}

function pl_thirds(array $eq, $start, $T, array $dates) { $o = []; $n = $T - $start;
  for ($i = 0; $i < 3; $i++) { $a = $start + (int) ($i * $n / 3); $b = $start + (int) (($i + 1) * $n / 3) - 1; $o[] = substr($dates[$a], 0, 7) . '..' . substr($dates[$b], 0, 7) . ' ' . pl_stats($eq, $a, $b, $dates)['cagr_pct']; }
  return $o; }
/* ---------- baseline: Nifty 50 bought and held (no costs at all) ---------- */
$nEq = []; for ($k = $start; $k < $T; $k++) $nEq[$k] = 10000 * $A['NIFTY']['c'][$k] / $A['NIFTY']['c'][$start];
$bT = pl_stats($nEq, $start, $split, $dates); $bH = pl_stats($nEq, $split, $T - 1, $dates);
echo json_encode(['strategy' => 'Nifty 50 buy & hold', 'baseline' => true, 'train' => $bT, 'holdout' => $bH, 'thirds' => pl_thirds($nEq, $start, $T, $dates), 'from' => $dates[$start], 'holdout_from' => $dates[$split], 'to' => $dates[$T - 1],
  'instruments' => $found, 'first_dates' => $first, 'missing' => $missing]), "\n";

foreach ($S as $name => $s) {
  $R = pl_run($s['fn'], $A, $dates, $start, $T);
  $tr = pl_stats($R['eq'], $start, $split, $dates); $ho = pl_stats($R['eq'], $split, $T - 1, $dates);
  $beats = $tr['cagr_pct'] > $bT['cagr_pct'] && $ho['cagr_pct'] > $bH['cagr_pct'];
  $safer = $tr['max_dd_pct'] > $bT['max_dd_pct'] && $ho['max_dd_pct'] > $bH['max_dd_pct'] && $tr['return_per_risk'] > $bT['return_per_risk'] && $ho['return_per_risk'] > $bH['return_per_risk'];
  echo json_encode(['strategy' => $name, 'school' => $s['who'], 'train' => $tr, 'holdout' => $ho,
    'beats_nifty_both' => $beats, 'safer_than_nifty_both' => $safer, 'thirds' => pl_thirds($R['eq'], $start, $T, $dates),
    'trades' => $R['trades'], 'signal_switches' => $R['switches'], 'costs_rs' => round($R['costs']), 'end_value' => round(end($R['eq'])),
    'weights_now' => array_map(function ($x) { return round($x, 2); }, $R['weights_now'])]), "\n";
}
