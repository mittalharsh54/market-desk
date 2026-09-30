<?php
/* Intraday strategy explorer — part of the 24/7 search (explorer.yml).
     php tools/explorer-intraday.php [seconds=600]
   Real 5-minute bars (Upstox, ~18 months) for 25 of the most liquid Nifty stocks. Every setting is
   traded on every stock and every day it applies to, one trade per stock per day, Rs 10,000 per trade,
   entries on completed bars, stops checked against each bar's high / low, everything closed by 15:15,
   with real intraday charges (brokerage, STT, exchange, stamp, GST — the app's own mk_charges).
   Families: opening-range breakouts, gap fade / gap-and-go, VWAP stretch reversion, first-hour
   momentum or reversal, and late-day momentum.
   Same honesty rules as the daily explorer: the search learns only from the first 60% of days; a
   candidate must also make money after charges in the later 40%; then it is re-scored on the days
   after it was found, and marked confirmed / failed after 60 such days. State: research/explorer_intraday.json */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../market.php';
require_once __DIR__ . '/_fetch.php';
ini_set('memory_limit', '2048M'); set_time_limit(0);

$BUDGET = max(30, (int) ($argv[1] ?? 600)); $T0 = microtime(true);
$FILE = dirname(__DIR__) . '/research/explorer_intraday.json';
define('XI_NOTIONAL', 10000.0);
define('XI_FWD_DAYS', 60);
$today = date('Y-m-d', time() + 19800);
$SYNTH = (bool) getenv('EX_SYNTH');

