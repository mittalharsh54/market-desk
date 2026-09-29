<?php
/* The weekly learning step. Reads the latest study results and updates rules.json,
   which the app uses for its live rules and for what it tells you about them.
     php tools/learn.php intraday.jsonl swing.jsonl lab.jsonl oc.jsonl pro.jsonl
   A change is adopted only when it made money AFTER charges in BOTH halves of the
   test period, on enough trades, and beats the current rule by a clear margin.
   Otherwise the current rule stays and the app says there is no tested edge. */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__); $file = "$root/rules.json";
$rules = json_decode((string) @file_get_contents($file), true) ?: [];
$rules += ['intraday' => ['vol_mult' => 1.5, 'th' => 0.40, 'max_trades' => 2, 'tradeable' => false], 'swing' => ['th' => 0.30, 'tradeable' => false], 'log' => []];
$read = function ($f) { $o = []; if ($f && is_file($f)) foreach (file($f) as $l) { $j = json_decode($l, true); if ($j) $o[] = $j; } return $o; };
$I = $read($argv[1] ?? null); $S = $read($argv[2] ?? null);
$today = gmdate('Y-m-d', time() + 19800); $log = ['date' => $today];

/* ---- intraday ---- */
$rows = array_values(array_filter($I, function ($r) { return isset($r['params']); }));
if ($rows) {
  $ok = function ($r) { return $r['trades'] >= 1000 && $r['half1_net_r'] > 0 && $r['half2_net_r'] > 0 && ($r['net_pf'] ?? 0) >= 1.05; };
  $cur = $rules['intraday']; $curRow = null;
  foreach ($rows as $r) if ($r['params']['vol_mult'] == $cur['vol_mult'] && $r['params']['th'] == $cur['th'] && $r['params']['max_trades'] == $cur['max_trades'] && !$r['params']['volp']) $curRow = $r;
  $valid = array_values(array_filter($rows, $ok));
  usort($valid, function ($a, $b) { return $b['net_r_per_trade'] <=> $a['net_r_per_trade']; });
  $best = $valid[0] ?? null;
  if ($best && (!$curRow || !$ok($curRow) || $best['net_r_per_trade'] >= $curRow['net_r_per_trade'] + 0.02)) {
    $p = $best['params']; $rules['intraday'] = ['vol_mult' => $p['vol_mult'], 'th' => $p['th'], 'max_trades' => $p['max_trades'], 'tradeable' => true];
    $log['intraday'] = 'adopted ' . $best['variant'] . ' (net ' . $best['net_r_per_trade'] . 'R/trade, profitable in both halves)';
    $ev = $best;
  } else {
    $rules['intraday']['tradeable'] = $curRow ? $ok($curRow) : false;
    $log['intraday'] = $best ? 'kept current rule (no clearly better tested setting)' : 'kept current rule; no setting made money after charges in both halves';
    $ev = $curRow ?: ($rows[0] ?? null);
  }
  $rules['intraday']['evidence'] = $ev ? array_intersect_key($ev, array_flip(['variant', 'trades', 'win_pct', 'gross_r_per_trade', 'net_r_per_trade', 'net_pf', 'half1_net_r', 'half2_net_r', 'stock_days'])) + ['tested' => $today] : null;
  $rules['intraday']['variants_tested'] = count($rows);
}

/* ---- swing ---- */
$net = array_values(array_filter($S, function ($r) { return ($r['costs'] ?? '') === 'net, Rs 2,500 positions' && !empty($r['all']); }));
if ($net) {
  $acct = function ($th) use ($S) { foreach ($S as $r) if (isset($r['slots']) && $r['slots'] == 2 && $r['th'] == $th) return $r; return null; };
  $ok = function ($r) use ($acct) { $a = $acct($r['th']);
    return $r['all']['trades'] >= 300 && ($r['first_half']['avg_ret_pct'] ?? -1) > 0 && ($r['second_half']['avg_ret_pct'] ?? -1) > 0 && $r['all']['avg_vs_nifty_pct'] > 0 && $a && $a['cagr_pct'] > $a['nifty_cagr_pct']; };
  $valid = array_values(array_filter($net, $ok));
  usort($valid, function ($a, $b) { return $b['all']['avg_ret_pct'] <=> $a['all']['avg_ret_pct']; });
  $curRow = null; foreach ($net as $r) if ($r['th'] == $rules['swing']['th']) $curRow = $r;
  $best = $valid[0] ?? null;
  if ($best && (!$curRow || !$ok($curRow) || $best['all']['avg_ret_pct'] >= $curRow['all']['avg_ret_pct'] + 0.3)) {
    $rules['swing'] = ['th' => $best['th'], 'tradeable' => true]; $ev = $best;
    $log['swing'] = 'adopted threshold ' . $best['th'] . ' (net ' . $best['all']['avg_ret_pct'] . '% a trade, beat the Nifty)';
  } else {
    $rules['swing']['tradeable'] = $curRow ? $ok($curRow) : false; $ev = $curRow ?: $net[0];
    $log['swing'] = $best ? 'kept current rule (tested edge holds)' : 'kept current rule; it did not beat the Nifty after charges in both halves';
  }
  $rules['swing']['evidence'] = ['net_2500' => $ev['all'], 'first_half' => $ev['first_half'], 'second_half' => $ev['second_half'], 'account' => $acct($ev['th']), 'tested' => $today];
}

