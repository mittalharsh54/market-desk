<?php
/* Strategy explorer — runs around the clock (GitHub Actions, every 2 hours).
     php tools/explorer.php [seconds=900]
   Each run spends its time budget trying new strategy settings on 15 years of NSE index / ETF
   prices (Nifty, Bank, Midcap 150, Next 50, Smallcap 250, the momentum / low-vol / quality /
   value factor indices, Nasdaq 100 and gold), in a Rs 10,000 account with ETF charges and
   6% on idle cash. Five families of rules are searched: buying dips, rotating into the
   strongest assets, trend following, fixed mixes, and dip-buying on top of a fixed mix.

   Honest by construction:
   - the search is guided ONLY by the tuning years (first 60%); the last 40% is a gate it never
     learns from;
   - a setting becomes a candidate only if it beats the Core portfolio (the app's tested plan)
     in both periods;
   - trying thousands of settings makes lucky ones inevitable, so every candidate is then
     re-scored on market days that came AFTER it was found — the only test luck cannot pass
     for long. After 120 such days a candidate is marked confirmed or failed.
   Everything is kept in research/explorer.json (committed by the workflow). */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../market.php';
require_once __DIR__ . '/_fetch.php';
ini_set('memory_limit', '1024M'); set_time_limit(0);

$BUDGET = max(30, (int) ($argv[1] ?? 900)); $T0 = microtime(true);
$FILE = dirname(__DIR__) . '/research/explorer.json';
define('EX_CASH', 0.06);
define('EX_FWD_DAYS', 120);
$years = 15;
$today = date('Y-m-d', time() + 19800);
$d = function ($n) use ($today) { return date('Y-m-d', strtotime("$today -$n days")); };

/* ---------- data (same requests as the other labs, so mostly served from the cache) ---------- */
$norm = function ($s) { return preg_replace('/[^a-z0-9]/', '', strtolower((string) $s)); };
$SPEC = [
  'NIFTY' => ['key' => 'NSE_INDEX|Nifty 50', 'er' => 0.0005], 'BANK' => ['key' => 'NSE_INDEX|Nifty Bank', 'er' => 0.002],
  'MID' => ['find' => [['midcap150'], ['mom', 'qual', 'value', 'lowvol', 'alpha', 'tr']], 'er' => 0.002],
  'NEXT50' => ['find' => [['next50'], ['tr', 'value', 'lowvol', 'mom']], 'er' => 0.002],
  'SMALL' => ['find' => [['smlcap250'], ['tr', 'mom', 'qual', 'value', 'q50', 'mq']], 'er' => 0.003],
  'MOM' => ['find' => [['200', 'mom', '30'], ['tr']], 'er' => 0.003], 'LOWVOL' => ['find' => [['100', 'lowvol', '30'], ['tr']], 'er' => 0.003],
  'QUAL' => ['find' => [['200', 'qual', '30'], ['tr']], 'er' => 0.003], 'VALUE' => ['find' => [['50', 'value', '20'], ['tr']], 'er' => 0.003],
  'NASDAQ' => ['eq' => 'MON100', 'er' => 0], 'GOLD' => ['eq' => 'GOLDBEES', 'er' => 0],
];
$SYNTH = (bool) getenv('EX_SYNTH');
$M = $SYNTH ? ['idx' => [], 'eq' => []] : mkt_upstox_master();
$IDX = []; foreach ($M['idx'] as $name => $key) $IDX[$norm($name)] = $key;
$keys = [];
foreach ($SPEC as $a => $s) {
  if (isset($s['key'])) $keys[$a] = $s['key'];
  elseif (isset($s['eq'])) { if (isset($M['eq'][$s['eq']])) $keys[$a] = $M['eq'][$s['eq']][0]; }
  else { $best = null; foreach ($IDX as $n => $k) { foreach ($s['find'][0] as $t) if (strpos($n, $t) === false) continue 2; foreach ($s['find'][1] as $t) if (strpos($n, $t) !== false) continue 2; if ($best === null || strlen($n) < strlen($best[0])) $best = [$n, $k]; } if ($best) $keys[$a] = $best[1]; }
}
$D = [];
if ($SYNTH) { mt_srand(5); foreach (array_keys($SPEC) as $a) { $C = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []]; $px = 1000;
  for ($i = 0; $i < 3000; $i++) { $t = gmmktime(10, 0, 0, 1, 1 + $i + 2 * intdiv($i, 5), 2012); $o = $px; $px *= 1 + 0.0004 + 0.012 * (mt_rand() / mt_getrandmax() * 2 - 1);
    $C['t'][] = $t; $C['o'][] = $o; $C['h'][] = max($o, $px); $C['l'][] = min($o, $px); $C['c'][] = $px; $C['v'][] = 1; } $D[$a] = $C; } }
