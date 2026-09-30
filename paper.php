<?php
/* =====================================================================
   Market Desk — Paper track record (paper.php)
   ---------------------------------------------------------------------
   A forward test from a fixed start date: what each part of the app would
   have made with real prices, with no hindsight, after charges.
     - Core portfolio: bought at the close of the start day in whole ETF
       units, then checked on the first trading day of each month and
       rebalanced only when an ETF drifts more than 5 points.
     - Monthly picks: follows the monthly momentum list exactly (buys and
       sells at the close of the rebalance day).
     - Intraday Top 10: each day's result of the locked list, as replayed
       after the close by the same engine the live page uses.
     - Nifty 50: the same money simply held, as the yardstick.
   Portfolios are valued at each day's close; daily closes arrive the next
   morning, so the record runs one session behind.
   ===================================================================== */

define('PT_START', getenv('MD_PT_START') ?: '2026-10-01'); // env override only for local testing
define('PT_BAND', 5.0);

function pt_buy_cost($v, $etf) { return $v * (($etf ? 0 : 0.001) + 0.00015 + 0.0000307 * 1.18); }        // STT (shares only), stamp, fees + GST
function pt_sell_cost($v, $etf) { return $v * (($etf ? 0.00001 : 0.001) + 0.0000307 * 1.18) + 15.93; }   // STT, fees + GST, DP charge

function pt_state() {
  $s = json_decode((string) md_store_get('paper_track'), true);
  if (is_array($s) && ($s['start'] ?? null) === PT_START) return $s;
  $c = (float) id_settings()['capital'];
  return ['start' => PT_START, 'capital' => $c, 'last' => null,
          'core' => ['cash' => $c, 'units' => [], 'month' => null, 'trades' => [], 'charges' => 0.0],
          'mom' => ['cash' => $c, 'pos' => [], 'month' => null, 'trades' => [], 'charges' => 0.0],
          'intraday' => [], 'nifty_start' => null, 'hist' => []];
}

/* daily closes by date for some symbols (up to yesterday) */
function pt_closes(array $syms) {
  $F = id_fetch(array_values(array_unique($syms)), ['d'], id_today()); $out = [];
  foreach ($syms as $s) { $C = $F[$s]['d'] ?? null; $out[$s] = [];
    if ($C) foreach ($C['t'] as $i => $t) $out[$s][mk_ist_date($t)] = (float) $C['c'][$i]; }
  return $out;
}
function pt_px(array $series, $d) { // close on day d, else the latest close before it
  if (isset($series[$d])) return $series[$d];
  $best = null; foreach ($series as $k => $v) if ($k <= $d) $best = $v; return $best;
}

/* whole units for target weights, leftover cash to whichever ETF is furthest below target */
function pt_alloc($value, array $w, array $px) {
  $u = []; $spent = 0.0;
  foreach ($w as $a => $x) { $u[$a] = $px[$a] ? (int) floor($value * $x / $px[$a]) : 0; $spent += $u[$a] * $px[$a]; }
  while (true) { $best = null; $gap = 0;
    foreach ($w as $a => $x) { if (!$px[$a] || $px[$a] > $value - $spent) continue; $g = $value * $x - $u[$a] * $px[$a]; if ($g > $gap) { $gap = $g; $best = $a; } }
    if ($best === null || $gap < $px[$best] / 2) break; $u[$best]++; $spent += $px[$best]; }
  return $u;
}

