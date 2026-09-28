<?php
/* =====================================================================
   Market Desk — analysis engine (market_engine.php)
   ---------------------------------------------------------------------
   Pure maths, no I/O: every function takes arrays and returns arrays, so
   the whole engine is testable offline (tools/test-market-engine.php).
   market.php does the fetching and hands the candles in here.

   Candles are column arrays: ['t'=>[unix], 'o'=>[], 'h'=>[], 'l'=>[], 'c'=>[], 'v'=>[]].
   Indicator series are aligned with the candles; a warm-up bar is null.

   Scores are always in [-1, +1]: +1 = strongly bullish for the stock (or for
   Indian equities, in the macro engine), -1 = strongly bearish. Every score
   comes with the plain-English reason it was given, so the page can show its
   working instead of a bare BUY/SELL.

   Nothing here is financial advice: these are rule-based probabilities, and
   the backtests are there so a user can see how often the rules were right.
   ===================================================================== */

const MK_IST = 19800; // seconds east of UTC

/* ---------- small helpers ---------- */
function mk_clamp($x, $lo = -1.0, $hi = 1.0) { return max($lo, min($hi, $x)); }
function mk_last(array $a, $back = 0) { $n = count($a); return $n > $back ? $a[$n - 1 - $back] : null; }
function mk_round($x, $d = 2) { return $x === null ? null : round((float) $x, $d); }
function mk_pct($a, $b) { return ($a === null || $b === null || (float) $b == 0.0) ? null : ($a / $b - 1) * 100; }
function mk_ist_date($t) { return gmdate('Y-m-d', (int) $t + MK_IST); }
function mk_ist_min($t) { $s = ((int) $t + MK_IST) % 86400; return intdiv($s, 60); } // minutes since IST midnight
function mk_mean(array $a) { $a = array_values(array_filter($a, 'is_numeric')); return $a ? array_sum($a) / count($a) : null; }
function mk_stdev(array $a) {
  $a = array_values(array_filter($a, 'is_numeric')); $n = count($a);
  if ($n < 2) return null;
  $m = array_sum($a) / $n; $s = 0; foreach ($a as $x) $s += ($x - $m) * ($x - $m);
  return sqrt($s / ($n - 1));
}
function mk_slice_valid(array $a, $from, $len) { return array_values(array_filter(array_slice($a, $from, $len), 'is_numeric')); }

/* Drop bars Yahoo sends with a null close (halts, the half-built bar) so every
   indicator can assume numbers. Open/high/low fall back to the close. */
function mk_clean(array $C) {
  $o = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
  $n = isset($C['c']) ? count($C['c']) : 0;
  for ($i = 0; $i < $n; $i++) {
    $c = $C['c'][$i];
    if (!is_numeric($c) || $c <= 0) continue;
    $c = (float) $c;
    $h = is_numeric($C['h'][$i] ?? null) ? (float) $C['h'][$i] : $c;
    $l = is_numeric($C['l'][$i] ?? null) ? (float) $C['l'][$i] : $c;
    $op = is_numeric($C['o'][$i] ?? null) ? (float) $C['o'][$i] : $c;
    $o['t'][] = (int) $C['t'][$i]; $o['o'][] = $op; $o['h'][] = max($h, $c, $op); $o['l'][] = min($l, $c, $op);
    $o['c'][] = $c; $o['v'][] = is_numeric($C['v'][$i] ?? null) ? (float) $C['v'][$i] : 0.0;
  }
  return $o;
}

/* Build 15-minute (or any multiple) bars out of 5-minute ones, per IST session. */
function mk_resample(array $C, $mult) {
  $o = ['t' => [], 'o' => [], 'h' => [], 'l' => [], 'c' => [], 'v' => []];
  $n = count($C['c']); $key = null; $k = -1;
  for ($i = 0; $i < $n; $i++) {
    $bucket = mk_ist_date($C['t'][$i]) . ':' . intdiv(mk_ist_min($C['t'][$i]) - 555, 5 * $mult); // 555 = 9:15
    if ($bucket !== $key) {
      $key = $bucket; $k++;
      $o['t'][$k] = $C['t'][$i]; $o['o'][$k] = $C['o'][$i]; $o['h'][$k] = $C['h'][$i]; $o['l'][$k] = $C['l'][$i]; $o['c'][$k] = $C['c'][$i]; $o['v'][$k] = $C['v'][$i];
    } else {
      $o['h'][$k] = max($o['h'][$k], $C['h'][$i]); $o['l'][$k] = min($o['l'][$k], $C['l'][$i]); $o['c'][$k] = $C['c'][$i]; $o['v'][$k] += $C['v'][$i];
    }
  }
  return $o;
}

/* ---------- indicators ---------- */
function mk_sma(array $a, $n) {
  $cnt = count($a); $out = array_fill(0, $cnt, null); $s = 0.0; $run = 0;
  for ($i = 0; $i < $cnt; $i++) {
    if ($a[$i] === null) { $s = 0.0; $run = 0; continue; }
    $s += $a[$i]; $run++;
    if ($run > $n) { $s -= $a[$i - $n]; $run = $n; }
    if ($run === $n) $out[$i] = $s / $n;
  }
  return $out;
}
function mk_ema(array $a, $n) {
  $cnt = count($a); $out = array_fill(0, $cnt, null); $k = 2 / ($n + 1); $prev = null; $run = 0; $s = 0.0;
  for ($i = 0; $i < $cnt; $i++) {
    if ($a[$i] === null) { $prev = null; $run = 0; $s = 0.0; continue; }
    if ($prev === null) {
      $s += $a[$i]; $run++;
      if ($run === $n) { $prev = $s / $n; $out[$i] = $prev; }
      continue;
    }
    $prev = $a[$i] * $k + $prev * (1 - $k); $out[$i] = $prev;
  }
  return $out;
}
/* Wilder smoothing (RSI, ATR, ADX use it) */
function mk_wilder(array $a, $n) {
  $cnt = count($a); $out = array_fill(0, $cnt, null); $prev = null; $run = 0; $s = 0.0;
  for ($i = 0; $i < $cnt; $i++) {
    if ($a[$i] === null) continue;
    if ($prev === null) { $s += $a[$i]; $run++; if ($run === $n) { $prev = $s / $n; $out[$i] = $prev; } continue; }
    $prev = ($prev * ($n - 1) + $a[$i]) / $n; $out[$i] = $prev;
  }
  return $out;
}
function mk_rsi(array $c, $n = 14) {
  $cnt = count($c); $g = array_fill(0, $cnt, null); $l = array_fill(0, $cnt, null);
  for ($i = 1; $i < $cnt; $i++) { $d = $c[$i] - $c[$i - 1]; $g[$i] = max($d, 0); $l[$i] = max(-$d, 0); }
  $ag = mk_wilder($g, $n); $al = mk_wilder($l, $n); $out = array_fill(0, $cnt, null);
  for ($i = 0; $i < $cnt; $i++) {
    if ($ag[$i] === null) continue;
    $out[$i] = $al[$i] == 0 ? ($ag[$i] == 0 ? 50.0 : 100.0) : 100 - 100 / (1 + $ag[$i] / $al[$i]);
  }
  return $out;
}
function mk_macd(array $c, $f = 12, $s = 26, $sig = 9) {
  $ef = mk_ema($c, $f); $es = mk_ema($c, $s); $cnt = count($c); $m = array_fill(0, $cnt, null);
  for ($i = 0; $i < $cnt; $i++) if ($ef[$i] !== null && $es[$i] !== null) $m[$i] = $ef[$i] - $es[$i];
  $sg = mk_ema($m, $sig); $h = array_fill(0, $cnt, null);
  for ($i = 0; $i < $cnt; $i++) if ($m[$i] !== null && $sg[$i] !== null) $h[$i] = $m[$i] - $sg[$i];
  return ['macd' => $m, 'signal' => $sg, 'hist' => $h];
}
function mk_boll(array $c, $n = 20, $k = 2.0) {
  $mid = mk_sma($c, $n); $cnt = count($c);
  $up = $lo = $pb = $bw = array_fill(0, $cnt, null);
  for ($i = $n - 1; $i < $cnt; $i++) {
    if ($mid[$i] === null) continue;
    $s = 0.0; for ($j = $i - $n + 1; $j <= $i; $j++) $s += ($c[$j] - $mid[$i]) ** 2;
    $sd = sqrt($s / $n); $up[$i] = $mid[$i] + $k * $sd; $lo[$i] = $mid[$i] - $k * $sd;
    $pb[$i] = ($up[$i] - $lo[$i]) > 0 ? ($c[$i] - $lo[$i]) / ($up[$i] - $lo[$i]) : 0.5;
    $bw[$i] = $mid[$i] > 0 ? ($up[$i] - $lo[$i]) / $mid[$i] * 100 : null;
  }
  return ['mid' => $mid, 'up' => $up, 'lo' => $lo, 'pctb' => $pb, 'bw' => $bw];
}
function mk_tr(array $C) {
  $n = count($C['c']); $tr = array_fill(0, $n, null);
  for ($i = 0; $i < $n; $i++) {
    $hl = $C['h'][$i] - $C['l'][$i];
    $tr[$i] = $i === 0 ? $hl : max($hl, abs($C['h'][$i] - $C['c'][$i - 1]), abs($C['l'][$i] - $C['c'][$i - 1]));
  }
  return $tr;
}
function mk_atr(array $C, $n = 14) { return mk_wilder(mk_tr($C), $n); }
function mk_adx(array $C, $n = 14) {
  $cnt = count($C['c']); $pdm = $mdm = array_fill(0, $cnt, null); $tr = mk_tr($C);
  for ($i = 1; $i < $cnt; $i++) {
    $up = $C['h'][$i] - $C['h'][$i - 1]; $dn = $C['l'][$i - 1] - $C['l'][$i];
    $pdm[$i] = ($up > $dn && $up > 0) ? $up : 0; $mdm[$i] = ($dn > $up && $dn > 0) ? $dn : 0;
  }
  $tr[0] = null;
  $str = mk_wilder($tr, $n); $sp = mk_wilder($pdm, $n); $sm = mk_wilder($mdm, $n);
  $pdi = $mdi = $dx = array_fill(0, $cnt, null);
  for ($i = 0; $i < $cnt; $i++) {
    if ($str[$i] === null || $str[$i] == 0) continue;
    $pdi[$i] = 100 * $sp[$i] / $str[$i]; $mdi[$i] = 100 * $sm[$i] / $str[$i];
    $den = $pdi[$i] + $mdi[$i]; $dx[$i] = $den > 0 ? 100 * abs($pdi[$i] - $mdi[$i]) / $den : 0;
  }
  return ['adx' => mk_wilder($dx, $n), 'pdi' => $pdi, 'mdi' => $mdi];
}
/* Supertrend: dir +1 = uptrend (line is support below price), -1 = downtrend */
function mk_supertrend(array $C, $n = 10, $mult = 3.0) {
  $cnt = count($C['c']); $atr = mk_atr($C, $n); $line = $dir = array_fill(0, $cnt, null);
  $fu = $fl = null; $d = 1;
  for ($i = 0; $i < $cnt; $i++) {
    if ($atr[$i] === null) continue;
    $hl2 = ($C['h'][$i] + $C['l'][$i]) / 2; $bu = $hl2 + $mult * $atr[$i]; $bl = $hl2 - $mult * $atr[$i];
    if ($fu === null) { $fu = $bu; $fl = $bl; $d = $C['c'][$i] >= $hl2 ? 1 : -1; }
    else {
      $pc = $C['c'][$i - 1];
      $fu = ($bu < $fu || $pc > $fu) ? $bu : $fu;
      $fl = ($bl > $fl || $pc < $fl) ? $bl : $fl;
      if ($d === 1 && $C['c'][$i] < $fl) $d = -1; elseif ($d === -1 && $C['c'][$i] > $fu) $d = 1;
    }
    $dir[$i] = $d; $line[$i] = $d === 1 ? $fl : $fu;
  }
  return ['line' => $line, 'dir' => $dir];
}
function mk_stoch(array $C, $n = 14, $d = 3) {
  $cnt = count($C['c']); $k = array_fill(0, $cnt, null);
  for ($i = $n - 1; $i < $cnt; $i++) {
    $hh = max(array_slice($C['h'], $i - $n + 1, $n)); $ll = min(array_slice($C['l'], $i - $n + 1, $n));
    $k[$i] = $hh > $ll ? 100 * ($C['c'][$i] - $ll) / ($hh - $ll) : 50;
  }
  return ['k' => $k, 'd' => mk_sma($k, $d)];
}
function mk_obv(array $C) {
  $cnt = count($C['c']); $o = array_fill(0, $cnt, 0.0);
  for ($i = 1; $i < $cnt; $i++) $o[$i] = $o[$i - 1] + ($C['c'][$i] > $C['c'][$i - 1] ? $C['v'][$i] : ($C['c'][$i] < $C['c'][$i - 1] ? -$C['v'][$i] : 0));
  return $o;
}
function mk_mfi(array $C, $n = 14) {
  $cnt = count($C['c']); $out = array_fill(0, $cnt, null); $tp = [];
  for ($i = 0; $i < $cnt; $i++) $tp[$i] = ($C['h'][$i] + $C['l'][$i] + $C['c'][$i]) / 3;
  for ($i = $n; $i < $cnt; $i++) {
    $p = $m = 0.0;
    for ($j = $i - $n + 1; $j <= $i; $j++) { $f = $tp[$j] * $C['v'][$j]; if ($tp[$j] > $tp[$j - 1]) $p += $f; elseif ($tp[$j] < $tp[$j - 1]) $m += $f; }
    $out[$i] = ($p + $m) == 0 ? 50.0 : ($m == 0 ? 100.0 : 100 - 100 / (1 + $p / $m));
  }
  return $out;
}
function mk_cci(array $C, $n = 20) {
  $cnt = count($C['c']); $tp = []; $out = array_fill(0, $cnt, null);
  for ($i = 0; $i < $cnt; $i++) $tp[$i] = ($C['h'][$i] + $C['l'][$i] + $C['c'][$i]) / 3;
  $sm = mk_sma($tp, $n);
  for ($i = $n - 1; $i < $cnt; $i++) {
    $md = 0.0; for ($j = $i - $n + 1; $j <= $i; $j++) $md += abs($tp[$j] - $sm[$i]); $md /= $n;
    $out[$i] = $md > 0 ? ($tp[$i] - $sm[$i]) / (0.015 * $md) : 0.0;
  }
  return $out;
}
function mk_willr(array $C, $n = 14) {
  $cnt = count($C['c']); $out = array_fill(0, $cnt, null);
  for ($i = $n - 1; $i < $cnt; $i++) {
    $hh = max(array_slice($C['h'], $i - $n + 1, $n)); $ll = min(array_slice($C['l'], $i - $n + 1, $n));
    $out[$i] = $hh > $ll ? -100 * ($hh - $C['c'][$i]) / ($hh - $ll) : -50;
  }
  return $out;
}
/* Session VWAP, reset each IST day. Indices report no volume, so they fall back
   to the equal-weighted average price (TWAP) and say so. */