else {
  $want = []; foreach ($keys as $a => $k) for ($y = 0; $y <= $years; $y++)
    $want["$a|$y"] = ['url' => 'https://api.upstox.com/v2/historical-candle/' . rawurlencode($k) . '/day/' . $d($y * 365) . '/' . $d($y * 365 + 364), 'headers' => ['Accept: application/json']];
  $res = study_fetch($want);
  foreach ($keys as $a => $k) { $C = null;
    for ($y = 0; $y <= $years; $y++) { $x = $res["$a|$y"] ?? null; if ($x && $x['code'] === 200) { $Q = mkt_upstox_parse($x['body']); if ($Q && count($Q['c'])) { $Q = mk_clean($Q); $C = $C ? mkt_candles_merge($Q, $C) : $Q; } } }
    if ($C && count($C['c']) > 300) $D[$a] = $C; }
}
if (empty($D['NIFTY'])) { fwrite(STDERR, "no Nifty data\n"); exit(1); }
$dates = array_map('mk_ist_date', $D['NIFTY']['t']); $di = array_flip($dates); $T = count($dates);
$A = [];
foreach ($D as $a => $C) {
  $o = array_fill(0, $T, null); $c = $o;
  foreach ($C['t'] as $i => $t) { $k = $di[mk_ist_date($t)] ?? null; if ($k !== null) { $o[$k] = $C['o'][$i]; $c[$k] = $C['c'][$i]; } }
  for ($k = 1; $k < $T; $k++) { if ($c[$k] === null && $c[$k - 1] !== null) $c[$k] = $c[$k - 1]; if ($o[$k] === null) $o[$k] = $c[$k]; }
  $sma = [];
  foreach ([50, 100, 150, 200] as $n) { $s = array_fill(0, $T, null); $sum = 0.0; $cnt = 0;
    for ($k = 0; $k < $T; $k++) { if ($c[$k] === null) { $sum = 0.0; $cnt = 0; continue; } $sum += $c[$k]; $cnt++; if ($cnt > $n) { $sum -= $c[$k - $n]; $cnt = $n; } if ($cnt === $n) $s[$k] = $sum / $n; }
    $sma[$n] = $s; }
  $A[$a] = ['o' => $o, 'c' => $c, 'sma' => $sma, 'er' => $SPEC[$a]['er']];
}
$ASSETS = array_keys($A);
$start = 210; $split = $start + (int) (0.6 * ($T - $start));
fwrite(STDERR, count($A) . ' assets (' . implode(', ', $ASSETS) . '), ' . $dates[$start] . ' → ' . $dates[$T - 1] . ', gate from ' . $dates[$split] . "\n");

/* ---------- account simulation: decisions on a close, trades at the next open ---------- */
function ex_vol(array $c, $k, $n = 63) { $r = []; for ($j = $k - $n + 1; $j <= $k; $j++) if ($j > 0 && $c[$j] !== null && $c[$j - 1]) $r[] = log($c[$j] / $c[$j - 1]);
  if (count($r) < $n * 0.8) return null; $m = array_sum($r) / count($r); $v = 0.0; foreach ($r as $x) $v += ($x - $m) ** 2; return sqrt($v / count($r) * 252); }
