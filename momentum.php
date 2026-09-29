<?php
/* =====================================================================
   Market Desk — Monthly momentum (momentum.php)
   ---------------------------------------------------------------------
   The one strategy that passed the lab's test (tools/strategy-lab.php,
   decided by tools/learn.php into rules.json):
     - on the first trading day of each month, rank liquid NSE stocks by
       their 12-month return skipping the last month, keeping only stocks
       above their 200-day average;
     - hold the top N (N from rules.json, 4 for a Rs 10,000 account),
       equal amounts; keep a holding while it stays in the top 20;
     - market filter: while the Nifty is below its 200-day average, hold
       cash instead.
   Delivery (CNC) trades, checked once a month. No intraday action.
   ===================================================================== */

function mom_sma_last(array $c, $n) { $m = count($c); if ($m < $n) return null; return array_sum(array_slice($c, $m - $n)) / $n; }

/* the ranking from the latest daily closes */
function mom_rank() {
  $ck = 'mom_rank|' . id_today(); $hit = mkt_cache_get($ck, 3 * 3600); if ($hit) return $hit;
  $date = id_today();
  $N = id_fetch(['^NSEI'], ['d'], $date)['^NSEI']['d'] ?? null;
  if (!$N || count($N['c']) < 200) throw new Exception('Nifty history unavailable — try again shortly.');
  $nLast = end($N['c']); $nSma = mom_sma_last($N['c'], 200);
  $D = id_fetch(id_universe(), ['d'], $date);
  $rows = []; $seen = 0;
  foreach ($D as $sym => $x) {
    $C = $x['d'] ?? null; if (!$C || count($C['c']) < 253) continue; $seen++;
    $c = $C['c']; $m = count($c); $last = $c[$m - 1]; $sma = mom_sma_last($c, 200);
    if ($last <= $sma) continue;
    $mom = $c[$m - 22] / $c[$m - 253] - 1;
    $rows[] = ['symbol' => $sym, 'name' => $x['name'] ?? $sym, 'price' => round($last, 2), 'mom_12_1' => round($mom * 100, 1), 'ret_1m' => round(($last / $c[$m - 22] - 1) * 100, 1),
               'above_200dma_pct' => round(($last / $sma - 1) * 100, 1), 'as_of' => mk_ist_date(end($C['t']))];
  }
  usort($rows, function ($a, $b) { return $b['mom_12_1'] <=> $a['mom_12_1']; });
  $out = ['market_on' => $nLast > $nSma, 'nifty' => round($nLast, 2), 'nifty_200dma' => round($nSma, 2), 'nifty_as_of' => mk_ist_date(end($N['t'])),
          'top' => array_slice($rows, 0, 20), 'ranked' => count($rows), 'screened' => $seen];
  if ($seen >= 100) mkt_cache_set($ck, $out); // don't cache a thin (feed error) run
  return $out;
}

function mom_first_weekday($ym) { $t = strtotime($ym . '-01'); while (in_array(date('N', $t), ['6', '7'], true)) $t += 86400; return date('Y-m-d', $t); }