/* ---- positional strategies from the strategy lab (tools/strategy-lab.php) ---- */
$L = $read($argv[3] ?? null);
if ($L) {
  $base = array_values(array_filter($L, function ($r) { return !empty($r['baseline']); }));
  $bar = function ($part) use ($base) { return max(array_map(function ($b) use ($part) { return $b[$part]['cagr_pct']; }, $base)); };
  $rows = array_values(array_filter($L, function ($r) { return empty($r['baseline']); }));
  /* must beat BOTH baselines (Nifty and equal-weight of the same stocks) in the tuning period AND the hold-out,
     with a drawdown a small account can live with */
  $ok = function ($r) use ($bar) { return $r['train']['cagr_pct'] > $bar('train') && $r['holdout']['cagr_pct'] > $bar('holdout') && $r['holdout']['max_dd_pct'] > -30 && $r['train']['max_dd_pct'] > -35; };
  /* robustness: the more set-ups we try, the easier a lucky one passes. A stock strategy must also beat both
     baselines on AVERAGE across all its account sizes (2, 4 and 6 positions), not only in its best one. */
  $avg = [];
  foreach ($rows as $r) { $avg[$r['strategy']]['train'][] = $r['train']['cagr_pct']; $avg[$r['strategy']]['holdout'][] = $r['holdout']['cagr_pct']; }
  $robust = function ($r) use ($avg, $bar) { $a = $avg[$r['strategy']]; if (count($a['train']) < 2) return true;
    return array_sum($a['train']) / count($a['train']) > $bar('train') && array_sum($a['holdout']) / count($a['holdout']) > $bar('holdout'); };
  $valid = array_values(array_filter($rows, function ($r) use ($ok, $robust) { return $ok($r) && $robust($r); }));
  usort($valid, function ($a, $b) { return $b['holdout']['cagr_pct'] <=> $a['holdout']['cagr_pct']; });
  $best = $valid[0] ?? null;
  /* the app can only run strategies it has code for (momentum.php); others are reported but not switched on */
  $runnable = ['Momentum rotation + market filter'];
  $validRun = array_values(array_filter($valid, function ($r) use ($runnable) { return in_array($r['strategy'], $runnable, true); }));
  if ($best && !in_array($best['strategy'], $runnable, true)) $log['positional_note'] = 'best passing strategy ' . $best['strategy'] . ' is not built into the app yet';
  $best = $validRun[0] ?? null;
  $rules['positional'] = ['tradeable' => (bool) $best, 'strategy' => $best['strategy'] ?? null, 'slots' => $best['slots'] ?? null,
    'configs_tested' => count($rows), 'strategies_tested' => count($avg), 'passed' => array_map(function ($r) { return $r['strategy'] . ' / ' . $r['slots']; }, $valid),
    'evidence' => ['tested' => $today, 'baselines' => $base, 'results' => array_map(function ($r) { return array_intersect_key($r, array_flip(['strategy', 'slots', 'index', 'train', 'holdout', 'end_value'])); }, $rows)]];
  $log['positional'] = $best ? 'best tested: ' . $best['strategy'] . ' with ' . $best['slots'] . ' positions (hold-out ' . $best['holdout']['cagr_pct'] . '% a year vs baselines ' . $bar('holdout') . '%)'
                             : 'no strategy beat buy-and-hold in both the tuning period and the hold-out';
}

/* ---- open-to-close and gap strategies (tools/open-close-study.php): recorded as evidence; none is built into the app yet ---- */
$O = array_values(array_filter($read($argv[4] ?? null), function ($r) { return isset($r['tuning'], $r['holdout']) && $r['tuning'] && $r['holdout']; }));
if ($O) {
  $okO = function ($r) { return $r['tuning']['avg_pct_per_trade'] > 0 && $r['holdout']['avg_pct_per_trade'] > 0 && ($r['holdout']['pf'] ?? 0) >= 1.05 && $r['holdout']['trades'] >= 200; };
  $pass = array_values(array_filter($O, $okO));
  $rules['intraday_hold'] = ['tested' => $today, 'passed' => array_column($pass, 'strategy'),
    'results' => array_map(function ($r) { return ['strategy' => $r['strategy'], 'tuning' => $r['tuning']['avg_pct_per_trade'], 'holdout' => $r['holdout']['avg_pct_per_trade'], 'holdout_pf' => $r['holdout']['pf']]; }, $O)];
  $log['intraday_hold'] = $pass ? 'PASSED after charges in both periods: ' . implode('; ', array_column($pass, 'strategy')) . ' (not built into the app yet)' : 'no open-to-close or gap strategy made money after charges in both periods (' . count($O) . ' tested)';
}

