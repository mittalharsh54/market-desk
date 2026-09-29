<?php
/* Do the swing rules make money after real delivery charges, and beat simply holding the Nifty?
     php tools/swing-study.php [stocks=260] [years=3]
   Replays the swing rule (daily score crosses up through the threshold; 2-ATR stop,
   4-ATR target, exit when the score turns negative or after 30 sessions) on real NSE
   daily data, then prices every trade with delivery charges for a small account and
   simulates a Rs 10,000 account. Needs internet; run it from GitHub Actions. */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../market.php';
require_once __DIR__ . '/_fetch.php';
ini_set('memory_limit', '1024M'); set_time_limit(0);

$max = (int) ($argv[1] ?? 260); $years = max(2, (int) ($argv[2] ?? 3));
$today = date('Y-m-d', time() + 19800);
$d = function ($n) use ($today) { return date('Y-m-d', strtotime("$today -$n days")); };

/* delivery charges for one round trip on a position of value $v (Rs): zero-brokerage broker,
   STT 0.1% each side, stamp 0.015% on the buy, exchange + SEBI fees with GST, DP charge on the sale */
function delivery_cost_pct($v) {
  $exch = 0.0000297; $sebi = 0.000001;
  $rs = $v * (0.001 * 2 + 0.00015 + 2 * ($exch + $sebi) * 1.18) + 15.93;
  return $rs / $v * 100;
}

$syms = array_slice(id_universe(), 0, $max);
$keys = ['NIFTY' => 'NSE_INDEX|Nifty 50'];
foreach ($syms as $s) { $k = mkt_upstox_key(mkt_norm_symbol($s) ?: $s); if ($k) $keys[$s] = $k[0]; }
$want = [];
foreach ($keys as $s => $k) for ($y = 0; $y < $years + 1; $y++)
  $want["$s|$y"] = ['url' => 'https://api.upstox.com/v2/historical-candle/' . rawurlencode($k) . '/day/' . $d($y * 365) . '/' . $d($y * 365 + 364), 'headers' => ['Accept: application/json']];
$res = study_fetch($want);
$data = [];
foreach ($keys as $s => $k) {
  $C = null;
  for ($y = 0; $y < $years + 1; $y++) { $x = $res["$s|$y"] ?? null; if ($x && $x['code'] === 200) { $P = mkt_upstox_parse($x['body']); if ($P && count($P['c'])) { $P = mk_clean($P); $C = $C ? mkt_candles_merge($P, $C) : $P; } } }
  if ($C && count($C['c']) > 400) $data[$s] = $C;
}
$N = $data['NIFTY'] ?? null; unset($data['NIFTY']);
if (!$N) { fwrite(STDERR, "no Nifty data\n"); exit(1); }
$nifty = []; foreach ($N['t'] as $i => $t) $nifty[mk_ist_date($t)] = $N['c'][$i];
$ndates = array_keys($nifty);
study_require_coverage(count($data), count($keys) - 1);
fwrite(STDERR, count($data) . " stocks, Nifty " . reset($ndates) . " → " . end($ndates) . "\n");
$first = $ndates[min(210, count($ndates) - 1)]; $mid = $ndates[(int) ((array_search($first, $ndates) + count($ndates) - 1) / 2)];