function mk_vwap(array $C) {
  $cnt = count($C['c']); $out = array_fill(0, $cnt, null); $day = null; $pv = $vv = 0.0; $tpSum = 0.0; $k = 0; $hasVol = false;
  for ($i = 0; $i < $cnt; $i++) { if ($C['v'][$i] > 0) { $hasVol = true; break; } }
  for ($i = 0; $i < $cnt; $i++) {
    $d = mk_ist_date($C['t'][$i]);
    if ($d !== $day) { $day = $d; $pv = $vv = 0.0; $tpSum = 0.0; $k = 0; }
    $tp = ($C['h'][$i] + $C['l'][$i] + $C['c'][$i]) / 3;
    $pv += $tp * $C['v'][$i]; $vv += $C['v'][$i]; $tpSum += $tp; $k++;
    $out[$i] = ($hasVol && $vv > 0) ? $pv / $vv : $tpSum / $k;
  }
  return ['vwap' => $out, 'volume_weighted' => $hasVol];
}
/* daily log returns */
function mk_returns(array $c) { $r = []; for ($i = 1; $i < count($c); $i++) $r[] = $c[$i - 1] > 0 ? log($c[$i] / $c[$i - 1]) : 0; return $r; }
function mk_beta(array $rs, array $rm) {
  $n = min(count($rs), count($rm)); if ($n < 30) return null;
  $rs = array_slice($rs, -$n); $rm = array_slice($rm, -$n);
  $ms = array_sum($rs) / $n; $mm = array_sum($rm) / $n; $cov = $var = $vs = 0.0;
  for ($i = 0; $i < $n; $i++) { $cov += ($rs[$i] - $ms) * ($rm[$i] - $mm); $var += ($rm[$i] - $mm) ** 2; $vs += ($rs[$i] - $ms) ** 2; }
  return ['beta' => $var > 0 ? $cov / $var : null, 'corr' => ($var > 0 && $vs > 0) ? $cov / sqrt($var * $vs) : null];
}
/* align a stock's closes with the benchmark's by IST date, for beta and relative strength */
function mk_align(array $A, array $B) {
  $mb = []; for ($i = 0; $i < count($B['c']); $i++) $mb[mk_ist_date($B['t'][$i])] = $B['c'][$i];
  $a = $b = [];
  for ($i = 0; $i < count($A['c']); $i++) { $d = mk_ist_date($A['t'][$i]); if (isset($mb[$d])) { $a[] = $A['c'][$i]; $b[] = $mb[$d]; } }
  return [$a, $b];
}
function mk_max_drawdown(array $c) {
  $peak = null; $dd = 0.0;
  foreach ($c as $x) { if ($peak === null || $x > $peak) $peak = $x; if ($peak > 0) $dd = min($dd, $x / $peak - 1); }
  return $dd * 100;
}
/* Classic floor pivots + Central Pivot Range from the previous session */
function mk_pivots($h, $l, $c) {
  $p = ($h + $l + $c) / 3; $bc = ($h + $l) / 2; $tc = 2 * $p - $bc;
  if ($tc < $bc) { $t = $tc; $tc = $bc; $bc = $t; }
  return ['P' => $p, 'TC' => $tc, 'BC' => $bc, 'R1' => 2 * $p - $l, 'S1' => 2 * $p - $h, 'R2' => $p + ($h - $l), 'S2' => $p - ($h - $l),
          'R3' => $h + 2 * ($p - $l), 'S3' => $l - 2 * ($h - $p), 'cpr_width_pct' => $p > 0 ? ($tc - $bc) / $p * 100 : null];
}
/* Swing highs/lows (fractals), then clustered into support/resistance zones.
   A level touched more often ranks higher. */
function mk_levels(array $C, $k = 3, $lookback = 250) {
  $n = count($C['c']); $from = max($k, $n - $lookback); $pts = [];
  for ($i = $from; $i < $n - $k; $i++) {
    $isH = $isL = true;
    for ($j = $i - $k; $j <= $i + $k; $j++) {
      if ($j === $i) continue;
      if ($C['h'][$j] > $C['h'][$i]) $isH = false;
      if ($C['l'][$j] < $C['l'][$i]) $isL = false;
    }
    if ($isH) $pts[] = ['p' => $C['h'][$i], 'i' => $i, 'k' => 'H'];
    if ($isL) $pts[] = ['p' => $C['l'][$i], 'i' => $i, 'k' => 'L'];
  }
  $price = $C['c'][$n - 1]; $atr = mk_last(mk_atr($C, 14)) ?: $price * 0.02; $tol = max($atr * 0.6, $price * 0.004);
  usort($pts, function ($a, $b) { return $a['p'] <=> $b['p']; });
  $zones = [];
  foreach ($pts as $p) {
    $z = count($zones) - 1;
    if ($z >= 0 && abs($p['p'] - $zones[$z]['level']) <= $tol) {
      $zones[$z]['sum'] += $p['p']; $zones[$z]['touches']++; $zones[$z]['level'] = $zones[$z]['sum'] / $zones[$z]['touches'];
      $zones[$z]['last'] = max($zones[$z]['last'], $p['i']);
    } else $zones[] = ['level' => $p['p'], 'sum' => $p['p'], 'touches' => 1, 'last' => $p['i']];
  }
  $sup = $res = [];
  foreach ($zones as $z) {
    $row = ['level' => round($z['level'], 2), 'touches' => $z['touches'], 'bars_ago' => $n - 1 - $z['last'], 'dist_pct' => round(($z['level'] / $price - 1) * 100, 2)];
    if ($z['level'] < $price) $sup[] = $row; else $res[] = $row;
  }
  usort($sup, function ($a, $b) { return $b['level'] <=> $a['level']; });
  usort($res, function ($a, $b) { return $a['level'] <=> $b['level']; });
  $swH = array_values(array_filter($pts, function ($p) { return $p['k'] === 'H'; }));
  $swL = array_values(array_filter($pts, function ($p) { return $p['k'] === 'L'; }));
  usort($swH, function ($a, $b) { return $a['i'] <=> $b['i']; }); usort($swL, function ($a, $b) { return $a['i'] <=> $b['i']; });
  return ['support' => array_slice($sup, 0, 4), 'resistance' => array_slice($res, 0, 4), 'swing_highs' => array_slice($swH, -4), 'swing_lows' => array_slice($swL, -4)];
}
/* Higher highs + higher lows = uptrend; lower highs + lower lows = downtrend */
function mk_structure(array $lv, $price = null) {
  $H = $lv['swing_highs']; $L = $lv['swing_lows'];
  if (count($H) < 2 || count($L) < 2) return ['label' => 'Not enough swings', 'score' => 0];
  /* a close through the latest swing point overrides the older pattern */
  if ($price !== null && $price < $L[count($L) - 1]['p'] && $price < $L[count($L) - 2]['p']) return ['label' => 'Broke below recent swing lows (downtrend)', 'score' => -1];
  if ($price !== null && $price > $H[count($H) - 1]['p'] && $price > $H[count($H) - 2]['p']) return ['label' => 'Broke above recent swing highs (uptrend)', 'score' => 1];
  $hh = $H[count($H) - 1]['p'] > $H[count($H) - 2]['p']; $hl = $L[count($L) - 1]['p'] > $L[count($L) - 2]['p'];
  if ($hh && $hl) return ['label' => 'Higher highs & higher lows (uptrend)', 'score' => 1];
  if (!$hh && !$hl) return ['label' => 'Lower highs & lower lows (downtrend)', 'score' => -1];
  if ($hh && !$hl) return ['label' => 'Expanding range (volatile)', 'score' => 0];
  return ['label' => 'Contracting range (coiling)', 'score' => 0];
}
/* Candlestick patterns on the last completed bar(s) */
function mk_patterns(array $C, $i = null) {
  $n = count($C['c']); if ($i === null) $i = $n - 1; if ($i < 2) return [];
  $o = $C['o']; $h = $C['h']; $l = $C['l']; $c = $C['c']; $out = [];
  $body = abs($c[$i] - $o[$i]); $rng = max($h[$i] - $l[$i], 1e-9);
  $up = $h[$i] - max($o[$i], $c[$i]); $dn = min($o[$i], $c[$i]) - $l[$i];
  $prevDown = $c[$i - 1] < $o[$i - 1]; $prevUp = $c[$i - 1] > $o[$i - 1];
  $trendDown = $c[$i - 1] < $c[max(0, $i - 6)]; $trendUp = $c[$i - 1] > $c[max(0, $i - 6)];
  if ($body / $rng < 0.1) $out[] = ['name' => 'Doji', 'bias' => 0, 'note' => 'Indecision; watch the next candle.'];
  if ($dn >= 2 * $body && $up <= $body * 0.6 && $body / $rng > 0.08) {
    $out[] = $trendDown ? ['name' => 'Hammer', 'bias' => 1, 'note' => 'Buyers rejected lower prices after a fall.']
                        : ['name' => 'Hanging man', 'bias' => -1, 'note' => 'Selling pressure appearing after a rise.'];
  }
  if ($up >= 2 * $body && $dn <= $body * 0.6 && $body / $rng > 0.08) {
    $out[] = $trendUp ? ['name' => 'Shooting star', 'bias' => -1, 'note' => 'Sellers rejected higher prices after a rise.']
                      : ['name' => 'Inverted hammer', 'bias' => 1, 'note' => 'Early buying interest after a fall.'];
  }
  if ($prevDown && $c[$i] > $o[$i] && $o[$i] <= $c[$i - 1] && $c[$i] >= $o[$i - 1]) $out[] = ['name' => 'Bullish engulfing', 'bias' => 1, 'note' => 'Buyers overwhelmed the prior down candle.'];
  if ($prevUp && $c[$i] < $o[$i] && $o[$i] >= $c[$i - 1] && $c[$i] <= $o[$i - 1]) $out[] = ['name' => 'Bearish engulfing', 'bias' => -1, 'note' => 'Sellers overwhelmed the prior up candle.'];
  if ($h[$i] <= $h[$i - 1] && $l[$i] >= $l[$i - 1]) $out[] = ['name' => 'Inside bar', 'bias' => 0, 'note' => 'Consolidation; a break of the mother bar sets direction.'];
  if ($body / $rng > 0.9) $out[] = ['name' => ($c[$i] > $o[$i] ? 'Bullish' : 'Bearish') . ' marubozu', 'bias' => $c[$i] > $o[$i] ? 1 : -1, 'note' => 'One side in control all session.'];
  $b2 = abs($c[$i - 1] - $o[$i - 1]); $b3 = abs($c[$i - 2] - $o[$i - 2]);
  if ($c[$i - 2] < $o[$i - 2] && $b2 < $b3 * 0.4 && $c[$i] > $o[$i] && $c[$i] > ($o[$i - 2] + $c[$i - 2]) / 2) $out[] = ['name' => 'Morning star', 'bias' => 1, 'note' => 'Three-candle bullish reversal.'];
  if ($c[$i - 2] > $o[$i - 2] && $b2 < $b3 * 0.4 && $c[$i] < $o[$i] && $c[$i] < ($o[$i - 2] + $c[$i - 2]) / 2) $out[] = ['name' => 'Evening star', 'bias' => -1, 'note' => 'Three-candle bearish reversal.'];
  return $out;
}

/* =====================================================================
   DAILY TECHNICALS — one set of indicator arrays, scored at any bar.
   Scoring at any bar (not just the last) is what lets the backtest replay
   the exact same rules over history without peeking ahead: every
   indicator here only uses bars up to i.
   ===================================================================== */
function mk_daily_arrays(array $C, array $bench = null) {
  $c = $C['c'];
  $A = ['C' => $C, 'n' => count($c),
    'sma20' => mk_sma($c, 20), 'sma50' => mk_sma($c, 50), 'sma200' => mk_sma($c, 200),
    'ema9' => mk_ema($c, 9), 'ema21' => mk_ema($c, 21),
    'rsi' => mk_rsi($c, 14), 'macd' => mk_macd($c), 'boll' => mk_boll($c), 'atr' => mk_atr($C, 14),
    'adx' => mk_adx($C, 14), 'st' => mk_supertrend($C, 10, 3), 'stoch' => mk_stoch($C), 'obv' => mk_obv($C),
    'mfi' => mk_mfi($C), 'cci' => mk_cci($C), 'willr' => mk_willr($C), 'vsma20' => mk_sma($C['v'], 20)];
  /* benchmark closes aligned by date, for relative strength at each bar */
  $A['bench'] = null;
  if ($bench && count($bench['c']) > 30) {
    $mb = []; for ($i = 0; $i < count($bench['c']); $i++) $mb[mk_ist_date($bench['t'][$i])] = $bench['c'][$i];
    $b = []; $last = null;
    for ($i = 0; $i < $A['n']; $i++) { $d = mk_ist_date($C['t'][$i]); if (isset($mb[$d])) $last = $mb[$d]; $b[$i] = $last; }
    $A['bench'] = $b;
  }
  return $A;
}
function mk_ret_at(array $c, $i, $bars) { return ($i - $bars >= 0 && $c[$i - $bars] > 0 && $c[$i - $bars] !== null && $c[$i] !== null) ? $c[$i] / $c[$i - $bars] - 1 : null; }

/* The technical score at bar i: 10 factors, weighted to 1.0 */
function mk_tech_score_at(array $A, $i) {
  $C = $A['C']; $c = $C['c']; $px = $c[$i]; $F = [];
  $add = function ($key, $label, $w, $score, $value, $note) use (&$F) {
    if ($score === null) return;
    $F[] = ['key' => $key, 'label' => $label, 'weight' => $w, 'score' => round(mk_clamp($score), 3), 'value' => $value, 'note' => $note];
  };
  /* 1 — moving-average trend */
  $s50 = $A['sma50'][$i]; $s200 = $A['sma200'][$i];
  if ($s50 !== null) {
    $s = ($px > $s50 ? 0.3 : -0.3); $bits = [$px > $s50 ? 'above 50-DMA' : 'below 50-DMA'];
    if ($s200 !== null) {
      $s += ($px > $s200 ? 0.35 : -0.35); $bits[] = $px > $s200 ? 'above 200-DMA' : 'below 200-DMA';
      $s += ($s50 > $s200 ? 0.2 : -0.2); $bits[] = $s50 > $s200 ? '50>200 (golden-cross regime)' : '50<200 (death-cross regime)';
    } else $s *= 1.5;
    $prev = $A['sma50'][$i - 10] ?? null;
    if ($prev) { $sl = $s50 / $prev - 1; $s += $sl > 0 ? 0.15 : -0.15; $bits[] = '50-DMA ' . ($sl > 0 ? 'rising' : 'falling'); }
    $add('ma', 'Moving-average trend', 0.18, $s, $s200 !== null ? round(($px / $s200 - 1) * 100, 1) . '% vs 200-DMA' : round(($px / $s50 - 1) * 100, 1) . '% vs 50-DMA', 'Price ' . implode(', ', $bits) . '.');
  }
  /* 2 — ADX trend strength & direction */
  $adx = $A['adx']['adx'][$i]; $pdi = $A['adx']['pdi'][$i]; $mdi = $A['adx']['mdi'][$i];
  if ($adx !== null) {
    $dirn = $pdi >= $mdi ? 1 : -1; $str = mk_clamp(($adx - 15) / 25, 0, 1);
    $add('adx', 'ADX trend strength', 0.08, $dirn * $str, round($adx, 1), $adx < 20 ? 'ADX ' . round($adx) . ': no real trend — range-bound, signals less reliable.'
      : 'ADX ' . round($adx) . ' with ' . ($dirn > 0 ? '+DI above -DI: buyers driving the trend.' : '-DI above +DI: sellers driving the trend.'));
  }
  /* 3 — Supertrend */
  $st = $A['st']['dir'][$i];
  if ($st !== null) $add('st', 'Supertrend (10,3)', 0.10, $st, round($A['st']['line'][$i], 2), $st > 0 ? 'In buy mode; trailing support at ' . round($A['st']['line'][$i], 2) . '.' : 'In sell mode; overhead resistance at ' . round($A['st']['line'][$i], 2) . '.');
  /* 4 — RSI */
  $r = $A['rsi'][$i];
  if ($r !== null) {
    $s = ($r - 50) / 20; $note = 'RSI ' . round($r) . ($r >= 50 ? ' — bullish momentum zone.' : ' — bearish momentum zone.');
    if ($r > 75) { $s = 0.2; $note = 'RSI ' . round($r) . ' — overbought: strong, but stretched; better to buy dips than chase.'; }
    elseif ($r < 25) { $s = -0.2; $note = 'RSI ' . round($r) . ' — oversold: weak, but a relief bounce is likely.'; }
    $add('rsi', 'RSI (14)', 0.10, $s, round($r, 1), $note);
  }
  /* 5 — MACD */
  $h = $A['macd']['hist'][$i]; $hp = $A['macd']['hist'][$i - 1] ?? null; $m = $A['macd']['macd'][$i];
  if ($h !== null && $hp !== null) {
    $rising = $h > $hp;
    $s = $h > 0 ? ($rising ? 1 : 0.4) : ($rising ? -0.4 : -1);
    $add('macd', 'MACD (12,26,9)', 0.10, $s * 0.85 + ($m > 0 ? 0.15 : -0.15), round($h, 3),
      'Histogram ' . ($h > 0 ? 'positive' : 'negative') . ' and ' . ($rising ? 'rising' : 'falling') . '; MACD line ' . ($m > 0 ? 'above' : 'below') . ' zero.');
  }
  /* 6 — medium-term momentum (3m & 6m return) */
  $r63 = mk_ret_at($c, $i, 63); $r126 = mk_ret_at($c, $i, 126);
  if ($r63 !== null) {
    $mix = $r126 !== null ? 0.5 * $r63 + 0.5 * $r126 : $r63;
    $add('mom', 'Price momentum (3m/6m)', 0.12, tanh($mix / 0.15), round($r63 * 100, 1) . '% (3m)',
      '3-month ' . sprintf('%+.1f%%', $r63 * 100) . ($r126 !== null ? ', 6-month ' . sprintf('%+.1f%%', $r126 * 100) : '') . '. Stocks with strong 3–12 month momentum tend to keep outperforming (momentum effect).');
  }
  /* 7 — relative strength vs Nifty */
  if ($A['bench'] && $r63 !== null) {
    $b = $A['bench']; $br = ($i - 63 >= 0 && $b[$i - 63] && $b[$i]) ? $b[$i] / $b[$i - 63] - 1 : null;
    if ($br !== null) {
      $rs = $r63 - $br;
      $add('rs', 'Relative strength vs Nifty', 0.12, tanh($rs / 0.10), sprintf('%+.1f%%', $rs * 100),
        ($rs >= 0 ? 'Outperformed' : 'Underperformed') . ' Nifty 50 by ' . sprintf('%.1f', abs($rs * 100)) . ' pts over 3 months.');
    }
  }
  /* 8 — volume: accumulation vs distribution over 20 sessions */
  if ($i >= 21) {
    $upv = $dnv = 0.0;
    for ($j = $i - 19; $j <= $i; $j++) { if ($c[$j] > $c[$j - 1]) $upv += $C['v'][$j]; elseif ($c[$j] < $c[$j - 1]) $dnv += $C['v'][$j]; }
    if ($upv + $dnv > 0) {
      $ratio = ($upv + 1) / ($dnv + 1); $obvUp = $A['obv'][$i] > $A['obv'][$i - 20];
      $vr = $A['vsma20'][$i] ? $C['v'][$i] / $A['vsma20'][$i] : null;
      $add('vol', 'Volume: accumulation / distribution', 0.08, tanh(log($ratio)) * 0.8 + ($obvUp ? 0.2 : -0.2), round($ratio, 2) . 'x up/down vol',
        'Up-day volume is ' . round($ratio, 2) . 'x down-day volume over 20 sessions; OBV ' . ($obvUp ? 'rising (accumulation)' : 'falling (distribution)') . '.' . ($vr ? ' Today ' . round($vr, 1) . 'x average volume.' : ''));
    }
  }
  /* 9 — position in the 52-week range */
  $lb = min($i + 1, 250); $hi = max(array_slice($C['h'], $i - $lb + 1, $lb)); $lo = min(array_slice($C['l'], $i - $lb + 1, $lb));
  if ($hi > $lo && $i >= 60) {
    $pos = ($px - $lo) / ($hi - $lo); $fromHi = ($px / $hi - 1) * 100;
    $add('52w', '52-week range position', 0.07, ($pos - 0.5) * 2, round($pos * 100) . '% of range',
      sprintf('%.1f%% below the 52-week high (%.2f); ', abs($fromHi), $hi) . ($pos > 0.9 ? 'near highs — breakout territory, no overhead supply.' : ($pos < 0.2 ? 'near lows — still in a downtrend until it proves otherwise.' : 'mid-range.')));
  }
  /* 10 — money flow index */
  $mf = $A['mfi'][$i];
  if ($mf !== null) {
    $s = ($mf - 50) / 30; $note = 'MFI ' . round($mf) . ' — volume-weighted buying ' . ($mf >= 50 ? 'exceeds' : 'trails') . ' selling.';
    if ($mf > 80) { $s = -0.2; $note = 'MFI ' . round($mf) . ' — overbought on money flow.'; }
    if ($mf < 20) { $s = 0.2; $note = 'MFI ' . round($mf) . ' — oversold on money flow.'; }
    $add('mfi', 'Money Flow Index', 0.05, $s, round($mf, 1), $note);
  }
  $w = 0.0; $s = 0.0; foreach ($F as $f) { $w += $f['weight']; $s += $f['weight'] * $f['score']; }
  return ['score' => $w > 0 ? round($s / $w, 3) : 0.0, 'factors' => $F];
}