/* bring the record up to date; safe to call often (does nothing new until a new close or a new session) */
function pt_update() {
  $lock = @fopen(md_path('paper_lock'), 'c'); if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return pt_state();
  try {
    $S = pt_state(); $today = id_today();
    if ($today < PT_START) return $S;
    /* 1. intraday: record each finished session since the start (at most 3 per call) */
    $done = 0; $m = mk_ist_min(time());
    for ($d = PT_START; $d <= $today && $done < 3; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
      if (!id_is_weekday($d) || isset($S['intraday'][$d]) || ($d === $today && $m < 935)) continue;
      $day = id_day_get($d);
      if (!$day || empty($day['picks'])) { if ($d < $today) $S['intraday'][$d] = ['pnl' => 0, 'trades' => 0, 'note' => 'no list that day']; continue; }
      $lv = id_live($day, $S['capital'], id_settings()['risk_pct']); $b = $lv['book'];
      $S['intraday'][$d] = ['pnl' => round($b['pnl'] + $b['open_pnl']), 'trades' => $b['trades'], 'wins' => $b['wins'], 'losses' => $b['losses'], 'charges' => $b['charges']];
      $done++;
    }
    ksort($S['intraday']);
    /* 2. core portfolio + monthly picks + Nifty, day by day from the daily closes */
    $P = md_rules()['portfolio'] ?? null; $plan = $P['plan'] ?? null;
    $sleeves = $plan ? ($plan['method'] === 'inv_vol' ? $plan['assets'] : array_keys($plan['weights'])) : [];
    $csym = []; foreach ($sleeves as $a) $csym[$a] = core_resolve($a);
    $debtW = $plan && $plan['method'] === 'fixed' ? max(0, 1 - array_sum($plan['weights'])) : 0;
    if ($debtW > 0.001) $csym['DEBT'] = core_resolve('DEBT');
    $ms = json_decode((string) md_store_get('mom_state'), true);
    $msyms = array_merge(array_keys($S['mom']['pos']), array_column($ms['holdings'] ?? [], 'symbol'));
    $CL = pt_closes(array_merge(['^NSEI'], array_values($csym), $msyms));
    $days = array_values(array_filter(array_keys($CL['^NSEI'] ?? []), function ($d) use ($S) { return $d >= PT_START && ($S['last'] === null || $d > $S['last']); }));
    sort($days);
    foreach ($days as $d) {
      $ym = substr($d, 0, 7);
      if ($S['nifty_start'] === null) $S['nifty_start'] = $CL['^NSEI'][$d];
      /* core: start, or the monthly check on the first session of a month */
      if ($plan && $S['core']['month'] !== $ym) {
        $px = []; foreach ($csym as $a => $s) $px[$a] = pt_px($CL[$s] ?? [], $d);
        if (!in_array(null, $px, true)) {
          if ($plan['method'] === 'inv_vol') { $iv = [];
            foreach ($plan['assets'] as $a) { $ser = array_values(array_filter($CL[$csym[$a]] ?? [], function ($k) use ($d) { return $k <= $d; }, ARRAY_FILTER_USE_KEY)); $v = core_vol($ser); if ($v) $iv[$a] = 1 / $v; }
            $t = array_sum($iv); $w = []; foreach ($iv as $a => $x) $w[$a] = $x / $t;
          } else { $w = $plan['weights']; if ($debtW > 0.001) $w['DEBT'] = $debtW; }
          $C = &$S['core']; $val = $C['cash']; foreach ($C['units'] as $a => $q) $val += $q * $px[$a];
          $drift = 0.0; foreach ($w as $a => $x) $drift = max($drift, abs(($C['units'][$a] ?? 0) * $px[$a] / $val * 100 - $x * 100));
          if (!$C['units'] || $drift > PT_BAND) {
            $tgt = pt_alloc($val * 0.998, $w, $px); // keep a little cash for charges
            foreach ($C['units'] as $a => $q) { $dq = ($tgt[$a] ?? 0) - $q; if ($dq >= 0) continue; $v = -$dq * $px[$a]; $c = pt_sell_cost($v, true);
              $C['cash'] += $v - $c; $C['charges'] += $c; $C['units'][$a] = $q + $dq; $C['trades'][] = ['date' => $d, 'side' => 'SELL', 'symbol' => $csym[$a], 'qty' => -$dq, 'price' => round($px[$a], 2)]; }
            foreach ($tgt as $a => $q) { $dq = $q - ($C['units'][$a] ?? 0); if ($dq <= 0) continue; $v = $dq * $px[$a]; $c = pt_buy_cost($v, true);
              if ($v + $c > $C['cash']) { $dq = (int) floor(($C['cash'] - 1) / ($px[$a] * 1.001)); if ($dq <= 0) continue; $v = $dq * $px[$a]; $c = pt_buy_cost($v, true); }
              $C['cash'] -= $v + $c; $C['charges'] += $c; $C['units'][$a] = ($C['units'][$a] ?? 0) + $dq; $C['trades'][] = ['date' => $d, 'side' => 'BUY', 'symbol' => $csym[$a], 'qty' => $dq, 'price' => round($px[$a], 2)]; }
          }
          $C['month'] = $ym; unset($C);
        }
      }
      /* monthly picks: apply the list set on its rebalance day */
      if ($ms && ($ms['rebalanced_on'] ?? '9999') <= $d && $S['mom']['month'] !== $ms['month']) {
        $M = &$S['mom']; $keep = array_column($ms['holdings'], 'qty', 'symbol');
        foreach ($M['pos'] as $s => $p) { if (isset($keep[$s])) continue; $px = pt_px($CL[$s] ?? [], $d) ?? $p['entry']; $v = $p['qty'] * $px; $c = pt_sell_cost($v, false);
          $M['cash'] += $v - $c; $M['charges'] += $c; unset($M['pos'][$s]); $M['trades'][] = ['date' => $d, 'side' => 'SELL', 'symbol' => $s, 'qty' => $p['qty'], 'price' => round($px, 2), 'ret_pct' => round(($px / $p['entry'] - 1) * 100, 1)]; }
        foreach ($ms['holdings'] as $h) { $s = $h['symbol']; if (isset($M['pos'][$s])) continue; $px = pt_px($CL[$s] ?? [], $d); if (!$px) continue;
          $q = (int) $h['qty']; $v = $q * $px; $c = pt_buy_cost($v, false); if ($v + $c > $M['cash']) { $q = (int) floor(($M['cash'] - 1) / ($px * 1.0012)); if ($q <= 0) continue; $v = $q * $px; $c = pt_buy_cost($v, false); }
          $M['cash'] -= $v + $c; $M['charges'] += $c; $M['pos'][$s] = ['qty' => $q, 'entry' => $px, 'date' => $d]; $M['trades'][] = ['date' => $d, 'side' => 'BUY', 'symbol' => $s, 'qty' => $q, 'price' => round($px, 2)]; }
        $M['month'] = $ms['month']; unset($M);
      }
      /* value everything at this close */
      $cv = $S['core']['cash']; foreach ($S['core']['units'] as $a => $q) $cv += $q * (pt_px($CL[$csym[$a]] ?? [], $d) ?? 0);
      $mv = $S['mom']['cash']; foreach ($S['mom']['pos'] as $s => $p) $mv += $p['qty'] * (pt_px($CL[$s] ?? [], $d) ?? $p['entry']);
      $iv = $S['capital']; foreach ($S['intraday'] as $k => $x) if ($k <= $d) $iv += $x['pnl'];
      $S['hist'][$d] = ['core' => round($cv), 'mom' => round($mv), 'intraday' => round($iv), 'nifty' => round($S['capital'] * $CL['^NSEI'][$d] / $S['nifty_start'])];
      $S['last'] = $d;
    }
    md_store_set('paper_track', json_encode($S));
    return $S;
  } finally { flock($lock, LOCK_UN); fclose($lock); }
}