/* ---------- data: day-by-day 5-minute bars ---------- */
$syms = array_slice(id_universe(), 0, 25); $DAYS = []; // DAYS[sym][] = [date, prevClose, o[], h[], l[], c[], v[]]
if ($SYNTH) { mt_srand(9); foreach (array_slice($syms, 0, 6) as $s) { $px = 1000; for ($d = 0; $d < 300; $d++) { $pc = $px; $bar = ['o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
  $px *= 1 + 0.01 * (mt_rand() / mt_getrandmax() * 2 - 1); for ($i = 0; $i < 75; $i++) { $o = $px; $px *= 1 + 0.002 * (mt_rand() / mt_getrandmax() * 2 - 1);
  $bar['o'][] = $o; $bar['h'][] = max($o, $px) * 1.0005; $bar['l'][] = min($o, $px) * 0.9995; $bar['c'][] = $px; $bar['v'][] = 1000; }
  $DAYS[$s][] = ['d' => date('Y-m-d', strtotime("2025-01-01 +$d days")), 'pc' => $pc] + $bar; } } }
else {
  $want = [];
  foreach ($syms as $s) { $k = mkt_upstox_key(mkt_norm_symbol($s) ?: $s); if (!$k) continue;
    for ($m = 0; $m < 18; $m++) { $to = date('Y-m-d', strtotime("$today -" . ($m * 30 + 1) . ' days')); $from = date('Y-m-d', strtotime("$today -" . ($m * 30 + 30) . ' days'));
      $want["$s|$m"] = ['url' => 'https://api.upstox.com/v3/historical-candle/' . rawurlencode($k[0]) . "/minutes/5/$to/$from", 'headers' => ['Accept: application/json']]; } }
  $res = study_fetch($want);
  foreach ($syms as $s) { $C = null;
    for ($m = 0; $m < 18; $m++) { $x = $res["$s|$m"] ?? null; if ($x && $x['code'] === 200) { $Q = mkt_upstox_parse($x['body']); if ($Q && count($Q['c'])) { $Q = mk_clean($Q); $C = $C ? mkt_candles_merge($Q, $C) : $Q; } } }
    if (!$C) continue; $by = [];
    foreach ($C['t'] as $i => $t) { $m = mk_ist_min($t); if ($m < 555 || $m > 925) continue; $by[mk_ist_date($t)][] = $i; }
    ksort($by); $prev = null;
    foreach ($by as $d => $ix) { if (count($ix) < 70) { $prev = null; continue; } $bar = ['o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
      foreach ($ix as $i) foreach ($bar as $f => $_) $bar[$f][] = (float) $C[$f][$i];
      if ($prev !== null) $DAYS[$s][] = ['d' => $d, 'pc' => $prev] + $bar; $prev = end($bar['c']); }
  }
}
$allDates = []; foreach ($DAYS as $s => $L) foreach ($L as $x) $allDates[$x['d']] = 1; ksort($allDates); $allDates = array_keys($allDates);
if (count($allDates) < 100) { fwrite(STDERR, "not enough intraday history (" . count($allDates) . " days)\n"); exit(1); }
$splitDate = $allDates[(int) (0.6 * count($allDates))]; $dataEnd = end($allDates);
fwrite(STDERR, count($DAYS) . ' stocks, ' . count($allDates) . ' days (' . $allDates[0] . ' → ' . $dataEnd . '), gate from ' . $splitDate . "\n");
/* VWAP per day, computed once */
foreach ($DAYS as $s => &$L) foreach ($L as &$x) { $pv = 0.0; $vv = 0.0; $x['vw'] = [];
  foreach ($x['c'] as $i => $c) { $tp = ($x['h'][$i] + $x['l'][$i] + $c) / 3; $pv += $tp * max(1, $x['v'][$i]); $vv += max(1, $x['v'][$i]); $x['vw'][] = $pv / $vv; } } unset($L, $x);

/* ---------- one trade: entry at bar e's close (or the open when e = -1), stop / target watched on later bars, out by 15:15 (bar 72) ---------- */
function xi_trade(array $x, $e, $dir, $stop, $target) {
  $en = $e < 0 ? $x['o'][0] : $x['c'][$e]; $out = null; $last = 72;
  for ($i = max(0, $e + 1); $i <= $last; $i++) {
    if ($dir > 0) { if ($x['l'][$i] <= $stop) { $out = min($x['o'][$i], $stop); break; } if ($target !== null && $x['h'][$i] >= $target) { $out = max($x['o'][$i], $target); break; } }
    else { if ($x['h'][$i] >= $stop) { $out = max($x['o'][$i], $stop); break; } if ($target !== null && $x['l'][$i] <= $target) { $out = min($x['o'][$i], $target); break; } }
  }
  if ($out === null) $out = $x['c'][$last];
  $q = floor(XI_NOTIONAL / $en); if ($q < 1) return null;
  $gross = ($out - $en) * $q * $dir; $ch = mk_charges($dir > 0 ? [['B', $q * $en], ['S', $q * $out]] : [['S', $q * $en], ['B', $q * $out]]);
  return ($gross - $ch) / ($q * $en) * 100; // net % of the position
}
/* ---------- the rule families: return [entryBar, dir, stop, target] or null for one stock-day ---------- */
function xi_signal(array $x, array $cfg) {
  $p = $cfg['p']; $o = $x['o'][0]; $gap = $o / $x['pc'] - 1;
  switch ($cfg['f']) {
    case 'orb': // break of the first N bars' range, confirmed on a close
      if ($p['gap'] === 'up' && $gap < 0.003) return null; if ($p['gap'] === 'down' && $gap > -0.003) return null;
      $hi = max(array_slice($x['h'], 0, $p['N'])); $lo = min(array_slice($x['l'], 0, $p['N'])); $rng = $hi - $lo; if ($rng <= 0) return null;
      for ($i = $p['N']; $i < 66; $i++) { $c = $x['c'][$i];
        $d = $c > $hi ? 1 : ($c < $lo ? -1 : 0); if (!$d) continue;
        if (($p['dir'] === 'long' && $d < 0) || ($p['dir'] === 'short' && $d > 0)) return null;
        $risk = $p['stop'] === 'range' ? $rng : $c * $p['stop']; $stop = $c - $d * $risk; $tgt = $p['R'] ? $c + $d * $risk * $p['R'] : null;
        return [$i, $d, $stop, $tgt]; }
      return null;
    case 'gap': // a gap of at least g%: fade it back towards yesterday's close, or go with it
      if (abs($gap) < $p['g']) return null; $d = ($p['mode'] === 'fade' ? -1 : 1) * ($gap > 0 ? 1 : -1);
      $e = $p['wait'] ? 0 : -1; $en = $e < 0 ? $o : $x['c'][0];
      if ($p['wait'] && ($x['c'][0] - $o) * $d < 0) return null; // wait for the first bar to agree
      $stop = $en * (1 - $d * $p['stop']); $tgt = $p['mode'] === 'fade' ? $x['pc'] : ($p['R'] ? $en * (1 + $d * $p['stop'] * $p['R']) : null);
      return [$e, $d, $stop, $tgt];
    case 'vwap': // price stretched k% from VWAP after a time: bet on the snap back to VWAP
      for ($i = $p['from']; $i < 66; $i++) { $dev = $x['c'][$i] / $x['vw'][$i] - 1; if (abs($dev) < $p['k']) continue;
        $d = $dev > 0 ? -1 : 1; $c = $x['c'][$i]; return [$i, $d, $c * (1 - $d * $p['k'] * $p['sm']), $x['vw'][$i]]; }
      return null;
    case 'first': // the first N bars moved x%: go with it (momentum) or against it (reversal), stop y%
      $r = $x['c'][$p['N'] - 1] / $o - 1; if (abs($r) < $p['x']) return null; $d = ($p['mode'] === 'mom' ? 1 : -1) * ($r > 0 ? 1 : -1);
      $c = $x['c'][$p['N'] - 1]; return [$p['N'] - 1, $d, $c * (1 - $d * $p['y']), $p['R'] ? $c * (1 + $d * $p['y'] * $p['R']) : null];
    case 'late': // by bar B the day is up / down x%: ride it into the close
      $r = $x['c'][$p['B']] / $x['pc'] - 1; if (abs($r) < $p['x']) return null; $d = ($p['mode'] === 'mom' ? 1 : -1) * ($r > 0 ? 1 : -1);
      $c = $x['c'][$p['B']]; return [$p['B'], $d, $c * (1 - $d * $p['y']), null];
  }
  return null;
}
function xi_eval(array $DAYS, array $cfg) { $T = [];
  foreach ($DAYS as $s => $L) foreach ($L as $x) { $sg = xi_signal($x, $cfg); if (!$sg) continue; $r = xi_trade($x, $sg[0], $sg[1], $sg[2], $sg[3]); if ($r !== null) $T[] = [$x['d'], $r]; }
  return $T; }
function xi_stats(array $T) { $n = count($T); if (!$n) return ['n' => 0, 'avg' => null, 'pf' => null, 'win' => null];
  $r = array_column($T, 1); $g = array_sum(array_filter($r, function ($v) { return $v > 0; })); $l = -array_sum(array_filter($r, function ($v) { return $v < 0; }));
  return ['n' => $n, 'avg' => round(array_sum($r) / $n, 3), 'pf' => $l > 0 ? round($g / $l, 2) : null, 'win' => round(count(array_filter($r, function ($v) { return $v > 0; })) / $n * 100, 1)]; }
function xi_split(array $T, $a, $b = null) { return array_values(array_filter($T, function ($t) use ($a, $b) { return $t[0] >= $a && ($b === null || $t[0] < $b); })); }

/* ---------- settings ---------- */
function xi_one(array $o) { return $o[array_rand($o)]; }
function xi_random($f) {
  switch ($f) {
    case 'orb': return ['N' => xi_one([3, 6, 12]), 'dir' => xi_one(['long', 'short', 'both']), 'stop' => xi_one(['range', 0.005, 0.01]), 'R' => xi_one([0, 1, 1.5, 2, 3]), 'gap' => xi_one(['any', 'up', 'down'])];
    case 'gap': return ['g' => xi_one([0.005, 0.01, 0.015, 0.02, 0.03]), 'mode' => xi_one(['fade', 'go']), 'wait' => xi_one([0, 1]), 'stop' => xi_one([0.005, 0.01, 0.02]), 'R' => xi_one([0, 1, 2])];
    case 'vwap': return ['k' => xi_one([0.005, 0.0075, 0.01, 0.015]), 'from' => xi_one([6, 9, 18, 30]), 'sm' => xi_one([0.5, 1, 1.5])];
    case 'first': return ['N' => xi_one([3, 6, 12]), 'x' => xi_one([0.005, 0.01, 0.015, 0.02]), 'mode' => xi_one(['mom', 'rev']), 'y' => xi_one([0.005, 0.01, 0.02]), 'R' => xi_one([0, 1, 2])];
    case 'late': return ['B' => xi_one([48, 54, 60]), 'x' => xi_one([0.005, 0.01, 0.015, 0.02]), 'mode' => xi_one(['mom', 'rev']), 'y' => xi_one([0.005, 0.01])];
  }
}
function xi_label(array $cfg) { $p = $cfg['p']; $pc = function ($v) { return round($v * 100, 2) . '%'; }; $t = function ($bar) { $m = 555 + 5 * ($bar + 1); return sprintf('%d:%02d', intdiv($m, 60), $m % 60); };
  switch ($cfg['f']) {
    case 'orb': return sprintf('%s-minute opening-range breakout (%s)%s, stop %s, %s', $p['N'] * 5, $p['dir'], $p['gap'] !== 'any' ? " on gap-{$p['gap']} days" : '', $p['stop'] === 'range' ? 'other side of the range' : $pc($p['stop']), $p['R'] ? "target {$p['R']}× risk" : 'hold to 15:15');
    case 'gap': return sprintf('Gap of %s+: %s it%s, stop %s, %s', $pc($p['g']), $p['mode'], $p['wait'] ? ' (after the first 5 minutes agree)' : ' at the open', $pc($p['stop']), $p['mode'] === 'fade' ? "target yesterday's close" : ($p['R'] ? "target {$p['R']}× risk" : 'hold to 15:15'));
    case 'vwap': return sprintf('After %s, price %s away from VWAP: trade back to VWAP, stop %s further', $t($p['from']), $pc($p['k']), $pc($p['k'] * $p['sm']));
    case 'first': return sprintf('First %d minutes move %s+: %s, stop %s, %s', $p['N'] * 5, $pc($p['x']), $p['mode'] === 'mom' ? 'go with it' : 'bet on a reversal', $pc($p['y']), $p['R'] ? "target {$p['R']}× risk" : 'hold to 15:15');
    case 'late': return sprintf('Day up / down %s+ by %s: %s into the close, stop %s', $pc($p['x']), $t($p['B']), $p['mode'] === 'mom' ? 'ride it' : 'fade it', $pc($p['y']));
  }
  return $cfg['f']; }

/* ---------- state, forward test, search ---------- */
$S = is_file($FILE) ? (json_decode((string) file_get_contents($FILE), true) ?: []) : [];
$S += ['version' => 1, 'tried_total' => 0, 'runs' => 0, 'per_family' => [], 'seeds' => [], 'candidates' => [], 'recent_runs' => [], 'started' => $today, 'tested' => []];
$S['data'] = ['stocks' => count($DAYS), 'days' => count($allDates), 'from' => $allDates[0], 'gate_from' => $splitDate, 'to' => $dataEnd];
foreach ($S['candidates'] as &$c) {
  $T = xi_split(xi_eval($DAYS, ['f' => $c['f'], 'p' => $c['p']]), date('Y-m-d', strtotime($c['found_data_end'] . ' +1 day')));
  $days = count(array_unique(array_column($T, 0))); $st = xi_stats($T);
  $c['forward'] = ['since' => $c['found_data_end'], 'days' => count(array_filter($allDates, function ($d) use ($c) { return $d > $c['found_data_end']; })), 'trades' => $st['n'], 'avg' => $st['avg'], 'pf' => $st['pf'], 'win' => $st['win']];
  $c['status'] = $c['forward']['days'] < XI_FWD_DAYS ? 'forward testing' : (($st['avg'] ?? -1) > 0 ? 'confirmed' : 'failed forward test');
} unset($c);
$FAM = ['orb', 'gap', 'vwap', 'first', 'late']; $tried = 0; $new = 0; $seen = array_flip(array_column($S['candidates'], 'id')); $tested = array_flip($S['tested']);
mt_srand(crc32($today . microtime()));
$gate = function ($a, $b) { return $a['n'] >= 300 && $b['n'] >= 150 && $a['avg'] > 0 && $b['avg'] > 0 && ($a['pf'] ?? 0) >= 1.1 && ($b['pf'] ?? 0) >= 1.1; };
$attempts = 0;
while (microtime(true) - $T0 < $BUDGET && $attempts < 20000) {
  $attempts++; $f = $FAM[$attempts % count($FAM)]; $seeds = $S['seeds'][$f] ?? [];
  $p = xi_random($f); if ($seeds && mt_rand(1, 100) <= 40) { $b = xi_one($seeds)['p']; $k = xi_one(array_keys($b)); $b[$k] = $p[$k]; $p = $b; }
  ksort($p); $cfg = ['f' => $f, 'p' => $p]; $id = substr(md5(json_encode($cfg)), 0, 10);
  if (isset($tested[$id])) continue; // the space is finite: never re-test a setting
  $tested[$id] = 1; $tried++; $S['per_family'][$f] = ($S['per_family'][$f] ?? 0) + 1;
  $T = xi_eval($DAYS, $cfg); $a = xi_stats(xi_split($T, '0000', $splitDate)); $b = xi_stats(xi_split($T, $splitDate));
  $sc = $a['n'] >= 300 ? $a['avg'] : -9;
  $seeds[] = ['p' => $p, 'score' => $sc]; usort($seeds, function ($x, $y) { return $y['score'] <=> $x['score']; }); $S['seeds'][$f] = array_slice($seeds, 0, 10);
  if (!isset($seen[$id]) && $gate($a, $b)) { $seen[$id] = 1; $new++;
    $S['candidates'][] = ['id' => $id, 'f' => $f, 'p' => $p, 'label' => xi_label($cfg), 'found_on' => $today, 'found_data_end' => $dataEnd, 'tried_before' => $S['tried_total'] + $tried,
      'train' => $a, 'gate' => $b, 'status' => 'forward testing', 'forward' => ['days' => 0]]; }
}
$S['tested'] = array_keys($tested); $S['tried_total'] += $tried; $S['runs']++; $S['last_run'] = gmdate('Y-m-d H:i', time() + 19800) . ' IST';
$S['space_left'] = $attempts >= 20000 ? 'mostly explored' : 'open';
usort($S['candidates'], function ($x, $y) { $fx = $x['forward']['days'] ?? 0; $fy = $y['forward']['days'] ?? 0; if (($fx > 0) !== ($fy > 0)) return $fy <=> $fx; return $y['gate']['avg'] <=> $x['gate']['avg']; });
$S['candidates'] = array_slice($S['candidates'], 0, 40);
array_unshift($S['recent_runs'], ['at' => $S['last_run'], 'tried' => $tried, 'new_candidates' => $new, 'seconds' => round(microtime(true) - $T0)]); $S['recent_runs'] = array_slice($S['recent_runs'], 0, 30);
/* the best ones by the tuning period, whatever the gate said — so it is visible how far from profitable the best intraday rules are */
$best = []; foreach ($S['seeds'] as $f => $L) foreach (array_slice($L, 0, 2) as $x) $best[] = ['f' => $f, 'label' => xi_label(['f' => $f, 'p' => $x['p']]), 'train_avg_net_pct' => $x['score']];
usort($best, function ($x, $y) { return $y['train_avg_net_pct'] <=> $x['train_avg_net_pct']; }); $S['best_by_tuning'] = array_slice($best, 0, 6);
$S['summary'] = ['confirmed' => count(array_filter($S['candidates'], function ($c) { return $c['status'] === 'confirmed'; })),
  'failed' => count(array_filter($S['candidates'], function ($c) { return $c['status'] === 'failed forward test'; })),
  'testing' => count(array_filter($S['candidates'], function ($c) { return $c['status'] === 'forward testing'; }))];
@mkdir(dirname($FILE), 0775, true);
file_put_contents($FILE, json_encode($S, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo json_encode(['tried_this_run' => $tried, 'tried_total' => $S['tried_total'], 'new_candidates' => $new, 'candidates' => count($S['candidates']), 'summary' => $S['summary'], 'data' => $S['data'],
  'top' => array_map(function ($c) { return ['label' => $c['label'], 'train' => $c['train'], 'gate' => $c['gate'], 'status' => $c['status']]; }, array_slice($S['candidates'], 0, 5)), 'best_by_tuning' => $S['best_by_tuning']], JSON_UNESCAPED_UNICODE), "\n";