/* Full technical snapshot of the last bar, for the report */
function mk_tech_report(array $C, array $bench = null) {
  $A = mk_daily_arrays($C, $bench); $n = $A['n']; $i = $n - 1; $c = $C['c']; $px = $c[$i];
  $S = mk_tech_score_at($A, $i); $lv = mk_levels($C);
  $rets = [];
  foreach (['1w' => 5, '1m' => 21, '3m' => 63, '6m' => 126, '1y' => 250] as $k => $b) { $r = mk_ret_at($c, $i, $b); $rets[$k] = $r === null ? null : round($r * 100, 2); }
  $dr = mk_returns(array_slice($c, -251)); $vol = mk_stdev($dr); $beta = null;
  if ($bench) { list($a, $b) = mk_align($C, $bench); $beta = mk_beta(mk_returns(array_slice($a, -251)), mk_returns(array_slice($b, -251))); }
  $lb = min($n, 250);
  $bw = $A['boll']['bw']; $bwNow = $bw[$i]; $bwMin = min(array_filter(array_slice($bw, -126), 'is_numeric') ?: [0]);
  $vr = $A['vsma20'][$i] ? $C['v'][$i] / $A['vsma20'][$i] : null;
  return [
    'price' => round($px, 2), 'prev_close' => $n > 1 ? round($c[$i - 1], 2) : null, 'change_pct' => $n > 1 ? round(($px / $c[$i - 1] - 1) * 100, 2) : null,
    'score' => $S['score'], 'factors' => $S['factors'],
    'indicators' => [
      'sma20' => mk_round($A['sma20'][$i]), 'sma50' => mk_round($A['sma50'][$i]), 'sma200' => mk_round($A['sma200'][$i]),
      'ema9' => mk_round($A['ema9'][$i]), 'ema21' => mk_round($A['ema21'][$i]),
      'rsi' => mk_round($A['rsi'][$i], 1), 'macd' => mk_round($A['macd']['macd'][$i], 3), 'macd_signal' => mk_round($A['macd']['signal'][$i], 3), 'macd_hist' => mk_round($A['macd']['hist'][$i], 3),
      'adx' => mk_round($A['adx']['adx'][$i], 1), 'plus_di' => mk_round($A['adx']['pdi'][$i], 1), 'minus_di' => mk_round($A['adx']['mdi'][$i], 1),
      'supertrend' => mk_round($A['st']['line'][$i]), 'supertrend_dir' => $A['st']['dir'][$i],
      'boll_up' => mk_round($A['boll']['up'][$i]), 'boll_mid' => mk_round($A['boll']['mid'][$i]), 'boll_lo' => mk_round($A['boll']['lo'][$i]), 'boll_pctb' => mk_round($A['boll']['pctb'][$i], 2),
      'boll_squeeze' => $bwNow !== null && $bwNow <= $bwMin * 1.1,
      'atr' => mk_round($A['atr'][$i]), 'atr_pct' => $A['atr'][$i] ? round($A['atr'][$i] / $px * 100, 2) : null,
      'stoch_k' => mk_round($A['stoch']['k'][$i], 1), 'stoch_d' => mk_round($A['stoch']['d'][$i], 1),
      'mfi' => mk_round($A['mfi'][$i], 1), 'cci' => mk_round($A['cci'][$i], 1), 'willr' => mk_round($A['willr'][$i], 1),
      'volume' => $C['v'][$i], 'avg_volume_20' => $A['vsma20'][$i] ? round($A['vsma20'][$i]) : null, 'volume_ratio' => mk_round($vr),
    ],
    'returns' => $rets,
    'risk' => ['volatility_ann_pct' => $vol ? round($vol * sqrt(252) * 100, 1) : null, 'beta' => $beta ? mk_round($beta['beta']) : null, 'corr_nifty' => $beta ? mk_round($beta['corr']) : null,
               'max_drawdown_1y_pct' => round(mk_max_drawdown(array_slice($c, -250)), 1)],
    'range52' => ['high' => round(max(array_slice($C['h'], -$lb)), 2), 'low' => round(min(array_slice($C['l'], -$lb)), 2)],
    'levels' => ['support' => $lv['support'], 'resistance' => $lv['resistance']],
    'structure' => mk_structure($lv, $px),
    'patterns' => mk_patterns($C),
    'pivots_daily' => $n > 1 ? array_map(function ($x) { return $x === null ? null : round($x, 2); }, mk_pivots($C['h'][$i - 1], $C['l'][$i - 1], $c[$i - 1])) : null,
    '_A' => $A,
  ];
}

/* Series for the chart: last $bars bars with overlays */
function mk_chart_series(array $A, $bars = 160) {
  $C = $A['C']; $n = $A['n']; $f = max(0, $n - $bars); $pick = function ($a) use ($f) { return array_map(function ($x) { return $x === null ? null : round($x, 2); }, array_slice($a, $f)); };
  return ['t' => array_slice($C['t'], $f), 'o' => $pick($C['o']), 'h' => $pick($C['h']), 'l' => $pick($C['l']), 'c' => $pick($C['c']), 'v' => array_slice($C['v'], $f),
    'sma20' => $pick($A['sma20']), 'sma50' => $pick($A['sma50']), 'sma200' => $pick($A['sma200']), 'bb_up' => $pick($A['boll']['up']), 'bb_lo' => $pick($A['boll']['lo']),
    'rsi' => $pick($A['rsi']), 'macd' => array_map(function ($x) { return $x === null ? null : round($x, 3); }, array_slice($A['macd']['macd'], $f)),
    'macd_signal' => array_map(function ($x) { return $x === null ? null : round($x, 3); }, array_slice($A['macd']['signal'], $f)),
    'macd_hist' => array_map(function ($x) { return $x === null ? null : round($x, 3); }, array_slice($A['macd']['hist'], $f)),
    'st' => $pick($A['st']['line']), 'st_dir' => array_slice($A['st']['dir'], $f)];
}

/* =====================================================================
   INTRADAY — 5-minute (live) or 15-minute (backtest) bars.
   ===================================================================== */
function mk_intraday_arrays(array $C, array $bench = null) {
  $n = count($C['c']); $c = $C['c'];
  $A = ['C' => $C, 'n' => $n, 'ema9' => mk_ema($c, 9), 'ema21' => mk_ema($c, 21), 'rsi' => mk_rsi($c, 14), 'macd' => mk_macd($c),
        'st' => mk_supertrend($C, 10, 3), 'atr' => mk_atr($C, 14)];
  $vw = mk_vwap($C); $A['vwap'] = $vw['vwap']; $A['volume_weighted'] = $vw['volume_weighted'];
  /* per-session facts: day open, opening range (9:15–9:30), previous day's H/L/C */
  $A['day'] = []; $A['dopen'] = []; $A['orh'] = []; $A['orl'] = []; $A['or_done'] = []; $A['pdh'] = []; $A['pdl'] = []; $A['pdc'] = []; $A['cumv'] = [];
  $days = []; $cur = null; $orh = $orl = null; $dop = null; $cv = 0.0; $dh = $dl = null; $prev = null;
  for ($i = 0; $i < $n; $i++) {
    $d = mk_ist_date($C['t'][$i]); $m = mk_ist_min($C['t'][$i]);
    if ($d !== $cur) {
      if ($cur !== null) $prev = ['h' => $dh, 'l' => $dl, 'c' => $c[$i - 1]];
      $cur = $d; $dop = $C['o'][$i]; $orh = $orl = null; $cv = 0.0; $dh = $C['h'][$i]; $dl = $C['l'][$i]; $days[] = $d;
    }
    $dh = max($dh, $C['h'][$i]); $dl = min($dl, $C['l'][$i]); $cv += $C['v'][$i];
    if ($m < 570) { $orh = $orh === null ? $C['h'][$i] : max($orh, $C['h'][$i]); $orl = $orl === null ? $C['l'][$i] : min($orl, $C['l'][$i]); } // before 9:30
    $A['day'][$i] = $d; $A['dopen'][$i] = $dop; $A['orh'][$i] = $orh; $A['orl'][$i] = $orl; $A['or_done'][$i] = $m >= 570 - 5;
    $A['pdh'][$i] = $prev ? $prev['h'] : null; $A['pdl'][$i] = $prev ? $prev['l'] : null; $A['pdc'][$i] = $prev ? $prev['c'] : null; $A['cumv'][$i] = $cv;
  }
  $A['days'] = $days;
  /* benchmark % change from its own previous close at the same timestamp */
  $A['bench_chg'] = null;
  if ($bench && count($bench['c']) > 10) {
    $BA = mk_intraday_arrays($bench); $map = [];
    for ($j = 0; $j < $BA['n']; $j++) if ($BA['pdc'][$j]) $map[$bench['t'][$j]] = $bench['c'][$j] / $BA['pdc'][$j] - 1;
    /* carry the last index reading forward, but never across a session boundary */
    $bc = []; $last = null; $lastDay = null;
    for ($i = 0; $i < $n; $i++) {
      if (isset($map[$C['t'][$i]])) { $last = $map[$C['t'][$i]]; $lastDay = $A['day'][$i]; }
      $bc[$i] = $lastDay === $A['day'][$i] ? $last : null;
    }
    $A['bench_chg'] = $bc;
  }
  return $A;
}

function mk_intraday_score_at(array $A, $i) {
  $C = $A['C']; $px = $C['c'][$i]; $F = [];
  $atr = $A['atr'][$i] ?: max($px * 0.003, 0.05);
  $add = function ($key, $label, $w, $score, $value, $note) use (&$F) {
    if ($score === null) return;
    $F[] = ['key' => $key, 'label' => $label, 'weight' => $w, 'score' => round(mk_clamp($score), 3), 'value' => $value, 'note' => $note];
  };
  $vw = $A['vwap'][$i];
  if ($vw) $add('vwap', $A['volume_weighted'] ? 'Price vs VWAP' : 'Price vs session average', 0.20, tanh(($px - $vw) / $atr), round($vw, 2),
    ($px >= $vw ? 'Above' : 'Below') . ' ' . ($A['volume_weighted'] ? 'VWAP' : 'average price') . ' ' . round($vw, 2) . ' — ' . ($px >= $vw ? 'intraday buyers are in profit, dips tend to get bought.' : 'intraday buyers are under water, rallies tend to get sold.'));
  $e9 = $A['ema9'][$i]; $e21 = $A['ema21'][$i];
  if ($e9 !== null && $e21 !== null) $add('ema', 'EMA 9/21 crossover', 0.15, ($e9 > $e21 ? 0.6 : -0.6) + ($px > $e9 ? 0.4 : -0.4), round($e9 - $e21, 2),
    'EMA9 ' . ($e9 > $e21 ? 'above' : 'below') . ' EMA21, price ' . ($px > $e9 ? 'above' : 'below') . ' EMA9.');
  $sd = $A['st']['dir'][$i];
  if ($sd !== null) $add('st', 'Supertrend (10,3)', 0.15, $sd, round($A['st']['line'][$i], 2), ($sd > 0 ? 'Buy mode, trailing stop ' : 'Sell mode, trailing stop ') . round($A['st']['line'][$i], 2) . '.');
  $r = $A['rsi'][$i];
  if ($r !== null) { $s = ($r - 50) / 20; if ($r > 80) $s = 0.1; if ($r < 20) $s = -0.1;
    $add('rsi', 'RSI (14)', 0.10, $s, round($r, 1), 'RSI ' . round($r) . ($r > 80 ? ' — exhausted, avoid fresh longs.' : ($r < 20 ? ' — exhausted, avoid fresh shorts.' : ($r >= 50 ? ' — bullish.' : ' — bearish.')))); }
  $h = $A['macd']['hist'][$i]; $hp = $A['macd']['hist'][$i - 1] ?? null;
  if ($h !== null && $hp !== null) $add('macd', 'MACD histogram', 0.10, $h > 0 ? ($h > $hp ? 1 : 0.3) : ($h < $hp ? -1 : -0.3), round($h, 3), 'Histogram ' . ($h > 0 ? 'positive' : 'negative') . ', ' . ($h > $hp ? 'rising' : 'falling') . '.');
  if ($A['or_done'][$i] && $A['orh'][$i] !== null) {
    $orh = $A['orh'][$i]; $orl = $A['orl'][$i]; $s = $px > $orh ? 1 : ($px < $orl ? -1 : 0);
    $add('orb', 'Opening-range breakout (15 min)', 0.10, $s, round($orl, 2) . '–' . round($orh, 2),
      $s > 0 ? 'Broke above the opening range high ' . round($orh, 2) . '.' : ($s < 0 ? 'Broke below the opening range low ' . round($orl, 2) . '.' : 'Still inside the opening range — no breakout yet.'));
  }
  $pdc = $A['pdc'][$i]; $dop = $A['dopen'][$i];
  if ($pdc) {
    $gap = ($dop / $pdc - 1) * 100; $chg = ($px / $pdc - 1) * 100;
    $s = ($px > $dop ? 0.5 : -0.5) + ($px > $pdc ? 0.5 : -0.5);
    $add('day', 'Day structure (open / prev close)', 0.05, $s, sprintf('%+.2f%%', $chg),
      sprintf('Gap %+.2f%%, now %+.2f%% on the day, ', $gap, $chg) . ($px > $dop ? 'above' : 'below') . ' the open' . (($gap > 0.5 && $px < $dop) ? ' — gap-up being sold.' : (($gap < -0.5 && $px > $dop) ? ' — gap-down being bought.' : '.')));
    if ($A['pdh'][$i]) {
      $P = mk_pivots($A['pdh'][$i], $A['pdl'][$i], $pdc);
      $s = $px > $P['TC'] ? 1 : ($px < $P['BC'] ? -1 : 0);
      $add('cpr', 'Central Pivot Range', 0.05, $s, round($P['BC'], 2) . '–' . round($P['TC'], 2),
        ($s > 0 ? 'Above CPR — bullish day bias.' : ($s < 0 ? 'Below CPR — bearish day bias.' : 'Inside CPR — undecided.')) . ' CPR width ' . round($P['cpr_width_pct'], 2) . '%' . ($P['cpr_width_pct'] < 0.25 ? ' (narrow → trending day likely).' : '.'));
    }
  }
  if ($A['bench_chg'] && $A['bench_chg'][$i] !== null && $pdc) {
    $rel = ($px / $pdc - 1) - $A['bench_chg'][$i];
    $add('rs', 'Intraday strength vs Nifty', 0.10, tanh($rel / 0.006), sprintf('%+.2f pts', $rel * 100), ($rel >= 0 ? 'Outperforming' : 'Underperforming') . ' Nifty by ' . sprintf('%.2f', abs($rel * 100)) . ' pts today.');
  }
  $w = 0.0; $s = 0.0; foreach ($F as $f) { $w += $f['weight']; $s += $f['weight'] * $f['score']; }
  return ['score' => $w > 0 ? round($s / $w, 3) : 0.0, 'factors' => $F];
}