function ex_run(array $A, array $cfg, $start, $T) {
  $dec = ex_decider($A, $cfg); $band = $cfg['p']['band'] ?? 0.0;
  $cash = 10000.0; $u = []; $eq = []; $pend = null; $st = []; $trades = 0; $costs = 0.0;
  for ($k = $start; $k < $T; $k++) {
    $cash *= 1 + EX_CASH / 252;
    foreach ($u as $a => $q) $u[$a] = $q * (1 - $A[$a]['er'] / 252);
    if ($pend !== null) {
      $val = $cash; foreach ($u as $a => $q) $val += $q * $A[$a]['o'][$k];
      $all = array_unique(array_merge(array_keys($pend), array_keys($u))); $ord = [];
      foreach ($all as $a) { $px = $A[$a]['o'][$k]; if (!$px) continue; $tw = $pend[$a] ?? 0.0; $cv = ($u[$a] ?? 0) * $px; $cw = $cv / $val;
        $flip = ($tw > 0.001) !== ($cw > 0.001); if (!$flip && abs($tw - $cw) <= $band) continue; $ord[$a] = $tw * $val - $cv; }
      foreach ($ord as $a => $dv) if ($dv < -300 || ($dv < 0 && empty($pend[$a]))) { $px = $A[$a]['o'][$k]; $full = empty($pend[$a]); $v = $full ? $u[$a] * $px : -$dv;
        $c = $v * (0.00001 + 0.0000362 + 0.0005) + 15.93; $cash += $v - $c; $costs += $c; $trades++; if ($full) unset($u[$a]); else $u[$a] -= $v / $px; }
      foreach ($ord as $a => $dv) if ($dv > 300) { $px = $A[$a]['o'][$k]; $v = min($dv, $cash / 1.001); if ($v < 300) continue;
        $c = $v * (0.00015 + 0.0000362 + 0.0005); $cash -= $v + $c; $costs += $c; $trades++; $u[$a] = ($u[$a] ?? 0) + $v / $px; }
      $pend = null;
    }
    $v = $cash; foreach ($u as $a => $q) $v += $q * $A[$a]['c'][$k]; $eq[$k] = $v;
    if ($k < $T - 1) { $w = $dec($k, $st); if ($w !== null) $pend = $w; }
  }
  return ['eq' => $eq, 'trades' => $trades, 'costs' => $costs];
}
function ex_stats(array $eq, $a, $b, array $dates) {
  $v0 = $eq[$a]; $v1 = $eq[$b]; $yrs = max(0.05, (strtotime($dates[$b]) - strtotime($dates[$a])) / (365.25 * 86400));
  $pk = $v0; $dd = 0.0; $r = [];
  for ($k = $a; $k <= $b; $k++) { $pk = max($pk, $eq[$k]); $dd = min($dd, $eq[$k] / $pk - 1); if ($k > $a) $r[] = log($eq[$k] / $eq[$k - 1]); }
  $m = array_sum($r) / max(1, count($r)); $v = 0.0; foreach ($r as $x) $v += ($x - $m) ** 2; $vol = sqrt($v / max(1, count($r)) * 252); $cagr = pow($v1 / $v0, 1 / $yrs) - 1;
  return ['cagr' => round($cagr * 100, 2), 'dd' => round($dd * 100, 1), 'rpr' => $vol > 0 ? round(($cagr - EX_CASH) / $vol, 3) : 0, 'ret' => round(($v1 / $v0 - 1) * 100, 2)];
}