foreach (['0.30 (live rule)' => 0.30, '0.45 (stricter)' => 0.45] as $label => $th) {
  $T = [];
  foreach ($data as $s => $C) {
    $bt = mk_backtest_swing(mk_daily_arrays($C, $N), $th, 0, true);
    foreach ($bt['all'] ?? [] as $t) {
      if (!isset($nifty[$t['date']], $nifty[$t['exit']])) continue;
      $t['sym'] = $s; $t['nifty'] = ($nifty[$t['exit']] / $nifty[$t['date']] - 1) * 100; $T[] = $t;
    }
  }
  $n = count($T); if (!$n) continue;
  $stat = function (array $T, $v) {
    $n = count($T); if (!$n) return null; $c = $v ? delivery_cost_pct($v) : 0;
    $net = array_map(function ($t) use ($c) { return $t['ret'] - $c; }, $T);
    $g = array_sum(array_filter($net, function ($x) { return $x > 0; })); $l = -array_sum(array_filter($net, function ($x) { return $x < 0; }));
    return ['trades' => $n, 'win_pct' => round(count(array_filter($net, function ($x) { return $x > 0; })) / $n * 100, 1),
      'avg_ret_pct' => round(array_sum($net) / $n, 2), 'avg_vs_nifty_pct' => round((array_sum($net) - array_sum(array_column($T, 'nifty'))) / $n, 2),
      'pf' => $l > 0 ? round($g / $l, 2) : null, 'avg_days' => round(array_sum(array_column($T, 'bars')) / $n, 1)];
  };
  $H1 = array_values(array_filter($T, function ($t) use ($mid) { return $t['date'] < $mid; }));
  $H2 = array_values(array_filter($T, function ($t) use ($mid) { return $t['date'] >= $mid; }));
  foreach (['gross (no charges)' => 0, 'net, Rs 2,500 positions' => 2500, 'net, Rs 5,000 positions' => 5000] as $cl => $v)
    echo json_encode(['rule' => $label, 'th' => $th, 'costs' => $cl, 'position' => $v, 'all' => $stat($T, $v), 'first_half' => $stat($H1, $v), 'second_half' => $stat($H2, $v)]), "\n";

  /* a Rs 10,000 account: at most $slots positions, equal split of equity, strongest signal first */
  foreach ([2, 4] as $slots) {
    usort($T, function ($a, $b) { return [$a['date'], -$a['score']] <=> [$b['date'], -$b['score']]; });
    $cash = 10000.0; $open = []; $peak = 10000.0; $dd = 0.0; $taken = 0; $byDate = [];
    foreach ($T as $t) $byDate[$t['date']][] = $t;
    foreach ($ndates as $day) {
      if ($day < $first) continue;
      foreach ($open as $k => $o) if ($o['exit'] <= $day) { $cash += $o['v'] * (1 + $o['ret'] / 100) - $o['v'] * delivery_cost_pct($o['v']) / 100; unset($open[$k]); }
      $eq = $cash; foreach ($open as $o) $eq += $o['v']; $peak = max($peak, $eq); $dd = min($dd, $eq / $peak - 1);
      foreach ($byDate[$day] ?? [] as $t) {
        if (count($open) >= $slots) break;
        $held = false; foreach ($open as $o) if ($o['sym'] === $t['sym']) $held = true; if ($held) continue;
        $v = min($cash, $eq / $slots); if ($v < 1000) continue;
        $cash -= $v; $open[] = ['sym' => $t['sym'], 'v' => $v, 'ret' => $t['ret'], 'exit' => $t['exit']]; $taken++;
      }
    }
    foreach ($open as $o) $cash += $o['v'] * (1 + $o['ret'] / 100) - $o['v'] * delivery_cost_pct($o['v']) / 100;
    $yrs = (strtotime(end($ndates)) - strtotime($first)) / (365.25 * 86400);
    $nb = $nifty[end($ndates)] / $nifty[$first];
    echo json_encode(['rule' => $label, 'th' => $th, 'slots' => $slots, 'account' => "Rs 10,000, up to $slots positions", 'from' => $first, 'to' => end($ndates), 'trades' => $taken,
      'end_value' => round($cash), 'cagr_pct' => round((pow($cash / 10000, 1 / $yrs) - 1) * 100, 1), 'max_drawdown_closed_trades_pct' => round($dd * 100, 1),
      'nifty_buy_hold_end_value' => round(10000 * $nb), 'nifty_cagr_pct' => round((pow($nb, 1 / $yrs) - 1) * 100, 1)]), "\n";
  }
}