/* Relative volume: today's cumulative volume vs the same time on earlier sessions */
function mk_rvol(array $A, $i) {
  $m = mk_ist_min($A['C']['t'][$i]); $today = $A['day'][$i]; $past = [];
  for ($j = 0; $j < $i; $j++) {
    if ($A['day'][$j] === $today) continue;
    $mj = mk_ist_min($A['C']['t'][$j]);
    if ($mj <= $m && ($j + 1 >= $A['n'] || $A['day'][$j + 1] !== $A['day'][$j] || mk_ist_min($A['C']['t'][$j + 1]) > $m)) $past[$A['day'][$j]] = $A['cumv'][$j];
  }
  $avg = mk_mean(array_values($past));
  return ($avg && $avg > 0) ? $A['cumv'][$i] / $avg : null;
}

/* Turn a score into a trade plan with entry, stop, targets and size.
   Stops are the wider of an ATR stop and the nearest structure (swing / VWAP),
   but never more than 2.5 ATR, so the risk stays bounded. */
function mk_trade_plan($side, $entry, $atr, array $structLevels, $capital, $riskPct, $rMult = [1.5, 2.5], $maxAtr = 2.5, $minAtr = 0.8) {
  if (!$atr || $atr <= 0) return null;
  $sl = $side > 0 ? $entry - 1.5 * $atr : $entry + 1.5 * $atr;
  foreach ($structLevels as $lv) {
    if (!$lv) continue;
    if ($side > 0 && $lv < $entry && $entry - $lv >= $minAtr * $atr && $entry - $lv <= $maxAtr * $atr) { $sl = min($sl, $lv - 0.1 * $atr); break; }
    if ($side < 0 && $lv > $entry && $lv - $entry >= $minAtr * $atr && $lv - $entry <= $maxAtr * $atr) { $sl = max($sl, $lv + 0.1 * $atr); break; }
  }
  $risk = abs($entry - $sl); if ($risk <= 0) return null;
  $t = []; foreach ($rMult as $r) $t[] = round($entry + $side * $r * $risk, 2);
  $qty = ($capital > 0 && $riskPct > 0) ? (int) floor($capital * $riskPct / 100 / $risk) : null;
  if ($qty !== null && $capital > 0) $qty = min($qty, (int) floor($capital / $entry * ($side > 0 ? 1 : 1)));
  return ['side' => $side > 0 ? 'LONG' : 'SHORT', 'entry' => round($entry, 2), 'stop' => round($sl, 2), 'targets' => $t,
          'risk_per_share' => round($risk, 2), 'risk_pct' => round($risk / $entry * 100, 2), 'rr' => $rMult, 'qty' => $qty,
          'capital_used' => $qty !== null ? round($qty * $entry) : null, 'max_loss' => $qty !== null ? round($qty * $risk) : null];
}

function mk_confidence($score, array $factors) {
  $agree = 0; $tot = 0;
  foreach ($factors as $f) { if (abs($f['score']) < 0.15) continue; $tot++; if (($f['score'] > 0) === ($score > 0)) $agree++; }
  $ag = $tot ? $agree / $tot : 0.5;
  return (int) round(mk_clamp(35 + abs($score) * 70 * (0.5 + $ag / 2), 0, 92)); // capped: no rule set deserves ~100%
}

/* Live intraday signal from 5-minute bars (+ 15-minute confirmation) */
function mk_intraday_signal(array $C5, array $bench5 = null, array $ctx = []) {
  $capital = $ctx['capital'] ?? 100000; $riskPct = $ctx['risk_pct'] ?? 1.0;
  $A = mk_intraday_arrays($C5, $bench5); $n = $A['n']; if ($n < 30) return ['error' => 'Not enough intraday bars yet.'];
  $i = $n - 1; $S = mk_intraday_score_at($A, $i); $px = $C5['c'][$i];
  $C15 = mk_resample($C5, 3); $A15 = mk_intraday_arrays($C15); $S15 = $A15['n'] > 30 ? mk_intraday_score_at($A15, $A15['n'] - 1) : null;
  $score = $S['score'];
  $ctxNotes = [];
  /* context tilts: the daily trend and the market's mood move the needle a little */
  if (isset($ctx['daily_score'])) { $score += 0.12 * $ctx['daily_score']; $ctxNotes[] = 'Daily trend score ' . sprintf('%+.2f', $ctx['daily_score']) . ' ' . ($ctx['daily_score'] >= 0 ? 'supports longs' : 'supports shorts') . '.'; }
  if (isset($ctx['market_score'])) { $score += 0.10 * $ctx['market_score']; $ms = $ctx['market_score']; $ctxNotes[] = 'Market regime ' . sprintf('%+.2f', $ms) . ($ms >= 0.12 ? ' (risk-on) — tailwind for longs.' : ($ms <= -0.12 ? ' (risk-off) — tailwind for shorts.' : ' (neutral) — no help either way.')); }
  if ($S15) { $score += 0.10 * $S15['score']; $ctxNotes[] = '15-minute score ' . sprintf('%+.2f', $S15['score']) . (($S15['score'] > 0) === ($S['score'] > 0) ? ' agrees with 5-minute.' : ' disagrees with 5-minute — lower conviction.'); }
  $score = round(mk_clamp($score), 3);
  $m = mk_ist_min($C5['t'][$i]); $today = $A['day'][$i];
  $warn = [];
  if ($m < 570) $warn[] = 'Opening 15 minutes: spreads are wide and moves reverse often — wait for the opening range to form (9:30).';
  if ($m >= 870) $warn[] = 'After 2:30 PM: too little time left for a fresh intraday trade to work; manage open positions only.';
  if (isset($ctx['india_vix']) && $ctx['india_vix'] > 20) $warn[] = 'India VIX ' . round($ctx['india_vix'], 1) . ' is elevated — halve position size, expect wider swings.';
  if (!empty($ctx['earnings_soon'])) $warn[] = 'Results due ' . $ctx['earnings_soon'] . ' — event risk; gaps can jump stops.';
  $rvol = mk_rvol($A, $i);
  if ($rvol !== null && $rvol < 0.6) $warn[] = 'Relative volume ' . round($rvol, 2) . 'x — thin participation, breakouts less reliable.';
  $atr = $A['atr'][$i];
  $last = mk_ist_date(time()) === $today;
  $action = 'NO TRADE'; $plan = null; $th = 0.35;
  $tradable = $m >= 570 && $m < 870;
  if ($score >= $th) { $action = 'BUY'; }
  elseif ($score <= -$th) { $action = 'SELL'; }
  if ($action !== 'NO TRADE') {
    $side = $action === 'BUY' ? 1 : -1;
    $lows = array_slice($C5['l'], -8); $highs = array_slice($C5['h'], -8);
    $struct = $side > 0 ? [min($lows), $A['vwap'][$i], $A['orl'][$i]] : [max($highs), $A['vwap'][$i], $A['orh'][$i]];
    $plan = mk_trade_plan($side, $px, $atr, $struct, $capital, $riskPct);
    if (!$tradable) $action = $action === 'BUY' ? 'BUY (wait)' : 'SELL (wait)';
  } elseif (abs($score) >= 0.2) $action = $score > 0 ? 'WATCH — LONG BIAS' : 'WATCH — SHORT BIAS';
  $P = $A['pdh'][$i] ? mk_pivots($A['pdh'][$i], $A['pdl'][$i], $A['pdc'][$i]) : null;
  $f = max(0, $n - 150); $pick = function ($a) use ($f) { return array_map(function ($x) { return $x === null ? null : round($x, 2); }, array_slice($a, $f)); };
  return [
    'action' => $action, 'score' => $score, 'raw_score' => $S['score'], 'confidence' => mk_confidence($score, $S['factors']),
    'factors' => $S['factors'], 'context' => $ctxNotes, 'warnings' => $warn, 'plan' => $plan,
    'session' => ['date' => $today, 'is_today' => $last, 'last_bar_ist' => gmdate('H:i', $C5['t'][$i] + MK_IST), 'price' => round($px, 2),
                  'open' => round($A['dopen'][$i], 2), 'prev_close' => $A['pdc'][$i] ? round($A['pdc'][$i], 2) : null,
                  'day_high' => round(max(array_slice($C5['h'], array_search($today, $A['day'], true))), 2), 'day_low' => round(min(array_slice($C5['l'], array_search($today, $A['day'], true))), 2),
                  'vwap' => mk_round($A['vwap'][$i]), 'vwap_is_volume' => $A['volume_weighted'], 'or_high' => mk_round($A['orh'][$i]), 'or_low' => mk_round($A['orl'][$i]),
                  'atr_5m' => mk_round($atr), 'rvol' => mk_round($rvol), 'pivots' => $P ? array_map(function ($x) { return $x === null ? null : round($x, 2); }, $P) : null],
    'tf15' => $S15 ? ['score' => $S15['score']] : null,
    'chart' => ['t' => array_slice($C5['t'], $f), 'o' => $pick($C5['o']), 'h' => $pick($C5['h']), 'l' => $pick($C5['l']), 'c' => $pick($C5['c']), 'v' => array_slice($C5['v'], $f),
                'vwap' => $pick($A['vwap']), 'ema9' => $pick($A['ema9']), 'ema21' => $pick($A['ema21'])],
  ];
}

/* =====================================================================
   BACKTESTS — the same scoring rules replayed over history.
   ===================================================================== */
function mk_bt_stats(array $trades, $label, $benchRet = null) {
  $n = count($trades); if (!$n) return ['label' => $label, 'trades' => 0, 'note' => 'No signals fired in the test window.'];
  $wins = 0; $gw = $gl = 0.0; $eq = 1.0; $peak = 1.0; $dd = 0.0; $hold = 0;
  foreach ($trades as $t) { $r = $t['ret']; if ($r > 0) { $wins++; $gw += $r; } else $gl += -$r; $eq *= (1 + $r / 100); $peak = max($peak, $eq); $dd = min($dd, $eq / $peak - 1); $hold += $t['bars']; }
  return ['label' => $label, 'trades' => $n, 'win_rate' => round($wins / $n * 100, 1), 'avg_ret' => round(array_sum(array_column($trades, 'ret')) / $n, 2),
          'avg_win' => $wins ? round($gw / $wins, 2) : 0, 'avg_loss' => $n - $wins ? round(-$gl / ($n - $wins), 2) : 0,
          'profit_factor' => $gl > 0 ? round($gw / $gl, 2) : null, 'total_ret' => round(($eq - 1) * 100, 1), 'max_dd' => round($dd * 100, 1),
          'avg_bars' => round($hold / $n, 1), 'benchmark_ret' => $benchRet === null ? null : round($benchRet, 1), 'recent' => array_slice($trades, -8)];
}
/* Swing: long when the daily score crosses up through +0.30; stop 2 ATR, target 4 ATR,
   exit if the score turns negative or after 30 sessions. Costs 0.25% round trip. */
function mk_backtest_swing(array $A, $th = 0.30, $cost = 0.25) {
  $C = $A['C']; $n = $A['n']; $trades = []; $start = min(210, $n - 1); $prevS = null; $in = null;
  $scores = []; for ($i = $start; $i < $n; $i++) $scores[$i] = mk_tech_score_at($A, $i)['score'];
  for ($i = $start; $i < $n - 1; $i++) {
    $s = $scores[$i];
    if ($in) {
      $j = $i; $hit = null;
      if ($C['l'][$j] <= $in['sl']) $hit = min($C['o'][$j], $in['sl']);
      elseif ($C['h'][$j] >= $in['tp']) $hit = max($C['o'][$j], $in['tp']);
      elseif ($s < 0 || $j - $in['i'] >= 30) $hit = $C['c'][$j];
      if ($hit !== null) { $trades[] = ['date' => mk_ist_date($C['t'][$in['i']]), 'exit' => mk_ist_date($C['t'][$j]), 'side' => 'LONG', 'ret' => round(($hit / $in['e'] - 1) * 100 - $cost, 2), 'bars' => $j - $in['i']]; $in = null; }
    }
    if (!$in && $prevS !== null && $s >= $th && $prevS < $th && $A['atr'][$i]) {
      $e = $C['o'][$i + 1]; $in = ['i' => $i + 1, 'e' => $e, 'sl' => $e - 2 * $A['atr'][$i], 'tp' => $e + 4 * $A['atr'][$i]];
    }
    $prevS = $s;
  }
  $bh = ($C['c'][$n - 1] / $C['c'][$start] - 1) * 100;
  $st = mk_bt_stats($trades, 'Swing (daily score ≥ +0.30, 2-ATR stop, 4-ATR target)', $bh);
  $st['period'] = mk_ist_date($C['t'][$start]) . ' → ' . mk_ist_date($C['t'][$n - 1]);
  $st['benchmark_label'] = 'Buy & hold over the same period';
  return $st;
}
/* Intraday: enter on a fresh cross of ±0.45 between 9:45 and 14:30, stop 1.2 ATR,
   target 2 ATR, square off at 15:15. Costs 0.08% round trip. Long and short. */