/* ---------- the rule families ---------- */
function ex_decider(array $A, array $cfg) {
  $p = $cfg['p']; $has = function ($a, $k, $back = 0) use ($A) { return isset($A[$a]) && $k - $back >= 0 && $A[$a]['c'][$k] !== null && $A[$a]['c'][$k - $back] !== null; };
  $up = function ($a, $k, $n) use ($A) { if (!$n) return true; $s = $A[$a]['sma'][$n][$k] ?? null; return $s !== null && $A[$a]['c'][$k] > $s; };
  switch ($cfg['f']) {
    case 'dip': // buy an asset after a fall of f% over L days (optionally only above its n-day average); sell after H days
      return function ($k, &$st) use ($A, $p, $has, $up) {
        $st += ['pos' => []]; $chg = false;
        foreach ($st['pos'] as $a => $e) if ($k - $e >= $p['H']) { unset($st['pos'][$a]); $chg = true; }
        $sig = []; foreach ($p['assets'] as $a) { if (isset($st['pos'][$a]) || !$has($a, $k, $p['L']) || !$up($a, $k, $p['sma'])) continue; $r = $A[$a]['c'][$k] / $A[$a]['c'][$k - $p['L']] - 1; if ($r <= -$p['f']) $sig[$a] = $r; }
        asort($sig); foreach ($sig as $a => $_) { if (count($st['pos']) >= $p['slots']) break; $st['pos'][$a] = $k + 1; $chg = true; }
        if (!$chg) return null; $w = []; foreach ($st['pos'] as $a => $_) $w[$a] = 1 / $p['slots']; return $w; };
    case 'rot': // every N days hold the K strongest assets by return over R days (skipping the last S), optionally only if that return beats cash
      return function ($k, &$st) use ($A, $p, $has) {
        if (isset($st['last']) && $k - $st['last'] < $p['every']) return null; $st['last'] = $k;
        $r = []; foreach ($p['assets'] as $a) if ($has($a, $k, $p['R'])) $r[$a] = $A[$a]['c'][$k - $p['S']] / $A[$a]['c'][$k - $p['R']] - 1;
        arsort($r); $pick = [];
        foreach (array_slice($r, 0, $p['K'], true) as $a => $x) { if ($p['abs'] === 'pos' && $x <= 0) continue; if ($p['abs'] === 'cash' && $x <= EX_CASH * ($p['R'] - $p['S']) / 252) continue; $pick[] = $a; }
        $w = [];
        if ($p['wt'] === 'iv') { $iv = []; foreach ($pick as $a) { $v = ex_vol($A[$a]['c'], $k); if ($v) $iv[$a] = 1 / $v; } $s = array_sum($iv); foreach ($iv as $a => $x) $w[$a] = $x / $s * count($pick) / $p['K']; }
        else foreach ($pick as $a) $w[$a] = 1 / $p['K'];
        return $w; };
    case 'trend': // equal slices of the chosen assets, each held only while above its n-day average (checked every N days)
      return function ($k, &$st) use ($A, $p, $has, $up) {
        if (isset($st['last']) && $k - $st['last'] < $p['every']) return null; $st['last'] = $k;
        $w = []; foreach ($p['assets'] as $a) if ($has($a, $k, 1) && $up($a, $k, $p['sma'])) $w[$a] = 1 / count($p['assets']); return $w; };
    case 'mix': // a fixed mix, checked every N days, rebalanced when a slice drifts more than the band
      return function ($k, &$st) use ($A, $p, $has) {
        if (isset($st['last']) && $k - $st['last'] < $p['every']) return null; $st['last'] = $k;
        $w = []; foreach ($p['w'] as $a => $x) if ($has($a, $k)) $w[$a] = $x; return $w; };
    case 'mixdip': // a fixed mix kept at (1 - x); the rest waits in cash and buys dips in the mix's own assets for H days
      return function ($k, &$st) use ($A, $p, $has) {
        $st += ['dip' => [], 'last' => -999]; $chg = false;
        foreach ($st['dip'] as $a => $e) if ($k - $e >= $p['H']) { unset($st['dip'][$a]); $chg = true; }
        foreach ($p['w'] as $a => $_) { if (isset($st['dip'][$a]) || !$has($a, $k, $p['L'])) continue; if ($A[$a]['c'][$k] / $A[$a]['c'][$k - $p['L']] - 1 <= -$p['f']) { $st['dip'][$a] = $k + 1; $chg = true; } }
        if (!$chg && $k - $st['last'] < $p['every']) return null; $st['last'] = $k;
        $w = []; foreach ($p['w'] as $a => $x) if ($has($a, $k)) $w[$a] = $x * (1 - $p['x']);
        $n = count($st['dip']); foreach ($st['dip'] as $a => $_) $w[$a] = ($w[$a] ?? 0) + $p['x'] / max(1, $n); return $w; };
  }
  return function () { return null; };
}