/* ---- core portfolio from the professional playbooks (tools/pro-lab.php) ----
   A playbook passes when, over ten years, it beat Nifty buy-and-hold in the tuning period AND the hold-out,
   fell less than the Nifty in both, earned more per unit of risk in both, and was not behind the Nifty in any
   third of the decade. Among the ones the app can run, the one with the best return per risk IN THE TUNING
   PERIOD is chosen (choosing on the hold-out would be peeking). */
$Q = $read($argv[5] ?? null);
if ($Q) {
  $nb = null; foreach ($Q as $r) if (!empty($r['baseline'])) $nb = $r;
  $rows = array_values(array_filter($Q, function ($r) { return empty($r['baseline']) && isset($r['train'], $r['holdout']); }));
  $third = function ($s) { return (float) substr(strrchr((string) $s, ' '), 1); };
  $thirdsOk = function ($r) use ($nb, $third) { if (empty($r['thirds']) || empty($nb['thirds'])) return false;
    foreach ($r['thirds'] as $i => $s) if ($third($s) < $third($nb['thirds'][$i] ?? '0 0')) return false; return true; };
  $okP = function ($r) use ($thirdsOk) { return !empty($r['beats_nifty_both']) && !empty($r['safer_than_nifty_both']) && $thirdsOk($r); };
  $RUN = [ // playbooks the app can run (core.php)
    'Equal weight: Nifty, midcap, Nasdaq, gold 25% each' => ['method' => 'fixed', 'weights' => ['NIFTY' => 0.25, 'MID' => 0.25, 'NASDAQ' => 0.25, 'GOLD' => 0.25]],
    'Risk parity: Nifty, midcap, Nasdaq, gold weighted by 1 / volatility' => ['method' => 'inv_vol', 'assets' => ['NIFTY', 'MID', 'NASDAQ', 'GOLD']],
    'Advisor growth: 50% Nifty / 20% midcap / 10% Nasdaq / 10% gold / 10% debt' => ['method' => 'fixed', 'weights' => ['NIFTY' => 0.5, 'MID' => 0.2, 'NASDAQ' => 0.1, 'GOLD' => 0.1]],
    'Advisor classic: 60% Nifty / 20% gold / 20% debt' => ['method' => 'fixed', 'weights' => ['NIFTY' => 0.6, 'GOLD' => 0.2]],
    'Risk parity without Nasdaq: Nifty, midcap, gold by 1 / volatility' => ['method' => 'inv_vol', 'assets' => ['NIFTY', 'MID', 'GOLD']],
    'Advisor growth without Nasdaq: 60% Nifty / 20% midcap / 10% gold / 10% debt' => ['method' => 'fixed', 'weights' => ['NIFTY' => 0.6, 'MID' => 0.2, 'GOLD' => 0.1]],
    'Risk parity, Nifty + gold only' => ['method' => 'inv_vol', 'assets' => ['NIFTY', 'GOLD']],
  ];
  $pass = array_values(array_filter($rows, $okP));
  usort($pass, function ($a, $b) { return $b['train']['return_per_risk'] <=> $a['train']['return_per_risk']; });
  $bestP = null; foreach ($pass as $r) if (isset($RUN[$r['strategy']])) { $bestP = $r; break; }
  $rules['portfolio'] = ['tradeable' => (bool) $bestP, 'strategy' => $bestP['strategy'] ?? null, 'school' => $bestP['school'] ?? null, 'plan' => $bestP ? $RUN[$bestP['strategy']] : null,
    'tested' => $today, 'playbooks_tested' => count($rows), 'passed' => array_column($pass, 'strategy'),
    'evidence' => ['baseline' => $nb, 'results' => array_map(function ($r) use ($okP) { return array_intersect_key($r, array_flip(['strategy', 'school', 'train', 'holdout', 'thirds', 'beats_nifty_both', 'safer_than_nifty_both', 'trades', 'costs_rs', 'end_value'])) + ['passed' => $okP($r)]; }, $rows)]];
  $log['portfolio'] = $bestP ? 'core portfolio: ' . $bestP['strategy'] . ' (hold-out ' . $bestP['holdout']['cagr_pct'] . '% a year, worst fall ' . $bestP['holdout']['max_dd_pct'] . '%, vs Nifty ' . $nb['holdout']['cagr_pct'] . '% / ' . $nb['holdout']['max_dd_pct'] . '%); ' . count($pass) . ' of ' . count($rows) . ' playbooks passed'
                             : 'no professional playbook beat the Nifty with smaller falls in both periods';
}

$rules['updated'] = $today;
array_unshift($rules['log'], $log); $rules['log'] = array_slice($rules['log'], 0, 26);
file_put_contents($file, json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo json_encode($log, JSON_UNESCAPED_UNICODE), "\n";