/* what the page shows */
function pt_view() {
  $S = pt_update(); $c = $S['capital']; $h = $S['hist']; $last = $h ? end($h) : null;
  $iv = $c; foreach ($S['intraday'] as $x) $iv += $x['pnl'];
  $row = function ($v) use ($c) { return ['value' => $v, 'pnl' => $v !== null ? round($v - $c) : null, 'pct' => $v !== null ? round(($v / $c - 1) * 100, 2) : null]; };
  $idays = array_filter($S['intraday'], function ($x) { return ($x['trades'] ?? 0) > 0; });
  return ['start' => $S['start'], 'started' => id_today() >= $S['start'], 'capital' => $c, 'as_of' => $S['last'],
    'core' => $row($last['core'] ?? null) + ['units' => $S['core']['units'], 'cash' => round($S['core']['cash']), 'charges' => round($S['core']['charges']), 'trades' => $S['core']['trades']],
    'mom' => $row($last['mom'] ?? null) + ['pos' => $S['mom']['pos'], 'cash' => round($S['mom']['cash']), 'charges' => round($S['mom']['charges']), 'trades' => $S['mom']['trades']],
    'intraday' => $row($S['intraday'] ? round($iv) : null) + ['days' => $S['intraday'], 'traded_days' => count($idays), 'green_days' => count(array_filter($idays, function ($x) { return $x['pnl'] > 0; }))],
    'nifty' => $row($last['nifty'] ?? null), 'hist' => $h];
}

/* a short Friday-evening report on Telegram */
function pt_week_msg(array $V, $lang = 'en') {
  $hi = $lang === 'hi'; $f = function ($r) { return $r['pnl'] === null ? '—' : ($r['pnl'] >= 0 ? '+' : '−') . id_money(abs($r['pnl'])) . ' (' . ($r['pct'] >= 0 ? '+' : '') . $r['pct'] . '%)'; };
  $L = [$hi ? '📒 <b>पेपर ट्रैक रिकॉर्ड</b> (' . $V['start'] . ' से, ' . id_money($V['capital']) . ' पर)' : '📒 <b>Paper track record</b> (since ' . $V['start'] . ', on ' . id_money($V['capital']) . ')'];
  $L[] = ($hi ? 'कोर पोर्टफ़ोलियो: ' : 'Core portfolio: ') . $f($V['core']);
  $L[] = ($hi ? 'मासिक चयन: ' : 'Monthly picks: ') . $f($V['mom']);
  $L[] = ($hi ? 'इंट्राडे टॉप 10: ' : 'Intraday Top 10: ') . $f($V['intraday']);
  $L[] = ($hi ? 'तुलना — निफ्टी 50: ' : 'Yardstick — Nifty 50: ') . $f($V['nifty']);
  $L[] = $hi ? 'असली भाव, चार्ज घटाकर, कोई पिछली जानकारी नहीं। पैसा नहीं लगाया गया — यह सिर्फ़ जाँच है।' : 'Real prices, after charges, no hindsight. No money was invested — this is only a test.';
  return implode("\n", $L);
}