/* ---------- random settings and small changes to good ones ---------- */
function ex_pick(array $from, $min, $max) { shuffle($from); return array_slice($from, 0, mt_rand($min, min($max, count($from)))); }
function ex_one(array $o) { return $o[array_rand($o)]; }
function ex_weights(array $assets) { $g = []; foreach ($assets as $a) $g[$a] = -log(max(1e-9, mt_rand() / mt_getrandmax())); $s = array_sum($g); $w = [];
  foreach ($g as $a => $x) if ($x / $s >= 0.03) $w[$a] = $x / $s; $t = array_sum($w); foreach ($w as $a => $x) $w[$a] = round($x / $t, 2); return $w; }
function ex_random($f, array $ASSETS) {
  $eqIdx = array_values(array_diff($ASSETS, ['GOLD', 'NASDAQ']));
  switch ($f) {
    case 'dip': return ['assets' => ex_pick($eqIdx, 1, 5), 'f' => ex_one([0.015, 0.02, 0.025, 0.03, 0.035, 0.04, 0.05, 0.06]), 'L' => ex_one([2, 3, 4, 5, 7, 10, 15]),
                        'H' => ex_one([2, 3, 5, 7, 10, 15, 20]), 'sma' => ex_one([0, 100, 150, 200]), 'slots' => ex_one([1, 2, 3, 4])];
    case 'rot': return ['assets' => ex_pick($ASSETS, 3, 11), 'R' => ex_one([42, 63, 126, 189, 252]), 'S' => ex_one([0, 5, 21]), 'K' => ex_one([1, 2, 3, 4]),
                        'every' => ex_one([5, 10, 21, 63]), 'abs' => ex_one(['none', 'pos', 'cash']), 'wt' => ex_one(['eq', 'iv'])];
    case 'trend': return ['assets' => ex_pick($ASSETS, 2, 8), 'sma' => ex_one([50, 100, 150, 200]), 'every' => ex_one([1, 5, 21])];
    case 'mix': $as = ex_pick($ASSETS, 2, 6); return ['w' => ex_weights($as), 'every' => ex_one([21, 63, 252]), 'band' => ex_one([0.03, 0.05, 0.1])];
    case 'mixdip': $as = ex_pick($ASSETS, 3, 5); return ['w' => ex_weights($as), 'every' => 21, 'band' => 0.05, 'x' => ex_one([0.1, 0.2, 0.3]),
                        'f' => ex_one([0.02, 0.03, 0.04, 0.05]), 'L' => ex_one([3, 5, 10]), 'H' => ex_one([5, 10, 20])];
  }
}
function ex_mutate($f, array $p, array $ASSETS) { // change one setting of a good one
  $r = ex_random($f, $ASSETS); $ks = array_keys($p); $k = ex_one($ks); $p[$k] = $r[$k];
  if (isset($p['w'])) { $p['w'] = array_filter($p['w'], function ($x) { return $x >= 0.03; }); $s = array_sum($p['w']) ?: 1; foreach ($p['w'] as $a => $x) $p['w'][$a] = round($x / $s, 2); }
  return $p; }
function ex_id(array $cfg) { return substr(md5(json_encode($cfg)), 0, 10); }
function ex_label(array $cfg) {
  $p = $cfg['p']; $as = function ($l) { return implode('+', $l); };
  switch ($cfg['f']) {
    case 'dip': return sprintf('Buy %s after a %s%% fall in %d days%s, sell after %d days, up to %d at once', $as($p['assets']), $p['f'] * 100, $p['L'], $p['sma'] ? ", above the {$p['sma']}-day average" : '', $p['H'], $p['slots']);
    case 'rot': return sprintf('Every %d days hold the %d strongest of %s by %d-day return%s%s%s', $p['every'], $p['K'], $as($p['assets']), $p['R'], $p['S'] ? " (skipping the last {$p['S']})" : '', $p['abs'] === 'pos' ? ', only if rising' : ($p['abs'] === 'cash' ? ', only if beating cash' : ''), $p['wt'] === 'iv' ? ', calmer ones bigger' : '');
    case 'trend': return sprintf('Equal slices of %s, each only above its %d-day average (checked every %d days)', $as($p['assets']), $p['sma'], $p['every']);
    case 'mix': $w = []; foreach ($p['w'] as $a => $x) $w[] = round($x * 100) . "% $a"; return 'Fixed mix ' . implode(' / ', $w) . sprintf(', %d-point band, checked every %d days', $p['band'] * 100, $p['every']);
    case 'mixdip': $w = []; foreach ($p['w'] as $a => $x) $w[] = round($x * 100) . "% $a"; return 'Mix ' . implode(' / ', $w) . sprintf(' with %d%% kept to buy %s%% dips (in %d days) for %d days', $p['x'] * 100, $p['f'] * 100, $p['L'], $p['H']);
  }
  return $cfg['f'];
}