function mk_backtest_intraday(array $A, $th = 0.45, $cost = 0.08) {
  $C = $A['C']; $n = $A['n']; $trades = []; $in = null; $prev = null;
  for ($i = 30; $i < $n; $i++) {
    $m = mk_ist_min($C['t'][$i]); $newDay = $A['day'][$i] !== $A['day'][$i - 1];
    if ($in && ($newDay)) { $x = $C['c'][$i - 1]; $trades[] = ['date' => $A['day'][$in['i']], 'side' => $in['side'] > 0 ? 'LONG' : 'SHORT', 'ret' => round($in['side'] * ($x / $in['e'] - 1) * 100 - $cost, 2), 'bars' => $i - 1 - $in['i']]; $in = null; }
    if ($in) {
      $hit = null;
      if ($in['side'] > 0) { if ($C['l'][$i] <= $in['sl']) $hit = min($C['o'][$i], $in['sl']); elseif ($C['h'][$i] >= $in['tp']) $hit = max($C['o'][$i], $in['tp']); }
      else { if ($C['h'][$i] >= $in['sl']) $hit = max($C['o'][$i], $in['sl']); elseif ($C['l'][$i] <= $in['tp']) $hit = min($C['o'][$i], $in['tp']); }
      if ($hit === null && $m >= 915) $hit = $C['c'][$i];
      if ($hit !== null) { $trades[] = ['date' => $A['day'][$in['i']], 'side' => $in['side'] > 0 ? 'LONG' : 'SHORT', 'ret' => round($in['side'] * ($hit / $in['e'] - 1) * 100 - $cost, 2), 'bars' => $i - $in['i']]; $in = null; }
    }
    $s = mk_intraday_score_at($A, $i)['score'];
    if (!$in && !$newDay && $prev !== null && $m >= 585 && $m <= 870 && $A['atr'][$i]) {
      $side = ($s >= $th && $prev < $th) ? 1 : (($s <= -$th && $prev > -$th) ? -1 : 0);
      if ($side) { $e = $C['c'][$i]; $a = $A['atr'][$i]; $in = ['i' => $i, 'side' => $side, 'e' => $e, 'sl' => $e - $side * 1.2 * $a, 'tp' => $e + $side * 2 * $a]; }
    }
    $prev = $newDay ? null : $s;
  }
  $st = mk_bt_stats($trades, 'Intraday (15-min score crosses ±0.45, 1.2-ATR stop, 2-ATR target, exit 3:15 PM)');
  $st['period'] = $n ? $A['day'][0] . ' → ' . $A['day'][$n - 1] : '';
  $long = array_values(array_filter($trades, function ($t) { return $t['side'] === 'LONG'; }));
  $short = array_values(array_filter($trades, function ($t) { return $t['side'] === 'SHORT'; }));
  $st['long_win_rate'] = $long ? round(count(array_filter($long, function ($t) { return $t['ret'] > 0; })) / count($long) * 100, 1) : null;
  $st['short_win_rate'] = $short ? round(count(array_filter($short, function ($t) { return $t['ret'] > 0; })) / count($short) * 100, 1) : null;
  return $st;
}

/* =====================================================================
   FUNDAMENTALS — from Yahoo's quoteSummary modules.
   Indian-market thresholds: ROE ≥ 15% is good, D/E < 1 for non-financials, etc.
   Banks and NBFCs skip leverage and liquidity ratios (debt is their raw material).
   ===================================================================== */
function mk_raw($x) { if (is_array($x)) return array_key_exists('raw', $x) ? $x['raw'] : null; return is_numeric($x) ? (float) $x : null; }
function mk_fundamentals(array $Q, $price, $sectorKey) {
  $fd = $Q['financialData'] ?? []; $ks = $Q['defaultKeyStatistics'] ?? []; $sd = $Q['summaryDetail'] ?? []; $pr = $Q['price'] ?? []; $ap = $Q['assetProfile'] ?? [];
  $fin = in_array($sectorKey, ['banks', 'psubanks', 'financials', 'insurance'], true);
  $v = [
    'market_cap' => mk_raw($pr['marketCap'] ?? $sd['marketCap'] ?? null),
    'pe' => mk_raw($sd['trailingPE'] ?? null), 'forward_pe' => mk_raw($sd['forwardPE'] ?? $ks['forwardPE'] ?? null),
    'pb' => mk_raw($ks['priceToBook'] ?? null), 'peg' => mk_raw($ks['pegRatio'] ?? null), 'ev_ebitda' => mk_raw($ks['enterpriseToEbitda'] ?? null),
    'ps' => mk_raw($sd['priceToSalesTrailing12Months'] ?? null),
    'roe' => mk_raw($fd['returnOnEquity'] ?? null), 'roa' => mk_raw($fd['returnOnAssets'] ?? null),
    'op_margin' => mk_raw($fd['operatingMargins'] ?? null), 'net_margin' => mk_raw($fd['profitMargins'] ?? null), 'gross_margin' => mk_raw($fd['grossMargins'] ?? null),
    'rev_growth' => mk_raw($fd['revenueGrowth'] ?? null), 'earn_growth' => mk_raw($fd['earningsGrowth'] ?? $ks['earningsQuarterlyGrowth'] ?? null),
    'de' => mk_raw($fd['debtToEquity'] ?? null), 'current_ratio' => mk_raw($fd['currentRatio'] ?? null), 'fcf' => mk_raw($fd['freeCashflow'] ?? null),
    'div_yield' => mk_raw($sd['dividendYield'] ?? null), 'payout' => mk_raw($sd['payoutRatio'] ?? null),
    'insiders' => mk_raw($ks['heldPercentInsiders'] ?? null), 'institutions' => mk_raw($ks['heldPercentInstitutions'] ?? null),
    'target_mean' => mk_raw($fd['targetMeanPrice'] ?? null), 'target_high' => mk_raw($fd['targetHighPrice'] ?? null), 'target_low' => mk_raw($fd['targetLowPrice'] ?? null),
    'rec_mean' => mk_raw($fd['recommendationMean'] ?? null), 'rec_key' => $fd['recommendationKey'] ?? null, 'analysts' => mk_raw($fd['numberOfAnalystOpinions'] ?? null),
    'eps_ttm' => mk_raw($ks['trailingEps'] ?? null), 'eps_fwd' => mk_raw($ks['forwardEps'] ?? null), 'book_value' => mk_raw($ks['bookValue'] ?? null),
    'beta' => mk_raw($sd['beta'] ?? $ks['beta'] ?? null),
    'sector' => $ap['sector'] ?? null, 'industry' => $ap['industry'] ?? null, 'employees' => $ap['fullTimeEmployees'] ?? null,
    'summary' => isset($ap['longBusinessSummary']) ? mb_substr($ap['longBusinessSummary'], 0, 700) : null,
    'name' => $pr['longName'] ?? $pr['shortName'] ?? null,
  ];
  $ce = $Q['calendarEvents']['earnings']['earningsDate'][0] ?? null; $v['next_earnings'] = $ce ? gmdate('Y-m-d', (int) mk_raw($ce)) : null;
  if ($v['pe'] === null && $v['eps_ttm'] && $v['eps_ttm'] > 0 && $price) $v['pe'] = $price / $v['eps_ttm'];
  $rt = $Q['recommendationTrend']['trend'][0] ?? null;
  $v['rec_trend'] = $rt ? ['strong_buy' => $rt['strongBuy'] ?? 0, 'buy' => $rt['buy'] ?? 0, 'hold' => $rt['hold'] ?? 0, 'sell' => $rt['sell'] ?? 0, 'strong_sell' => $rt['strongSell'] ?? 0] : null;
  $F = [];
  $add = function ($grp, $key, $label, $w, $score, $value, $note) use (&$F) {
    if ($score === null) return; $F[] = ['group' => $grp, 'key' => $key, 'label' => $label, 'weight' => $w, 'score' => round(mk_clamp($score), 3), 'value' => $value, 'note' => $note];
  };
  $pct = function ($x) { return $x === null ? null : round($x * 100, 1) . '%'; };
  $norm = mk_sector_norms($sectorKey);
  /* VALUE */
  if ($v['pe'] !== null) {
    if ($v['pe'] <= 0) $add('Value', 'pe', 'P/E (trailing)', 0.10, -0.6, 'loss-making', 'Negative earnings — no P/E support.');
    else { $rel = $v['pe'] / $norm['pe']; $add('Value', 'pe', 'P/E vs sector norm', 0.10, tanh((1 - $rel) * 1.5), round($v['pe'], 1) . 'x (norm ~' . $norm['pe'] . 'x)', 'Trades at ' . round($rel * 100) . '% of the typical ' . $norm['label'] . ' multiple' . ($rel < 0.8 ? ' — cheap vs peers.' : ($rel > 1.3 ? ' — premium valuation; growth must deliver.' : ' — fairly valued.'))); }
  }
  if ($v['forward_pe'] && $v['pe'] && $v['pe'] > 0 && $v['forward_pe'] > 0) $add('Value', 'fpe', 'Forward vs trailing P/E', 0.04, tanh(($v['pe'] / $v['forward_pe'] - 1) * 3), round($v['forward_pe'], 1) . 'x fwd',
    $v['forward_pe'] < $v['pe'] ? 'Forward P/E below trailing — analysts expect earnings to grow.' : 'Forward P/E above trailing — earnings expected to shrink.');
  if ($v['pb'] !== null && $v['pb'] > 0) $add('Value', 'pb', 'Price / Book', $fin ? 0.08 : 0.04, tanh((1 - $v['pb'] / $norm['pb']) * 1.2), round($v['pb'], 2) . 'x', $fin ? 'For lenders P/B is the key valuation — norm ~' . $norm['pb'] . 'x.' : 'Norm for the sector ~' . $norm['pb'] . 'x.');
  if ($v['peg'] !== null && $v['peg'] > 0) $add('Value', 'peg', 'PEG ratio', 0.04, tanh((1.5 - $v['peg']) / 1.0), round($v['peg'], 2), $v['peg'] < 1 ? 'Growth available cheaply (PEG < 1).' : ($v['peg'] > 2.5 ? 'Paying a lot for the growth (PEG > 2.5).' : 'Reasonable price for the growth.'));
  /* QUALITY */
  if ($v['roe'] !== null) $add('Quality', 'roe', 'Return on equity', 0.12, tanh(($v['roe'] - 0.13) / 0.08), $pct($v['roe']), $v['roe'] >= 0.18 ? 'High ROE — efficient use of shareholder capital (a hallmark of Indian compounders).' : ($v['roe'] < 0.10 ? 'Low ROE — capital earns below its cost.' : 'Adequate ROE.'));
  if ($v['op_margin'] !== null && !$fin) $add('Quality', 'opm', 'Operating margin', 0.06, tanh(($v['op_margin'] - $norm['opm']) / 0.08), $pct($v['op_margin']), ($v['op_margin'] >= $norm['opm'] ? 'Above' : 'Below') . ' the sector norm of ~' . round($norm['opm'] * 100) . '%.');
  if ($v['net_margin'] !== null) $add('Quality', 'npm', 'Net profit margin', 0.04, tanh($v['net_margin'] / 0.10), $pct($v['net_margin']), $v['net_margin'] < 0 ? 'Loss-making.' : 'Keeps ' . round($v['net_margin'] * 100, 1) . 'p of every ₹1 of sales.');
  if ($v['roa'] !== null) $add('Quality', 'roa', 'Return on assets', $fin ? 0.06 : 0.03, tanh(($v['roa'] - ($fin ? 0.012 : 0.06)) / ($fin ? 0.008 : 0.05)), $pct($v['roa']), $fin ? 'For banks RoA ≥ 1.2% is strong.' : 'Asset efficiency.');
  /* GROWTH */
  if ($v['rev_growth'] !== null) $add('Growth', 'rev', 'Revenue growth (YoY)', 0.10, tanh(($v['rev_growth'] - 0.08) / 0.12), $pct($v['rev_growth']), $v['rev_growth'] > 0.15 ? 'Strong top-line growth, ahead of nominal GDP.' : ($v['rev_growth'] < 0 ? 'Revenue shrinking.' : ($v['rev_growth'] < 0.07 ? 'Revenue growing slower than the economy.' : 'Growing roughly with the economy.')));
  if ($v['earn_growth'] !== null) $add('Growth', 'eps', 'Earnings growth (YoY)', 0.10, tanh(($v['earn_growth'] - 0.10) / 0.20), $pct($v['earn_growth']), $v['earn_growth'] > 0.20 ? 'Profits compounding fast — earnings drive long-term returns.' : ($v['earn_growth'] < 0 ? 'Profits falling.' : ($v['earn_growth'] < 0.06 ? 'Profits barely growing.' : 'Moderate profit growth.')));
  /* HEALTH */
  if (!$fin && $v['de'] !== null) { $de = $v['de'] / 100; // Yahoo and CNBC both report D/E in percent (35 = 0.35x)
    $add('Health', 'de', 'Debt / Equity', 0.08, tanh((0.8 - $de) / 0.6), round($de, 2) . 'x', $de < 0.3 ? 'Nearly debt-free — resilient to rate hikes.' : ($de > 1.5 ? 'Highly leveraged — vulnerable to rising rates / slowdowns.' : ($de > 0.9 ? 'Meaningful debt — watch interest costs.' : 'Manageable leverage.'))); }
  if (!$fin && $v['current_ratio'] !== null) $add('Health', 'cr', 'Current ratio', 0.03, tanh(($v['current_ratio'] - 1.2) / 0.6), round($v['current_ratio'], 2), $v['current_ratio'] < 1 ? 'Short-term liabilities exceed short-term assets.' : 'Comfortable liquidity.');
  if (!$fin && $v['fcf'] !== null) $add('Health', 'fcf', 'Free cash flow', 0.04, $v['fcf'] > 0 ? 0.6 : -0.6, $v['fcf'] > 0 ? 'positive' : 'negative', $v['fcf'] > 0 ? 'Generates cash after capex — can fund growth/dividends itself.' : 'Burning cash — depends on borrowing or equity.');
  /* OWNERSHIP & STREET */
  if ($v['insiders'] !== null) $add('Ownership', 'promoter', 'Promoter / insider holding', 0.04, tanh(($v['insiders'] - 0.40) / 0.20), $pct($v['insiders']), $v['insiders'] > 0.5 ? 'High promoter skin in the game.' : ($v['insiders'] < 0.2 ? 'Low promoter holding (common for professionally-run cos).' : 'Moderate promoter holding.'));
  if ($v['institutions'] !== null) $add('Ownership', 'inst', 'Institutional holding (FII+DII)', 0.03, tanh(($v['institutions'] - 0.25) / 0.2), $pct($v['institutions']), 'Institutional sponsorship supports liquidity and re-rating.');
  if ($v['target_mean'] && $price) { $up = $v['target_mean'] / $price - 1;
    $add('Street', 'target', 'Analyst target upside', 0.06, tanh($up / 0.15), sprintf('%+.1f%%', $up * 100), 'Mean target ₹' . round($v['target_mean'], 1) . ($v['analysts'] ? ' from ' . (int) $v['analysts'] . ' analysts' : '') . '.'); }
  if ($v['rec_mean']) $add('Street', 'rec', 'Analyst consensus', 0.04, tanh((3 - $v['rec_mean']) / 1.0), round($v['rec_mean'], 2) . ' (' . ($v['rec_key'] ?: '—') . ')', 'Scale 1 = strong buy … 5 = sell.');
  if ($v['div_yield'] !== null && $v['div_yield'] > 0) $add('Value', 'dy', 'Dividend yield', 0.02, tanh(($v['div_yield'] - 0.01) / 0.015), $pct($v['div_yield']), 'Cash returned to shareholders.');
  $w = 0.0; $s = 0.0; $groups = [];
  foreach ($F as $f) { $w += $f['weight']; $s += $f['weight'] * $f['score']; $groups[$f['group']][] = $f; }
  $gs = []; foreach ($groups as $g => $fs) { $gw = array_sum(array_column($fs, 'weight')); $gs[$g] = $gw ? round(array_sum(array_map(function ($f) { return $f['weight'] * $f['score']; }, $fs)) / $gw, 3) : 0; }
  return ['available' => count($F) >= 3, 'score' => $w > 0 ? round($s / $w, 3) : 0.0, 'coverage' => round(min(1, $w / 0.8), 2), 'group_scores' => $gs, 'factors' => $F, 'values' => $v];
}
/* Rough Indian sector valuation norms (long-run medians, not live data) */
function mk_sector_norms($k) {
  $N = [
    'it' => [28, 7, 0.22, 'IT services'], 'pharma' => [30, 4.5, 0.20, 'pharma'], 'banks' => [16, 2.2, 0.30, 'private bank'], 'psubanks' => [8, 1.1, 0.25, 'PSU bank'],
    'financials' => [22, 3.5, 0.35, 'NBFC/financials'], 'insurance' => [60, 7, 0.10, 'insurance'], 'auto' => [25, 4, 0.12, 'auto'], 'fmcg' => [48, 12, 0.20, 'FMCG'],
    'metals' => [11, 1.6, 0.15, 'metals'], 'oil_upstream' => [8, 1.0, 0.25, 'upstream oil'], 'omc' => [9, 1.4, 0.05, 'refining/marketing'], 'energy' => [18, 2.2, 0.20, 'energy'],
    'power' => [18, 2.5, 0.28, 'power utility'], 'realty' => [35, 3.5, 0.25, 'real estate'], 'capgoods' => [45, 7, 0.12, 'capital goods'], 'cement' => [35, 4, 0.16, 'cement'],
    'chemicals' => [35, 5, 0.16, 'chemicals'], 'paints' => [55, 12, 0.18, 'paints'], 'aviation' => [25, 10, 0.12, 'aviation'], 'telecom' => [40, 6, 0.25, 'telecom'],
    'consumer' => [55, 10, 0.10, 'consumer discretionary'], 'jewellery' => [70, 20, 0.10, 'jewellery'], 'healthcare' => [60, 9, 0.18, 'hospitals'], 'media' => [20, 2.5, 0.15, 'media'],
    'defence' => [45, 8, 0.20, 'defence'], 'infra' => [25, 3, 0.12, 'infrastructure'], 'diversified' => [24, 3.5, 0.15, 'market'],
  ];
  $r = $N[$k] ?? $N['diversified'];
  return ['pe' => $r[0], 'pb' => $r[1], 'opm' => $r[2], 'label' => $r[3]];
}