/* the holdings for this month; rebalanced once, on the first weekday of a month */
function mom_view() {
  $R = md_rules(); $P = $R['positional'] ?? []; $slots = (int) ($P['slots'] ?? 4) ?: 4;
  $S = id_settings(); $capital = $S['capital']; $per = $capital / $slots;
  $rank = mom_rank(); $today = id_today(); $ym = substr($today, 0, 7);
  $state = json_decode((string) md_store_get('mom_state'), true);
  $next = mom_first_weekday(date('Y-m', strtotime($ym . '-01 +1 month')));
  $pick = function (array $keep) use ($rank, $slots, $per) {
    $hold = []; $top = array_column($rank['top'], null, 'symbol');
    foreach ($keep as $h) if (isset($top[$h['symbol']]) && count($hold) < $slots) $hold[] = array_merge($h, ['kept' => true]);
    foreach ($rank['top'] as $r) {
      if (count($hold) >= $slots) break;
      if (in_array($r['symbol'], array_column($hold, 'symbol'), true)) continue;
      $q = (int) floor($per / $r['price']); if ($q < 1) continue; // can't afford one share of it in an equal slot
      $hold[] = ['symbol' => $r['symbol'], 'name' => $r['name'], 'qty' => $q, 'buy_ref' => $r['price'], 'amount' => round($q * $r['price']), 'kept' => false];
    }
    return $hold;
  };
  $mode = 'current';
  if (!$state || $state['month'] !== $ym) {
    if ((int) substr($today, 8, 2) > 7 && !$state) {
      /* starting mid-month: show what the list would be, but the real start is the next month's first trading day */
      $mode = 'preview';
      $view = $rank['market_on'] ? $pick([]) : [];
      return ['mode' => $mode, 'month' => date('Y-m', strtotime($next)), 'starts' => $next, 'holdings' => $view, 'sell' => [], 'buy' => $view, 'rank' => $rank,
              'slots' => $slots, 'per_slot' => round($per), 'next_rebalance' => $next, 'evidence' => $P, 'capital' => $capital];
    }
    $prev = $state['holdings'] ?? [];
    $new = $rank['market_on'] ? $pick($prev) : [];
    $sell = array_values(array_filter($prev, function ($h) use ($new) { return !in_array($h['symbol'], array_column($new, 'symbol'), true); }));
    $buy = array_values(array_filter($new, function ($h) { return empty($h['kept']); }));
    $state = ['month' => $ym, 'rebalanced_on' => $today, 'market_on' => $rank['market_on'], 'holdings' => $new, 'sell' => $sell, 'buy' => $buy, 'announced' => false];
    md_store_set('mom_state', json_encode($state));
  }
  return ['mode' => $mode, 'month' => $state['month'], 'rebalanced_on' => $state['rebalanced_on'], 'holdings' => $state['holdings'], 'sell' => $state['sell'], 'buy' => $state['buy'],
          'market_on_at_rebalance' => $state['market_on'], 'rank' => $rank, 'slots' => $slots, 'per_slot' => round($per), 'next_rebalance' => $next, 'evidence' => $P, 'capital' => $capital];
}

/* one Telegram message per rebalance */
function mom_announce() {
  $state = json_decode((string) md_store_get('mom_state'), true);
  if (!$state || !empty($state['announced'])) return null;
  $msg = id_tg_compose(function ($lang) use ($state) { return mom_msg($state, $lang); });
  $state['announced'] = true; md_store_set('mom_state', json_encode($state));
  return $msg;
}
function mom_msg(array $state, $lang = 'en') {
  $hi = $lang === 'hi'; $t = strtotime($state['month'] . '-01');
  $hiMonths = ['जनवरी', 'फ़रवरी', 'मार्च', 'अप्रैल', 'मई', 'जून', 'जुलाई', 'अगस्त', 'सितंबर', 'अक्टूबर', 'नवंबर', 'दिसंबर'];
  $L = [$hi ? '📅 <b>मासिक मोमेंटम — ' . $hiMonths[(int) date('n', $t) - 1] . ' ' . date('Y', $t) . '</b>' : '📅 <b>Monthly momentum — ' . date('F Y', $t) . '</b>'];
  if (!$state['market_on']) $L[] = $hi ? 'बाज़ार फ़िल्टर बंद है (निफ्टी अपने 200-दिन औसत से नीचे): इस महीने <b>नकद</b> रखें।' : 'Market filter is OFF (Nifty below its 200-day average): hold <b>cash</b> this month.';
  foreach ($state['sell'] as $h) $L[] = $hi ? '🔻 ' . htmlspecialchars($h['symbol']) . ' पूरा बेचें (टॉप 20 से बाहर)' : '🔻 SELL all ' . htmlspecialchars($h['symbol']) . ' (dropped out of the top 20)';
  foreach ($state['buy'] as $h) $L[] = $hi ? '🟢 ' . htmlspecialchars($h['symbol']) . ' के ' . $h['qty'] . ' शेयर खरीदें (~' . id_money($h['amount']) . ', डिलीवरी/CNC)' : '🟢 BUY ' . $h['qty'] . ' ' . htmlspecialchars($h['symbol']) . ' (~' . id_money($h['amount']) . ', delivery/CNC)';
  foreach ($state['holdings'] as $h) if (!empty($h['kept'])) $L[] = $hi ? '✔ ' . htmlspecialchars($h['symbol']) . ' रखें' : '✔ keep ' . htmlspecialchars($h['symbol']);
  $L[] = $hi ? 'आज 9:15 बजे के बाद ऑर्डर लगाएँ। अगली जाँच: अगले महीने का पहला ट्रेडिंग दिन।' : 'Place orders after 9:15 AM today. Next check: first trading day of next month.';
  return implode("\n", $L);
}