/* ---------- state ---------- */
$S = is_file($FILE) ? (json_decode((string) file_get_contents($FILE), true) ?: []) : [];
$S += ['version' => 1, 'tried_total' => 0, 'runs' => 0, 'per_family' => [], 'seeds' => [], 'candidates' => [], 'recent_runs' => [], 'started' => $today];
$dataEnd = $dates[$T - 1];
$eval = function (array $cfg) use ($A, $start, $split, $T, $dates) {
  $R = ex_run($A, $cfg, $start, $T);
  return ['train' => ex_stats($R['eq'], $start, $split, $dates), 'gate' => ex_stats($R['eq'], $split, $T - 1, $dates), 'trades' => $R['trades'], 'costs' => round($R['costs']), 'eq' => $R['eq']];
};
/* yardsticks: the Core portfolio (equal Nifty / Midcap / Nasdaq / gold, 5-point band, monthly) and the Nifty */
$core = ['f' => 'mix', 'p' => ['w' => ['NIFTY' => 0.25, 'MID' => 0.25, 'NASDAQ' => 0.25, 'GOLD' => 0.25], 'every' => 21, 'band' => 0.05]];
$CE = $eval($core);
$nEq = []; for ($k = $start; $k < $T; $k++) $nEq[$k] = 10000 * $A['NIFTY']['c'][$k] / $A['NIFTY']['c'][$start];
$S['core'] = ['train' => $CE['train'], 'gate' => $CE['gate']]; $S['nifty'] = ['train' => ex_stats($nEq, $start, $split, $dates), 'gate' => ex_stats($nEq, $split, $T - 1, $dates)];
$S['data'] = ['from' => $dates[$start], 'gate_from' => $dates[$split], 'to' => $dataEnd, 'assets' => $ASSETS];

/* a candidate must beat the Core portfolio in the tuning years AND in the gate period, without deeper falls */
$gate = function ($r) use ($CE) {
  return $r['trades'] >= 5 && $r['train']['rpr'] >= $CE['train']['rpr'] + 0.05 && $r['train']['dd'] >= -35
    && $r['gate']['rpr'] > $CE['gate']['rpr'] && $r['gate']['cagr'] >= $CE['gate']['cagr'] - 1 && $r['gate']['dd'] >= min(-25, $CE['gate']['dd'] - 3); };
/* the search score uses the tuning years only */
$score = function ($r) { return $r['train']['dd'] < -40 ? -9 : $r['train']['rpr'] + min(0, ($r['train']['dd'] + 30) / 100); };

/* ---------- 1. forward test: re-score every candidate on the days since it was found ---------- */
foreach ($S['candidates'] as &$c) {
  $since = $c['found_data_end']; $a = null; foreach ($dates as $k => $dd) if ($dd > $since) { $a = $k - 1; break; }
  if ($a === null || $a < $start || $T - 1 - $a < 5) { $c['forward'] = ['days' => 0]; continue; }
  $R = ex_run($A, ['f' => $c['f'], 'p' => $c['p']], $start, $T);
  $mine = ex_stats($R['eq'], $a, $T - 1, $dates); $cr = ex_stats($CE['eq'], $a, $T - 1, $dates); $days = $T - 1 - $a;
  $c['forward'] = ['since' => $since, 'days' => $days, 'ret' => $mine['ret'], 'dd' => $mine['dd'], 'core_ret' => $cr['ret']];
  $c['status'] = $days < EX_FWD_DAYS ? 'forward testing' : ($mine['ret'] >= $cr['ret'] ? 'confirmed' : 'failed forward test');
}
unset($c);