/* =====================================================================
   MACRO — international & national factors → one market regime score,
   plus how each sector is exposed to each driver.
   ===================================================================== */

/* sign = what a RISE in this instrument usually means for Indian equities */
function mk_macro_catalog() {
  return [
    /* international equities */
    ['sym' => '^GSPC', 'label' => 'S&P 500', 'group' => 'Global equities', 'driver' => 'global_eq', 'sign' => 1, 'why' => 'US risk appetite sets the tone for FII flows into emerging markets.'],
    ['sym' => '^IXIC', 'label' => 'Nasdaq', 'group' => 'Global equities', 'driver' => 'global_eq', 'sign' => 1, 'why' => 'Tech sentiment — closely tracked by Indian IT.'],
    ['sym' => '^DJI', 'label' => 'Dow Jones', 'group' => 'Global equities', 'driver' => 'global_eq', 'sign' => 1, 'why' => 'US blue-chip mood.'],
    ['sym' => '^FTSE', 'label' => 'FTSE 100', 'group' => 'Global equities', 'driver' => 'global_eq', 'sign' => 1, 'why' => 'European risk appetite.'],
    ['sym' => '^GDAXI', 'label' => 'DAX', 'group' => 'Global equities', 'driver' => 'global_eq', 'sign' => 1, 'why' => 'European industrial cycle.'],
    ['sym' => '^N225', 'label' => 'Nikkei 225', 'group' => 'Asian markets', 'driver' => 'asia', 'sign' => 1, 'why' => 'Asia opens before India — sets the morning tone.'],
    ['sym' => '^HSI', 'label' => 'Hang Seng', 'group' => 'Asian markets', 'driver' => 'asia', 'sign' => 1, 'why' => 'China/HK sentiment; competes with India for EM allocations.'],
    ['sym' => '000001.SS', 'label' => 'Shanghai Comp.', 'group' => 'Asian markets', 'driver' => 'asia', 'sign' => 1, 'why' => 'Chinese demand drives metals and commodities.'],
    ['sym' => '^KS11', 'label' => 'KOSPI', 'group' => 'Asian markets', 'driver' => 'asia', 'sign' => 1, 'why' => 'EM tech/export cycle.'],
    /* volatility */
    ['sym' => '^VIX', 'label' => 'CBOE VIX (US fear)', 'group' => 'Volatility & risk', 'driver' => 'us_vix', 'sign' => -1, 'why' => 'Rising US fear → global de-risking → FII selling in India.'],
    ['sym' => '^INDIAVIX', 'label' => 'India VIX', 'group' => 'Volatility & risk', 'driver' => 'india_vix', 'sign' => -1, 'why' => 'Expected Nifty volatility over 30 days; spikes accompany sell-offs.'],
    /* rates & dollar */
    ['sym' => '^TNX', 'label' => 'US 10-yr yield', 'group' => 'Rates & dollar', 'driver' => 'us10y', 'sign' => -1, 'why' => 'Higher US yields pull money out of emerging markets and pressure valuations.'],
    ['sym' => '^IRX', 'label' => 'US 3-mo T-bill', 'group' => 'Rates & dollar', 'driver' => 'us_short', 'sign' => -1, 'why' => 'Tracks Fed policy expectations.'],
    ['sym' => 'DX-Y.NYB', 'label' => 'US Dollar Index', 'group' => 'Rates & dollar', 'driver' => 'dxy', 'sign' => -1, 'why' => 'A strong dollar drains EM liquidity and weakens the rupee.'],
    /* currency */
    ['sym' => 'INR=X', 'label' => 'USD/INR', 'group' => 'Rupee', 'driver' => 'usdinr', 'sign' => -1, 'why' => 'A weaker rupee (USD/INR up) signals FII outflows and imported inflation; helps exporters (IT, pharma).'],
    ['sym' => 'EURINR=X', 'label' => 'EUR/INR', 'group' => 'Rupee', 'driver' => 'eurinr', 'sign' => -0.3, 'why' => 'Euro-rupee — matters for European exporters.'],
    /* commodities */
    ['sym' => 'BZ=F', 'label' => 'Brent crude', 'group' => 'Commodities', 'driver' => 'crude', 'sign' => -1, 'why' => 'India imports ~85% of its oil: costlier crude widens the deficit, lifts inflation, hurts the rupee.'],
    ['sym' => 'CL=F', 'label' => 'WTI crude', 'group' => 'Commodities', 'driver' => 'crude', 'sign' => -1, 'why' => 'US oil benchmark.'],
    ['sym' => 'NG=F', 'label' => 'Natural gas', 'group' => 'Commodities', 'driver' => 'natgas', 'sign' => -0.3, 'why' => 'Input cost for city gas, fertiliser and power.'],
    ['sym' => 'GC=F', 'label' => 'Gold', 'group' => 'Commodities', 'driver' => 'gold', 'sign' => -0.3, 'why' => 'Safe-haven demand rises when investors are nervous.'],
    ['sym' => 'SI=F', 'label' => 'Silver', 'group' => 'Commodities', 'driver' => 'silver', 'sign' => 0, 'why' => 'Industrial + precious metal.'],
    ['sym' => 'HG=F', 'label' => 'Copper', 'group' => 'Commodities', 'driver' => 'copper', 'sign' => 0.3, 'why' => '"Dr Copper" — a global growth barometer; lifts metal stocks.'],
    ['sym' => 'BTC-USD', 'label' => 'Bitcoin', 'group' => 'Volatility & risk', 'driver' => 'btc', 'sign' => 0.1, 'why' => 'Speculative risk appetite gauge.'],
    /* India */
    ['sym' => '^NSEI', 'label' => 'Nifty 50', 'group' => 'India', 'driver' => 'nifty', 'sign' => 1, 'why' => 'The benchmark.'],
    ['sym' => '^BSESN', 'label' => 'Sensex', 'group' => 'India', 'driver' => 'sensex', 'sign' => 1, 'why' => 'BSE benchmark.'],
    ['sym' => '^NSEBANK', 'label' => 'Bank Nifty', 'group' => 'India', 'driver' => 'banknifty', 'sign' => 1, 'why' => 'Banks are ~1/3 of Nifty — the market rarely rallies without them.'],
    ['sym' => '^NSEMDCP50', 'label' => 'Nifty Midcap 50', 'group' => 'India', 'driver' => 'midcap', 'sign' => 1, 'why' => 'Risk appetite of domestic investors.'],
    ['sym' => '^CNXSC', 'label' => 'Nifty Smallcap 100', 'group' => 'India', 'driver' => 'smallcap', 'sign' => 1, 'why' => 'Retail speculation gauge.'],
  ];
}
/* Nifty sector indices on Yahoo */
function mk_sector_indices() {
  return ['it' => ['^CNXIT', 'IT'], 'banks' => ['^NSEBANK', 'Banks'], 'psubanks' => ['^CNXPSUBANK', 'PSU Banks'], 'financials' => ['NIFTY_FIN_SERVICE.NS', 'Financial Services'],
    'auto' => ['^CNXAUTO', 'Auto'], 'pharma' => ['^CNXPHARMA', 'Pharma'], 'fmcg' => ['^CNXFMCG', 'FMCG'], 'metals' => ['^CNXMETAL', 'Metal'], 'energy' => ['^CNXENERGY', 'Energy'],
    'realty' => ['^CNXREALTY', 'Realty'], 'infra' => ['^CNXINFRA', 'Infrastructure'], 'media' => ['^CNXMEDIA', 'Media'], 'psu' => ['^CNXPSE', 'PSE'], 'consumption' => ['^CNXCONSUM', 'Consumption']];
}
/* How each sector reacts when a driver RISES (+ benefits, − hurts). The driver's
   own move is used here, not its India-sign, so "crude +0.8" reads naturally:
   upstream oil gains when crude rises. */
function mk_sector_sensitivity() {
  return [
    'it' => ['label' => 'IT services', 'idx' => 'it', 'drivers' => ['usdinr' => 0.6, 'global_eq' => 0.5, 'us10y' => -0.2, 'dxy' => 0.2], 'story' => 'Earns in dollars: gains from a weaker rupee and strong US tech spending.'],
    'pharma' => ['label' => 'Pharma', 'idx' => 'pharma', 'drivers' => ['usdinr' => 0.5, 'india_vix' => 0.2, 'global_eq' => 0.1], 'story' => 'Export-heavy and defensive: weaker rupee helps, holds up in sell-offs.'],
    'banks' => ['label' => 'Private banks', 'idx' => 'banks', 'drivers' => ['us10y' => -0.3, 'usdinr' => -0.3, 'global_eq' => 0.3, 'india_vix' => -0.4, 'crude' => -0.2], 'story' => 'Biggest FII holding: sensitive to flows, rates and credit growth.'],
    'psubanks' => ['label' => 'PSU banks', 'idx' => 'psubanks', 'drivers' => ['india_vix' => -0.4, 'us10y' => -0.2, 'global_eq' => 0.2, 'crude' => -0.2], 'story' => 'High-beta domestic cyclicals; bond-yield and asset-quality sensitive.'],
    'financials' => ['label' => 'NBFC / financials', 'idx' => 'financials', 'drivers' => ['us10y' => -0.4, 'india_vix' => -0.3, 'usdinr' => -0.2], 'story' => 'Borrow to lend: falling rates widen margins.'],
    'insurance' => ['label' => 'Insurance', 'idx' => 'financials', 'drivers' => ['us10y' => -0.2, 'india_vix' => -0.3], 'story' => 'Long-duration businesses; like stable, falling yields.'],
    'auto' => ['label' => 'Automobiles', 'idx' => 'auto', 'drivers' => ['crude' => -0.4, 'copper' => -0.2, 'usdinr' => -0.2, 'global_eq' => 0.2], 'story' => 'Fuel prices hit demand; metal prices hit margins; rural income matters.'],
    'fmcg' => ['label' => 'FMCG', 'idx' => 'fmcg', 'drivers' => ['crude' => -0.3, 'india_vix' => 0.25, 'usdinr' => -0.1], 'story' => 'Defensive; crude-linked packaging costs; depends on rural demand & monsoon.'],
    'metals' => ['label' => 'Metals & mining', 'idx' => 'metals', 'drivers' => ['copper' => 0.8, 'asia' => 0.5, 'dxy' => -0.4, 'global_eq' => 0.3], 'story' => 'Priced globally: China demand and the dollar decide.'],
    'oil_upstream' => ['label' => 'Oil & gas producers', 'idx' => 'energy', 'drivers' => ['crude' => 0.8, 'natgas' => 0.3], 'story' => 'ONGC/Oil India realise more when crude rises.'],
    'omc' => ['label' => 'Refiners / OMCs', 'idx' => 'energy', 'drivers' => ['crude' => -0.8, 'usdinr' => -0.3], 'story' => 'BPCL/HPCL/IOC: costlier crude squeezes marketing margins.'],
    'energy' => ['label' => 'Energy (diversified)', 'idx' => 'energy', 'drivers' => ['crude' => 0.1, 'global_eq' => 0.2], 'story' => 'Mixed exposure to oil, gas, retail, telecom.'],
    'gas' => ['label' => 'Gas utilities', 'idx' => 'energy', 'drivers' => ['natgas' => -0.5, 'crude' => -0.1], 'story' => 'City-gas margins shrink when LNG costs rise.'],
    'power' => ['label' => 'Power & utilities', 'idx' => 'energy', 'drivers' => ['natgas' => -0.2, 'us10y' => -0.2, 'india_vix' => -0.2], 'story' => 'Capex-heavy, rate-sensitive, riding India’s power demand.'],
    'realty' => ['label' => 'Real estate', 'idx' => 'realty', 'drivers' => ['us10y' => -0.5, 'india_vix' => -0.4], 'story' => 'Most rate-sensitive sector: home-loan rates drive demand.'],
    'capgoods' => ['label' => 'Capital goods', 'idx' => 'infra', 'drivers' => ['global_eq' => 0.2, 'india_vix' => -0.3, 'crude' => -0.1], 'story' => 'Government & private capex cycle.'],
    'infra' => ['label' => 'Infrastructure', 'idx' => 'infra', 'drivers' => ['india_vix' => -0.3, 'us10y' => -0.2, 'crude' => -0.2], 'story' => 'Order books tied to government spending; bitumen/fuel costs.'],
    'defence' => ['label' => 'Defence', 'idx' => 'psu', 'drivers' => ['india_vix' => -0.2], 'story' => 'Government orders & indigenisation; geopolitics can lift sentiment.'],
    'cement' => ['label' => 'Cement', 'idx' => 'infra', 'drivers' => ['crude' => -0.4, 'natgas' => -0.2], 'story' => 'Energy is ~30% of cost (pet coke, diesel).'],
    'chemicals' => ['label' => 'Chemicals', 'idx' => null, 'drivers' => ['crude' => -0.3, 'usdinr' => 0.2, 'asia' => 0.2], 'story' => 'Crude-derived inputs; export-oriented; China competition.'],
    'paints' => ['label' => 'Paints', 'idx' => 'consumption', 'drivers' => ['crude' => -0.7], 'story' => '~50% of raw materials are crude derivatives.'],
    'aviation' => ['label' => 'Aviation', 'idx' => null, 'drivers' => ['crude' => -0.8, 'usdinr' => -0.4], 'story' => 'Jet fuel is the biggest cost; leases are in dollars.'],
    'telecom' => ['label' => 'Telecom', 'idx' => null, 'drivers' => ['india_vix' => 0.1, 'us10y' => -0.1], 'story' => 'Defensive cash flows; tariff hikes drive earnings.'],
    'consumer' => ['label' => 'Consumer discretionary', 'idx' => 'consumption', 'drivers' => ['crude' => -0.2, 'india_vix' => -0.2], 'story' => 'Urban spending, festive demand, inflation.'],
    'jewellery' => ['label' => 'Jewellery', 'idx' => 'consumption', 'drivers' => ['gold' => 0.2, 'india_vix' => -0.2], 'story' => 'Gold price vs wedding demand.'],
    'healthcare' => ['label' => 'Hospitals', 'idx' => 'pharma', 'drivers' => ['india_vix' => 0.15], 'story' => 'Defensive, structural growth.'],
    'media' => ['label' => 'Media', 'idx' => 'media', 'drivers' => ['global_eq' => 0.1], 'story' => 'Ad-spend cycle.'],
    'diversified' => ['label' => 'Diversified', 'idx' => null, 'drivers' => ['global_eq' => 0.3, 'india_vix' => -0.2], 'story' => 'Moves with the broad market.'],
  ];
}

/* One instrument's move, normalised by its own volatility so a 1% move in
   the VIX and a 1% move in the Dow are not treated alike. */
function mk_instrument(array $C, array $def) {
  $c = $C['c']; $n = count($c); if ($n < 3) return null;
  $last = $c[$n - 1]; $prev = $c[$n - 2];
  $dr = []; for ($i = max(1, $n - 61); $i < $n; $i++) $dr[] = $c[$i] / $c[$i - 1] - 1;
  $sd = mk_stdev(array_slice($dr, 0, -1)) ?: 0.01;
  $d1 = $last / $prev - 1; $d5 = $n > 5 ? $last / $c[$n - 6] - 1 : null; $d21 = $n > 21 ? $last / $c[$n - 22] - 1 : null;
  $z1 = $d1 / $sd; $z21 = $d21 !== null ? $d21 / ($sd * sqrt(21)) : 0;
  $move = mk_clamp(0.6 * tanh($z1 / 1.5) + 0.4 * tanh($z21 / 1.5)); // instrument's own direction
  $sma50 = $n >= 50 ? array_sum(array_slice($c, -50)) / 50 : null;
  return ['sym' => $def['sym'], 'label' => $def['label'], 'group' => $def['group'], 'driver' => $def['driver'], 'why' => $def['why'],
          'last' => round($last, 4), 'chg_1d' => round($d1 * 100, 2), 'chg_5d' => $d5 === null ? null : round($d5 * 100, 2), 'chg_1m' => $d21 === null ? null : round($d21 * 100, 2),
          'z_1d' => round($z1, 2), 'move' => round($move, 3), 'impact' => round($move * $def['sign'], 3), 'sign' => $def['sign'],
          'trend' => $sma50 === null ? null : ($last > $sma50 ? 'above 50-DMA' : 'below 50-DMA'),
          'spark' => array_map(function ($x) { return round($x, 4); }, array_slice($c, -30)), 'as_of' => gmdate('Y-m-d H:i', $C['t'][$n - 1] + MK_IST)];
}

