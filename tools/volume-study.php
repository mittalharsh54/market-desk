<?php
/* Does the volume rule earn its place? Replays the live intraday rules day by day
   on real 5-minute NSE data (Upstox), with and without the volume checks.
     php tools/volume-study.php [stocks=120] [shard=0] [shards=1]
   Prints one JSON line per variant with trades, win rate, average R, profit
   factor and net rupees after charges. Needs internet; run it from GitHub Actions. */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../market.php';
ini_set('memory_limit', '1024M'); set_time_limit(0);

$max = (int) ($argv[1] ?? 120); $shard = (int) ($argv[2] ?? 0); $shards = max(1, (int) ($argv[3] ?? 1));
$VARIANTS = [
  'no volume rules (before)'        => [false, 0],
  'volume pressure factor only'     => [true, 0],
  'factor + gate 1.0x'              => [true, 1.0],
  'factor + gate 1.2x (live now)'   => [true, 1.2],
  'gate 1.2x only'                  => [false, 1.2],
  'factor + gate 1.5x'              => [true, 1.5],
];

$today = date('Y-m-d', time() + 19800);
$d = function ($n) use ($today) { return date('Y-m-d', strtotime("$today -$n days")); };
$syms = array_slice(id_universe(), 0, $max);
$syms = array_values(array_filter($syms, function ($s, $k) use ($shard, $shards) { return $k % $shards === $shard; }, ARRAY_FILTER_USE_BOTH));

/* 5-minute candles, two one-month requests per stock, plus the Nifty for relative strength */
$want = []; $keys = ['NIFTY' => 'NSE_INDEX|Nifty 50'];
foreach ($syms as $s) { $k = mkt_upstox_key(mkt_norm_symbol($s) ?: $s); if ($k) $keys[$s] = $k[0]; }
foreach ($keys as $s => $k) foreach ([[0, 29], [30, 59]] as $j => $r)
  $want["$s|$j"] = ['url' => 'https://api.upstox.com/v3/historical-candle/' . rawurlencode($k) . '/minutes/5/' . $d($r[0]) . '/' . $d($r[1]), 'headers' => ['Accept: application/json']];
$res = mkt_http_multi($want, 30, 6);
$data = [];
foreach ($keys as $s => $k) {
  $parts = [];
  foreach ([0, 1] as $j) { $x = $res["$s|$j"] ?? null; if ($x && $x['code'] === 200) { $C = mkt_upstox_parse($x['body']); if ($C) $parts[] = mk_clean($C); } }
  if (!$parts) continue; $C = count($parts) === 2 ? mkt_candles_merge($parts[0], $parts[1]) : $parts[0];
  if (count($C['c']) > 75 * 8) $data[$s] = $C;
}
$bench = $data['NIFTY'] ?? null; unset($data['NIFTY']);
fwrite(STDERR, count($data) . " stocks with data (shard $shard/$shards)\n");

function slice_c(array $C, $a, $b) { $o = []; foreach ($C as $k => $v) $o[$k] = array_slice($v, $a, $b - $a + 1); return $o; }
function align_bench(array $C, array $B = null) { // Nifty bars on the stock's timestamps
  if (!$B) return null; $ix = array_flip($B['t']); $o = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
  foreach ($C['t'] as $t) { if (!isset($ix[$t])) return null; $j = $ix[$t]; foreach ($o as $k => $_) $o[$k][] = $B[$k][$j]; }
  return $o;
}

$out = [];
foreach ($VARIANTS as $name => $_) $out[$name] = ['trades' => [], 'days' => 0];
foreach ($data as $s => $C) {
  $day = []; foreach ($C['t'] as $i => $t) $day[$i] = gmdate('Y-m-d', $t + MK_IST);
  $starts = []; foreach ($day as $i => $dd) if ($i === 0 || $day[$i - 1] !== $dd) $starts[] = $i;
  for ($k = 6; $k < count($starts); $k++) {           // 6 earlier sessions for warm-up, like the live page
    $a = $starts[$k - 6]; $b = ($starts[$k + 1] ?? count($C['t'])) - 1;
    $W = slice_c($C, $a, $b); $BW = align_bench($W, $bench);
    foreach ($VARIANTS as $name => $v) {
      $GLOBALS['MK_VOLP'] = $v[0]; $GLOBALS['MK_VOL_MULT'] = $v[1];
      $R = mk_intraday_replay($W, $BW, ['now' => end($W['t']) + 3600, 'bias' => 'BOTH', 'capital' => 100000, 'risk_pct' => 1]);
      $out[$name]['days']++;
      foreach ($R['trades'] as $t) $out[$name]['trades'][] = ['r' => $t['r'], 'pnl' => $t['pnl'], 'half' => $k < count($starts) / 2 + 3 ? 1 : 2];
    }
  }
}
foreach ($out as $name => $o) {
  $T = $o['trades']; $row = ['variant' => $name, 'shard' => $shard, 'stock_days' => $o['days'], 'trades' => count($T)];
  $row['wins'] = count(array_filter($T, function ($t) { return $t['pnl'] > 0; }));
  $row['sum_r'] = round(array_sum(array_column($T, 'r')), 2);
  $row['gain_r'] = round(array_sum(array_filter(array_column($T, 'r'), function ($x) { return $x > 0; })), 2);
  $row['loss_r'] = round(-array_sum(array_filter(array_column($T, 'r'), function ($x) { return $x < 0; })), 2);
  $row['net_pnl'] = array_sum(array_column($T, 'pnl'));
  foreach ([1, 2] as $h) { $H = array_filter($T, function ($t) use ($h) { return $t['half'] === $h; }); $row["half{$h}_trades"] = count($H); $row["half{$h}_r"] = round(array_sum(array_column($H, 'r')), 2); }
  echo json_encode($row), "\n";
}