/* ---------- 2. search for new settings until the time budget runs out ---------- */
$FAM = ['dip', 'rot', 'trend', 'mix', 'mixdip']; $tried = 0; $new = 0; $seen = array_flip(array_column($S['candidates'], 'id'));
mt_srand(crc32($today . microtime()));
while (microtime(true) - $T0 < $BUDGET) {
  $f = $FAM[$tried % count($FAM)];
  $seeds = $S['seeds'][$f] ?? [];
  $p = ($seeds && mt_rand(1, 100) <= 40) ? ex_mutate($f, ex_one($seeds)['p'], $ASSETS) : ex_random($f, $ASSETS);
  $cfg = ['f' => $f, 'p' => $p]; $id = ex_id($cfg);
  $r = $eval($cfg); $tried++; $S['per_family'][$f] = ($S['per_family'][$f] ?? 0) + 1;
  $sc = $score($r);
  $seeds[] = ['p' => $p, 'score' => round($sc, 3)]; usort($seeds, function ($x, $y) { return $y['score'] <=> $x['score']; });
  $u = []; $S['seeds'][$f] = array_values(array_filter(array_slice($seeds, 0, 20), function ($s) use (&$u) { $h = md5(json_encode($s['p'])); if (isset($u[$h])) return false; $u[$h] = 1; return true; }));
  $S['seeds'][$f] = array_slice($S['seeds'][$f], 0, 10);
  if (!isset($seen[$id]) && $gate($r)) {
    $seen[$id] = 1; $new++;
    $S['candidates'][] = ['id' => $id, 'f' => $f, 'p' => $p, 'label' => ex_label($cfg), 'found_on' => $today, 'found_data_end' => $dataEnd, 'tried_before' => $S['tried_total'] + $tried,
      'train' => $r['train'], 'gate' => $r['gate'], 'trades' => $r['trades'], 'costs' => $r['costs'], 'status' => 'forward testing', 'forward' => ['days' => 0]];
  }
}
$S['tried_total'] += $tried; $S['runs']++; $S['last_run'] = gmdate('Y-m-d H:i', time() + 19800) . ' IST';
/* keep every candidate with a forward record, plus the best new ones (by the gate period), at most 40 */
usort($S['candidates'], function ($x, $y) { $fx = $x['forward']['days'] ?? 0; $fy = $y['forward']['days'] ?? 0; if (($fx > 0) !== ($fy > 0)) return $fy <=> $fx; return $y['gate']['rpr'] <=> $x['gate']['rpr']; });
$S['candidates'] = array_slice($S['candidates'], 0, 40);
array_unshift($S['recent_runs'], ['at' => $S['last_run'], 'tried' => $tried, 'new_candidates' => $new, 'seconds' => round(microtime(true) - $T0)]);
$S['recent_runs'] = array_slice($S['recent_runs'], 0, 30);
$S['summary'] = ['confirmed' => count(array_filter($S['candidates'], function ($c) { return $c['status'] === 'confirmed'; })),
  'failed' => count(array_filter($S['candidates'], function ($c) { return $c['status'] === 'failed forward test'; })),
  'testing' => count(array_filter($S['candidates'], function ($c) { return $c['status'] === 'forward testing'; }))];
@mkdir(dirname($FILE), 0775, true);
file_put_contents($FILE, json_encode($S, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo json_encode(['tried_this_run' => $tried, 'tried_total' => $S['tried_total'], 'new_candidates' => $new, 'candidates' => count($S['candidates']), 'summary' => $S['summary'], 'core' => $S['core'],
  'top' => array_map(function ($c) { return ['label' => $c['label'], 'train' => $c['train'], 'gate' => $c['gate'], 'status' => $c['status'], 'forward' => $c['forward']]; }, array_slice($S['candidates'], 0, 5))], JSON_UNESCAPED_UNICODE), "\n";