/* India's own macro numbers, typed in by the user (RBI, CPI, GDP, PMI...).
   Each is scored against sensible Indian ranges; blank ones are skipped. */
function mk_india_inputs_score(array $in) {
  $F = []; $num = function ($k) use ($in) { return (isset($in[$k]) && $in[$k] !== '' && is_numeric($in[$k])) ? (float) $in[$k] : null; };
  $add = function ($key, $label, $score, $value, $note) use (&$F) { $F[] = ['key' => $key, 'label' => $label, 'score' => round(mk_clamp($score), 3), 'value' => $value, 'note' => $note]; };
  if (($x = $num('cpi')) !== null) $add('cpi', 'CPI inflation', $x < 2 ? 0.2 : ($x <= 4.5 ? 0.8 : ($x <= 6 ? 0 : -0.8)), $x . '%', $x <= 4.5 ? 'Within/under RBI’s 4% target band — room for rate cuts.' : ($x > 6 ? 'Above RBI’s 6% upper band — rate hikes risk.' : 'Upper half of the 2–6% band.'));
  if (($x = $num('gdp')) !== null) $add('gdp', 'Real GDP growth', tanh(($x - 6.5) / 1.2), $x . '%', $x >= 7 ? 'Robust growth supports earnings.' : ($x < 5.5 ? 'Slowdown — earnings downgrades likely.' : 'Trend-level growth.'));
  if (($x = $num('pmi_mfg')) !== null) $add('pmi_mfg', 'Manufacturing PMI', tanh(($x - 53) / 3), $x, $x > 50 ? 'Expansion.' : 'Contraction.');
  if (($x = $num('pmi_svc')) !== null) $add('pmi_svc', 'Services PMI', tanh(($x - 54) / 3), $x, $x > 50 ? 'Expansion.' : 'Contraction.');
  if (($x = $num('iip')) !== null) $add('iip', 'IIP (industrial output)', tanh(($x - 4) / 3), $x . '%', 'Factory output growth.');
  if (($x = $num('gst')) !== null) $add('gst', 'GST collections growth', tanh(($x - 9) / 5), $x . '%', 'Proxy for consumption & formal activity.');
  if (($x = $num('fiscal_deficit')) !== null) $add('fd', 'Fiscal deficit (% GDP)', tanh((5 - $x) / 1), $x . '%', $x <= 4.5 ? 'Consolidating — supports bond yields & ratings.' : 'High deficit crowds out private borrowing.');
  if (($x = $num('cad')) !== null) $add('cad', 'Current account (% GDP)', tanh(($x + 1.5) / 1), $x . '%', $x < -2.5 ? 'Wide deficit — rupee vulnerable.' : 'Comfortable external position.');
  if (($x = $num('monsoon')) !== null) $add('monsoon', 'Monsoon (% of LPA)', tanh(($x - 96) / 6), $x . '%', $x >= 96 ? 'Normal/above — good for rural demand, food inflation.' : 'Deficient — rural stress, food inflation risk.');
  if (($x = $num('repo')) !== null) $add('repo', 'RBI repo rate', tanh((6 - $x) / 1), $x . '%', 'Lower policy rates lift valuations and credit growth.');
  if (!empty($in['rbi_stance'])) { $s = strtolower($in['rbi_stance']); $sc = strpos($s, 'eas') !== false || strpos($s, 'accom') !== false ? 0.8 : (strpos($s, 'tight') !== false || strpos($s, 'withdraw') !== false ? -0.8 : 0);
    $add('stance', 'RBI policy stance', $sc, $in['rbi_stance'], $sc > 0 ? 'Easing cycle — historically good for banks, autos, realty.' : ($sc < 0 ? 'Tightening — pressure on rate-sensitive sectors.' : 'Neutral.')); }
  if (($x = $num('fii_month')) !== null) $add('fii_m', 'FII net flow, month (₹ cr)', tanh($x / 20000), number_format($x), $x >= 0 ? 'Foreign money coming in.' : 'Foreign money leaving.');
  if (($x = $num('dii_month')) !== null) $add('dii_m', 'DII net flow, month (₹ cr)', tanh($x / 30000) * 0.6, number_format($x), 'Domestic funds (SIP flows) cushioning the market.');
  return ['score' => $F ? round(array_sum(array_column($F, 'score')) / count($F), 3) : null, 'factors' => $F];
}

/* News sentiment: finance lexicon over headlines, plus topic tags */
function mk_news_score(array $items) {
  $pos = ['surge' => 2, 'soar' => 2, 'rally' => 1.5, 'record high' => 2, 'all-time high' => 2, 'jump' => 1.5, 'gain' => 1, 'gains' => 1, 'rise' => 1, 'rises' => 1, 'climb' => 1, 'up ' => 0.5,
    'beat' => 1.5, 'beats' => 1.5, 'upgrade' => 1.5, 'strong' => 1, 'robust' => 1, 'growth' => 0.5, 'profit rises' => 1.5, 'bullish' => 1.5, 'outperform' => 1, 'boost' => 1, 'rebound' => 1.2,
    'recovery' => 1, 'inflow' => 1, 'inflows' => 1, 'rate cut' => 1.5, 'cuts rate' => 1.5, 'cools' => 1, 'eases' => 0.8, 'approval' => 1, 'order win' => 1.5, 'bags order' => 1.5, 'wins order' => 1.5,
    'expansion' => 0.8, 'buyback' => 1.2, 'dividend' => 0.6, 'upbeat' => 1, 'optimism' => 1, 'stimulus' => 1, 'deal' => 0.6, 'higher' => 0.5, 'advances' => 0.8, 'breakout' => 1, 'buy' => 0.6, 'positive' => 0.8, 'green' => 0.5, 'ceasefire' => 1.2, 'trade deal' => 1.5];
  $neg = ['plunge' => 2, 'crash' => 2.5, 'slump' => 2, 'tumble' => 2, 'sell-off' => 1.8, 'selloff' => 1.8, 'fall' => 1, 'falls' => 1, 'decline' => 1, 'drop' => 1, 'drops' => 1, 'slips' => 0.8, 'sheds' => 1, 'sinks' => 1.5,
    'loss' => 1, 'losses' => 1, 'weak' => 1, 'miss' => 1.2, 'misses' => 1.2, 'downgrade' => 1.5, 'bearish' => 1.5, 'underperform' => 1, 'outflow' => 1.2, 'outflows' => 1.2, 'rate hike' => 1.5, 'hikes rate' => 1.5,
    'inflation rises' => 1.2, 'war' => 1.5, 'conflict' => 1.2, 'attack' => 1.5, 'sanction' => 1.2, 'sanctions' => 1.2, 'tariff' => 1, 'tariffs' => 1, 'recession' => 2, 'slowdown' => 1.2, 'default' => 1.5,
    'fraud' => 2, 'probe' => 1.2, 'raid' => 1.5, 'penalty' => 1, 'ban' => 1, 'resigns' => 1, 'lower' => 0.5, 'warning' => 1, 'concern' => 0.8, 'fear' => 1.2, 'fears' => 1.2, 'volatile' => 0.6, 'pressure' => 0.8,
    'worst' => 1.5, 'lowest' => 1, 'red' => 0.5, 'sell' => 0.6, 'cut target' => 1, 'crisis' => 1.8, 'tension' => 1, 'tensions' => 1, 'slide' => 1, 'bleed' => 1.5];
  $topics = [
    'RBI & rates' => '/\b(rbi|repo|monetary policy|mpc|rate cut|rate hike|liquidity)\b/i', 'US Fed' => '/\b(fed|fomc|powell|federal reserve|us rates?)\b/i',
    'Inflation' => '/\b(inflation|cpi|wpi|prices)\b/i', 'Crude oil' => '/\b(crude|oil prices?|brent|opec)\b/i', 'Rupee & FX' => '/\b(rupee|forex|dollar|usd\/inr)\b/i',
    'FII / DII flows' => '/\b(fii|fpi|dii|foreign investors?|foreign funds?)\b/i', 'Earnings' => '/\b(q[1-4]|results?|earnings|profit|revenue)\b/i',
    'Geopolitics' => '/\b(war|conflict|israel|iran|russia|ukraine|china|border|sanctions?|missile|attack|ceasefire)\b/i', 'Trade & tariffs' => '/\b(tariffs?|trade deal|exports?|imports?|wto)\b/i',
    'Govt policy & budget' => '/\b(budget|gst|pli|policy|reform|disinvest|government|cabinet|capex)\b/i', 'Monsoon & rural' => '/\b(monsoon|rainfall|kharif|rabi|rural)\b/i',
    'Regulation (SEBI)' => '/\b(sebi|regulator|f&o|derivatives rules?)\b/i', 'IPOs' => '/\b(ipo|listing|primary market)\b/i', 'Elections' => '/\b(election|poll results?|exit poll)\b/i',
  ];
  $out = []; $sum = 0.0; $n = 0; $tagCount = []; $tagScore = [];
  foreach ($items as $it) {
    $t = ' ' . strtolower(html_entity_decode(strip_tags($it['title'] ?? ''), ENT_QUOTES)) . ' ';
    $p = $q = 0.0;
    foreach ($pos as $w => $wt) if (strpos($t, ' ' . $w) !== false) $p += $wt;
    foreach ($neg as $w => $wt) if (strpos($t, ' ' . $w) !== false) $q += $wt;
    if (preg_match('/\b(not|no|fails? to|despite)\b/', $t)) { $tmp = $p; $p = $p * 0.6 + $q * 0.2; $q = $q * 0.6 + $tmp * 0.2; }
    $s = ($p + $q) > 0 ? ($p - $q) / ($p + $q) : 0.0;
    $tags = []; foreach ($topics as $name => $re) if (preg_match($re, $t)) { $tags[] = $name; $tagCount[$name] = ($tagCount[$name] ?? 0) + 1; $tagScore[$name] = ($tagScore[$name] ?? 0) + $s; }
    $row = $it; $row['sentiment'] = round($s, 2); $row['tags'] = $tags; $out[] = $row;
    if ($p + $q > 0) { $sum += $s; $n++; }
  }
  $tagsOut = []; foreach ($tagCount as $k => $c) $tagsOut[] = ['topic' => $k, 'count' => $c, 'sentiment' => round($tagScore[$k] / $c, 2)];
  usort($tagsOut, function ($a, $b) { return $b['count'] <=> $a['count']; });
  return ['score' => $n ? round($sum / $n * min(1, $n / 8), 3) : 0.0, 'scored' => $n, 'items' => $out, 'topics' => $tagsOut];
}

/* Combine every driver into a regime score for Indian equities. */
function mk_regime(array $instruments, array $extra) {
  $by = []; foreach ($instruments as $x) if ($x) $by[$x['driver']][] = $x;
  $drv = []; foreach ($by as $d => $xs) $drv[$d] = ['move' => mk_mean(array_column($xs, 'move')), 'impact' => mk_mean(array_column($xs, 'impact'))];
  $W = ['global_eq' => [0.16, 'Global equities'], 'asia' => [0.09, 'Asian markets'], 'us_vix' => [0.07, 'US volatility (VIX)'], 'india_vix' => [0.09, 'India VIX'],
        'us10y' => [0.08, 'US bond yields'], 'dxy' => [0.06, 'Dollar index'], 'usdinr' => [0.08, 'Rupee'], 'crude' => [0.10, 'Crude oil'], 'gold' => [0.02, 'Gold (safe haven)'], 'copper' => [0.02, 'Copper (growth)']];
  $comp = [];
  foreach ($W as $k => $w) if (isset($drv[$k])) $comp[] = ['key' => $k, 'label' => $w[1], 'weight' => $w[0], 'score' => round($drv[$k]['impact'], 3)];
  foreach ($extra as $e) if ($e['score'] !== null) $comp[] = $e;
  $tw = array_sum(array_column($comp, 'weight')); $s = 0.0; foreach ($comp as $c) $s += $c['weight'] * $c['score'];
  $score = $tw > 0 ? round($s / $tw, 3) : 0.0;
  $label = $score >= 0.35 ? 'Strong risk-on' : ($score >= 0.12 ? 'Risk-on' : ($score > -0.12 ? 'Neutral / mixed' : ($score > -0.35 ? 'Risk-off' : 'Strong risk-off')));
  $advice = $score >= 0.12 ? 'Tailwinds dominate: favour long setups, buy dips in leaders, let winners run.'
    : ($score > -0.12 ? 'Mixed cues: be selective, trade smaller, prefer stocks with their own strength.' : 'Headwinds dominate: protect capital, tighten stops, prefer defensives (FMCG, pharma, IT on a weak rupee) or cash; intraday shorts on weak stocks.');
  usort($comp, function ($a, $b) { return abs($b['weight'] * $b['score']) <=> abs($a['weight'] * $a['score']); });
  return ['score' => $score, 'label' => $label, 'advice' => $advice, 'components' => $comp, 'drivers' => $drv];
}

/* Sector tailwinds: driver moves × sensitivities, blended with each sector index's relative strength */
function mk_sector_view(array $drivers, array $sectorRS) {
  $out = [];
  foreach (mk_sector_sensitivity() as $k => $S) {
    $s = 0.0; $why = [];
    foreach ($S['drivers'] as $d => $beta) {
      if (!isset($drivers[$d])) continue; $m = $drivers[$d]['move']; $c = $beta * $m; $s += $c;
      if (abs($c) >= 0.08) $why[] = mk_driver_name($d) . ($m >= 0 ? ' up' : ' down') . ($c >= 0 ? ' helps' : ' hurts');
    }
    $macro = tanh($s * 1.5); $rs = $S['idx'] && isset($sectorRS[$S['idx']]) ? $sectorRS[$S['idx']] : null;
    $score = $rs === null ? $macro : 0.55 * $macro + 0.45 * $rs['score'];
    $out[$k] = ['key' => $k, 'label' => $S['label'], 'story' => $S['story'], 'macro' => round($macro, 3), 'momentum' => $rs, 'score' => round($score, 3), 'why' => $why];
  }
  uasort($out, function ($a, $b) { return $b['score'] <=> $a['score']; });
  return $out;
}
function mk_driver_name($d) {
  $m = ['global_eq' => 'Global stocks', 'asia' => 'Asian markets', 'us_vix' => 'US VIX', 'india_vix' => 'India VIX', 'us10y' => 'US yields', 'dxy' => 'Dollar', 'usdinr' => 'USD/INR', 'crude' => 'Crude',
        'gold' => 'Gold', 'copper' => 'Copper', 'natgas' => 'Nat gas', 'us_short' => 'US short rates'];
  return $m[$d] ?? $d;
}
/* sector index relative strength vs Nifty over 1 month and 3 months */
function mk_sector_rs(array $C, array $nifty) {
  list($a, $b) = mk_align($C, $nifty); $n = count($a); if ($n < 64) return null;
  $r21 = $a[$n - 1] / $a[$n - 22] - ($b[$n - 1] / $b[$n - 22]); $r63 = $a[$n - 1] / $a[$n - 64] - ($b[$n - 1] / $b[$n - 64]);
  $d1 = ($a[$n - 1] / $a[$n - 2] - 1) * 100;
  return ['rs_1m' => round($r21 * 100, 2), 'rs_3m' => round($r63 * 100, 2), 'chg_1d' => round($d1, 2), 'score' => round(tanh(($r21 * 0.6 + $r63 * 0.4) / 0.04), 3)];
}

/* =====================================================================
   LONG-TERM VERDICT — fundamentals + trend + momentum + macro/sector
   ===================================================================== */
function mk_longterm(array $tech, array $fund = null, array $sector = null, $regime = null, $capital = 100000, $riskPct = 1.0) {
  $F = []; $byKey = []; foreach ($tech['factors'] as $f) $byKey[$f['key']] = $f['score'];
  $trend = mk_mean(array_filter([$byKey['ma'] ?? null, $byKey['st'] ?? null, $byKey['adx'] ?? null, $tech['structure']['score'] ?? null], function ($x) { return $x !== null; })) ?? 0;
  $mom = mk_mean(array_filter([$byKey['mom'] ?? null, $byKey['rs'] ?? null, $byKey['52w'] ?? null], function ($x) { return $x !== null; })) ?? 0;
  $parts = [];
  if ($fund && $fund['available']) $parts[] = ['key' => 'fund', 'label' => 'Fundamentals (value, quality, growth, health)', 'weight' => 0.40 * max(0.5, $fund['coverage']), 'score' => $fund['score']];
  $parts[] = ['key' => 'trend', 'label' => 'Long-term trend (DMAs, Supertrend, structure)', 'weight' => 0.25, 'score' => round($trend, 3)];
  $parts[] = ['key' => 'mom', 'label' => 'Momentum & relative strength', 'weight' => 0.17, 'score' => round($mom, 3)];
  if ($sector) $parts[] = ['key' => 'sector', 'label' => 'Sector tailwind (' . $sector['label'] . ')', 'weight' => 0.10, 'score' => $sector['score']];
  if ($regime !== null) $parts[] = ['key' => 'macro', 'label' => 'Macro regime (global + India)', 'weight' => 0.08, 'score' => $regime];
  $tw = array_sum(array_column($parts, 'weight')); $s = 0.0; foreach ($parts as $p) $s += $p['weight'] * $p['score']; $score = round($s / $tw, 3);
  foreach ($parts as &$p) $p['weight'] = round($p['weight'] / $tw, 3); unset($p);
  $rating = $score >= 0.40 ? 'STRONG BUY' : ($score >= 0.20 ? 'BUY' : ($score >= 0.07 ? 'ACCUMULATE ON DIPS' : ($score > -0.12 ? 'HOLD' : ($score > -0.30 ? 'REDUCE' : 'SELL / AVOID'))));
  $px = $tech['price']; $atr = $tech['indicators']['atr'] ?: $px * 0.02;
  /* target: blend analyst mean target with a volatility-scaled projection of the score */
  $vol = ($tech['risk']['volatility_ann_pct'] ?? 30) / 100; $exp = $score * $vol * 1.2;
  $techTarget = $px * (1 + $exp);
  $at = $fund['values']['target_mean'] ?? null;
  $target = $at ? 0.5 * $at + 0.5 * $techTarget : $techTarget;
  if ($score > 0.07) $target = max($target, $px * 1.05);
  $sup = $tech['levels']['support'][0]['level'] ?? null; $s200 = $tech['indicators']['sma200'];
  $stop = $px - 3.5 * $atr;
  if ($s200 && $s200 < $px && $s200 > $px * 0.80) $stop = max($stop, $s200 * 0.97);
  if ($sup && $sup < $px && $sup > $px * 0.80) $stop = max($stop, $sup * 0.97);
  $stop = min($stop, $px * 0.93);
  $risk = $px - $stop; $qty = $riskPct > 0 ? (int) floor($capital * $riskPct / 100 / max($risk, 0.01)) : null; if ($qty !== null) $qty = min($qty, (int) floor($capital / $px));
  $pros = $cons = [];
  $all = array_merge($tech['factors'], $fund ? $fund['factors'] : []);
  usort($all, function ($a, $b) { return abs($b['score'] * $b['weight']) <=> abs($a['score'] * $a['weight']); });
  foreach ($all as $f) { if ($f['score'] >= 0.3 && count($pros) < 6) $pros[] = $f['label'] . ': ' . $f['note']; if ($f['score'] <= -0.3 && count($cons) < 6) $cons[] = $f['label'] . ': ' . $f['note']; }
  if ($sector) { if ($sector['score'] >= 0.2) $pros[] = 'Sector tailwind: ' . $sector['label'] . ($sector['why'] ? ' — ' . implode('; ', $sector['why']) : '') . '.'; elseif ($sector['score'] <= -0.2) $cons[] = 'Sector headwind: ' . $sector['label'] . ($sector['why'] ? ' — ' . implode('; ', $sector['why']) : '') . '.'; }
  $entry = $score >= 0.07 && ($tech['indicators']['rsi'] ?? 50) > 70 ? 'Stretched (RSI > 70): stagger buys — 1/3 now, add near ₹' . round(max($tech['indicators']['ema21'] ?? $px * 0.95, $px * 0.93), 2) . ' (21-EMA) or on a breakout retest.'
    : ($score >= 0.07 ? 'Buy in 2–3 tranches over the next few weeks; add on dips toward ₹' . round(max($sup ?: $px * 0.95, $px * 0.9), 2) . '.' : ($score > -0.12 ? 'Existing holders can stay; fresh money should wait for trend confirmation (close above the 50-DMA with volume).' : 'Trim on rallies; re-assess only after price reclaims the 200-DMA.'));
  return ['rating' => $rating, 'score' => $score, 'confidence' => mk_confidence($score, array_map(function ($p) { return $p + ['score' => $p['score']]; }, $parts)),
          'horizon' => '6–18 months', 'components' => $parts, 'target' => round($target, 2), 'target_upside_pct' => round(($target / $px - 1) * 100, 1),
          'stop' => round($stop, 2), 'stop_pct' => round(($stop / $px - 1) * 100, 1), 'qty' => $qty, 'entry_plan' => $entry, 'pros' => $pros, 'cons' => $cons];
}
/* Swing (2–6 weeks) from the daily technical score */
function mk_swing(array $tech, $capital = 100000, $riskPct = 1.0, $regime = null) {
  $s = $tech['score'] + ($regime !== null ? 0.1 * $regime : 0); $s = round(mk_clamp($s), 3);
  $px = $tech['price']; $atr = $tech['indicators']['atr'];
  $action = $s >= 0.3 ? 'BUY' : ($s <= -0.3 ? 'SELL / EXIT' : ($s >= 0.12 ? 'WATCH — LONG BIAS' : ($s <= -0.12 ? 'WATCH — WEAK' : 'NO CLEAR EDGE')));
  $plan = null;
  if ($s >= 0.12 && $atr) {
    $sup = array_map(function ($x) { return $x['level']; }, $tech['levels']['support']);
    $plan = mk_trade_plan(1, $px, $atr, array_merge($sup, [$tech['indicators']['supertrend_dir'] > 0 ? $tech['indicators']['supertrend'] : null]), $capital, $riskPct, [1.5, 3.0], 3.0, 1.0);
  }
  return ['action' => $action, 'score' => $s, 'confidence' => mk_confidence($s, $tech['factors']), 'horizon' => '2–6 weeks', 'plan' => $plan];
}

/* Map a symbol / Yahoo sector+industry to one of our sector keys */
function mk_sector_of($sym, $ysector = null, $yindustry = null) {
  $base = strtoupper(preg_replace('/\.(NS|BO)$/i', '', $sym));
  $M = [
    'TCS' => 'it', 'INFY' => 'it', 'HCLTECH' => 'it', 'WIPRO' => 'it', 'TECHM' => 'it', 'LTIM' => 'it', 'PERSISTENT' => 'it', 'COFORGE' => 'it', 'MPHASIS' => 'it', 'OFSS' => 'it', 'KPITTECH' => 'it', 'TATAELXSI' => 'it',
    'HDFCBANK' => 'banks', 'ICICIBANK' => 'banks', 'KOTAKBANK' => 'banks', 'AXISBANK' => 'banks', 'INDUSINDBK' => 'banks', 'IDFCFIRSTB' => 'banks', 'FEDERALBNK' => 'banks', 'BANDHANBNK' => 'banks', 'AUBANK' => 'banks', 'YESBANK' => 'banks',
    'SBIN' => 'psubanks', 'BANKBARODA' => 'psubanks', 'PNB' => 'psubanks', 'CANBK' => 'psubanks', 'UNIONBANK' => 'psubanks', 'INDIANB' => 'psubanks', 'BANKINDIA' => 'psubanks',
    'BAJFINANCE' => 'financials', 'BAJAJFINSV' => 'financials', 'SHRIRAMFIN' => 'financials', 'JIOFIN' => 'financials', 'CHOLAFIN' => 'financials', 'MUTHOOTFIN' => 'financials', 'PFC' => 'financials', 'RECLTD' => 'financials', 'M&MFIN' => 'financials', 'LICHSGFIN' => 'financials', 'BSE' => 'financials', 'CDSL' => 'financials', 'ANGELONE' => 'financials',
    'HDFCLIFE' => 'insurance', 'SBILIFE' => 'insurance', 'ICICIPRULI' => 'insurance', 'ICICIGI' => 'insurance', 'LICI' => 'insurance',
    'MARUTI' => 'auto', 'M&M' => 'auto', 'TATAMOTORS' => 'auto', 'TMPV' => 'auto', 'BAJAJ-AUTO' => 'auto', 'EICHERMOT' => 'auto', 'HEROMOTOCO' => 'auto', 'TVSMOTOR' => 'auto', 'ASHOKLEY' => 'auto', 'BOSCHLTD' => 'auto', 'MOTHERSON' => 'auto',
    'MRF' => 'auto', 'APOLLOTYRE' => 'auto', 'BALKRISIND' => 'auto',
    'HINDUNILVR' => 'fmcg', 'ITC' => 'fmcg', 'NESTLEIND' => 'fmcg', 'BRITANNIA' => 'fmcg', 'TATACONSUM' => 'fmcg', 'DABUR' => 'fmcg', 'GODREJCP' => 'fmcg', 'MARICO' => 'fmcg', 'COLPAL' => 'fmcg', 'VBL' => 'fmcg', 'UNITDSPR' => 'fmcg',
    'SUNPHARMA' => 'pharma', 'DRREDDY' => 'pharma', 'CIPLA' => 'pharma', 'DIVISLAB' => 'pharma', 'LUPIN' => 'pharma', 'AUROPHARMA' => 'pharma', 'TORNTPHARM' => 'pharma', 'ZYDUSLIFE' => 'pharma', 'MANKIND' => 'pharma', 'ALKEM' => 'pharma',
    'APOLLOHOSP' => 'healthcare', 'MAXHEALTH' => 'healthcare', 'FORTIS' => 'healthcare',
    'TATASTEEL' => 'metals', 'JSWSTEEL' => 'metals', 'HINDALCO' => 'metals', 'VEDL' => 'metals', 'SAIL' => 'metals', 'NMDC' => 'metals', 'JINDALSTEL' => 'metals', 'NATIONALUM' => 'metals', 'HINDZINC' => 'metals', 'COALINDIA' => 'metals', 'ADANIENT' => 'metals',
    'ONGC' => 'oil_upstream', 'OIL' => 'oil_upstream', 'BPCL' => 'omc', 'HINDPETRO' => 'omc', 'IOC' => 'omc', 'RELIANCE' => 'energy', 'GAIL' => 'gas', 'IGL' => 'gas', 'MGL' => 'gas', 'PETRONET' => 'gas', 'GUJGASLTD' => 'gas',
    'NTPC' => 'power', 'POWERGRID' => 'power', 'TATAPOWER' => 'power', 'ADANIPOWER' => 'power', 'ADANIGREEN' => 'power', 'JSWENERGY' => 'power', 'NHPC' => 'power', 'TORNTPOWER' => 'power', 'SUZLON' => 'power',
    'DLF' => 'realty', 'GODREJPROP' => 'realty', 'OBEROIRLTY' => 'realty', 'PRESTIGE' => 'realty', 'LODHA' => 'realty', 'PHOENIXLTD' => 'realty',
    'LT' => 'capgoods', 'SIEMENS' => 'capgoods', 'ABB' => 'capgoods', 'BHEL' => 'capgoods', 'CGPOWER' => 'capgoods', 'CUMMINSIND' => 'capgoods', 'POLYCAB' => 'capgoods', 'HAVELLS' => 'capgoods',
    'BEL' => 'defence', 'HAL' => 'defence', 'MAZDOCK' => 'defence', 'BDL' => 'defence', 'COCHINSHIP' => 'defence',
    'ADANIPORTS' => 'infra', 'IRFC' => 'infra', 'RVNL' => 'infra', 'IRCTC' => 'infra', 'GMRAIRPORT' => 'infra',
    'ULTRACEMCO' => 'cement', 'GRASIM' => 'cement', 'SHREECEM' => 'cement', 'AMBUJACEM' => 'cement', 'ACC' => 'cement', 'DALBHARAT' => 'cement',
    'ASIANPAINT' => 'paints', 'BERGEPAINT' => 'paints', 'PIDILITIND' => 'chemicals', 'SRF' => 'chemicals', 'UPL' => 'chemicals', 'PIIND' => 'chemicals', 'DEEPAKNTR' => 'chemicals', 'TATACHEM' => 'chemicals',
    'INDIGO' => 'aviation', 'BHARTIARTL' => 'telecom', 'IDEA' => 'telecom', 'INDUSTOWER' => 'telecom',
    'TITAN' => 'jewellery', 'KALYANKJIL' => 'jewellery', 'TRENT' => 'consumer', 'DMART' => 'consumer', 'ETERNAL' => 'consumer', 'ZOMATO' => 'consumer', 'NYKAA' => 'consumer', 'DIXON' => 'consumer', 'VOLTAS' => 'consumer', 'PAGEIND' => 'consumer', 'NAUKRI' => 'consumer', 'PAYTM' => 'financials', 'POLICYBZR' => 'insurance',
    'ZEEL' => 'media', 'SUNTV' => 'media', 'PVRINOX' => 'media',
  ];
  if (isset($M[$base])) return $M[$base];
  $s = strtolower((string) $ysector); $ind = strtolower((string) $yindustry);
  if (strpos($ind, 'refining') !== false) return 'omc';
  if (strpos($ind, 'oil & gas e&p') !== false) return 'oil_upstream';
  if (strpos($ind, 'bank') !== false) return 'banks';
  if (strpos($ind, 'insurance') !== false) return 'insurance';
  if (strpos($ind, 'auto') !== false) return 'auto';
  if (strpos($ind, 'steel') !== false || strpos($ind, 'aluminum') !== false || strpos($ind, 'mining') !== false || strpos($ind, 'metals') !== false) return 'metals';
  if (strpos($ind, 'drug') !== false || strpos($ind, 'pharma') !== false) return 'pharma';
  if (strpos($ind, 'medical care') !== false || strpos($ind, 'hospital') !== false) return 'healthcare';
  if (strpos($ind, 'real estate') !== false) return 'realty';
  if (strpos($ind, 'building materials') !== false) return 'cement';
  if (strpos($ind, 'chemical') !== false) return 'chemicals';
  if (strpos($ind, 'airline') !== false) return 'aviation';
  if (strpos($ind, 'aerospace') !== false || strpos($ind, 'defense') !== false) return 'defence';
  if (strpos($ind, 'utilities') !== false || strpos($s, 'utilities') !== false) return 'power';
  if (strpos($ind, 'telecom') !== false) return 'telecom';
  if (strpos($ind, 'credit') !== false || strpos($ind, 'capital markets') !== false || strpos($ind, 'mortgage') !== false) return 'financials';
  $bySector = ['technology' => 'it', 'financial services' => 'financials', 'healthcare' => 'pharma', 'consumer defensive' => 'fmcg', 'consumer cyclical' => 'consumer', 'basic materials' => 'metals',
               'energy' => 'energy', 'industrials' => 'capgoods', 'real estate' => 'realty', 'communication services' => 'telecom', 'utilities' => 'power'];
  return $bySector[$s] ?? 'diversified';
}

/* Market clock (IST). NSE holidays are not modelled: on a holiday the page
   simply shows the last session's data. */
function mk_market_status($now = null) {
  $now = $now ?? time(); $dow = (int) gmdate('N', $now + MK_IST); $m = mk_ist_min($now);
  if ($dow >= 6) return ['open' => false, 'phase' => 'Weekend', 'ist' => gmdate('D H:i', $now + MK_IST)];
  $phase = $m < 540 ? 'Pre-market' : ($m < 555 ? 'Pre-open auction' : ($m < 930 ? 'Open' : 'Closed'));
  return ['open' => $phase === 'Open', 'phase' => $phase, 'ist' => gmdate('D H:i', $now + MK_IST)];
}
