<?php
/* =====================================================================
   Market Desk — Core portfolio (core.php)
   ---------------------------------------------------------------------
   What advisors, business schools, economists and bank risk desks agree
   on, once tested (tools/pro-lab.php, decided daily by tools/learn.php
   into rules.json → "portfolio"): spread the money across assets that
   do not fall together, and rebalance.
   The plan is a mix of ETFs (Nifty 50, Midcap 150, Nasdaq 100, gold and,
   for some mixes, a liquid fund for the debt share). Bought once as
   delivery (CNC), checked on the first trading day of each month, and
   rebalanced only when a holding drifts more than 5 points from its
   target share. No timing, no daily watching.
   ===================================================================== */

/* the ETF used for each sleeve (first one the instrument list knows) */
function core_etfs() {
  return [
    'NIFTY'  => ['syms' => ['NIFTYBEES', 'SETFNIF50'], 'what' => ['Nifty 50 — India\'s 50 largest companies', 'निफ्टी 50 — भारत की 50 सबसे बड़ी कंपनियाँ']],
    'MID'    => ['syms' => ['MID150BEES', 'MIDCAPETF', 'MID150CASE'], 'what' => ['Nifty Midcap 150 — the next 150 companies', 'निफ्टी मिडकैप 150 — अगली 150 कंपनियाँ']],
    'NASDAQ' => ['syms' => ['MON100', 'MAFANG'], 'what' => ['Nasdaq 100 — large US companies (in rupees)', 'नैस्डैक 100 — अमेरिका की बड़ी कंपनियाँ (रुपये में)']],
    'GOLD'   => ['syms' => ['GOLDBEES', 'SETFGOLD'], 'what' => ['Gold', 'सोना']],
    'DEBT'   => ['syms' => ['LIQUIDBEES', 'LIQUIDCASE'], 'what' => ['Liquid fund — the safe / debt share', 'लिक्विड फ़ंड — सुरक्षित / डेट हिस्सा']],
  ];
}
function core_resolve($sleeve) {
  $c = core_etfs()[$sleeve]['syms'];
  foreach ($c as $s) if (mkt_upstox_key($s . '.NS')) return $s;
  return $c[0]; // not in the instrument list right now: still name it (price shows as unavailable)
}
function core_vol(array $c, $n = 252) {
  $m = count($c); if ($m < $n + 1) return null; $r = [];
  for ($i = $m - $n; $i < $m; $i++) if ($c[$i - 1] > 0) $r[] = log($c[$i] / $c[$i - 1]);
  $mu = array_sum($r) / count($r); $v = 0.0; foreach ($r as $x) $v += ($x - $mu) ** 2; return sqrt($v / count($r) * 252);
}

function core_view() {
  $R = md_rules(); $P = $R['portfolio'] ?? null;
  if (!$P || empty($P['plan'])) return ['tradeable' => false, 'evidence' => $P];
  $plan = $P['plan']; $S = id_settings(); $capital = $S['capital']; $date = id_today();
  $sleeves = $plan['method'] === 'inv_vol' ? $plan['assets'] : array_keys($plan['weights']);
  $syms = []; foreach ($sleeves as $a) { $s = core_resolve($a); if ($s) $syms[$a] = $s; }
  $debtW = $plan['method'] === 'fixed' ? max(0, 1 - array_sum($plan['weights'])) : 0;
  if ($debtW > 0.001) { $s = core_resolve('DEBT'); if ($s) $syms['DEBT'] = $s; }
  $ck = 'core|' . $date . '|' . md5(json_encode($plan)); $D = mkt_cache_get($ck, 3 * 3600);
  if (!$D) {
    $F = id_fetch(array_values($syms), ['d'], $date); $D = [];
    foreach ($syms as $a => $s) { $C = $F[$s]['d'] ?? null; if ($C && count($C['c'])) $D[$a] = ['c' => $C['c'], 'as_of' => mk_ist_date(end($C['t'])), 'name' => $F[$s]['name'] ?? $s]; }
    if (count($D) === count($syms)) mkt_cache_set($ck, $D);
  }
  /* target shares */
  $w = [];
  if ($plan['method'] === 'inv_vol') {
    $iv = []; foreach ($plan['assets'] as $a) { $v = isset($D[$a]) ? core_vol($D[$a]['c']) : null; if ($v) $iv[$a] = 1 / $v; }
    $t = array_sum($iv); foreach ($iv as $a => $x) $w[$a] = $x / $t;
  } else { $w = $plan['weights']; if ($debtW > 0.001) $w['DEBT'] = $debtW; }
  $rows = []; $spent = 0.0; $E = core_etfs();
  foreach ($w as $a => $x) {
    $px = isset($D[$a]) ? end($D[$a]['c']) : null; $amt = $capital * $x; $u = $px ? (int) floor($amt / $px) : 0;
    $rows[] = ['sleeve' => $a, 'symbol' => $syms[$a] ?? null, 'name' => $D[$a]['name'] ?? null, 'what' => $E[$a]['what'], 'target_pct' => round($x * 100, 1),
               'price' => $px ? round($px, 2) : null, 'as_of' => $D[$a]['as_of'] ?? null, 'units' => $u, 'amount' => $px ? round($u * $px) : null];
    if ($px) $spent += $u * $px;
  }
  /* whole units leave cash over: add one unit at a time to whichever ETF is furthest below its target, while affordable */
  while (true) {
    $best = null; $gap = 0;
    foreach ($rows as $i => $r) { if (!$r['price'] || $r['price'] > $capital - $spent) continue; $g = $capital * $r['target_pct'] / 100 - $r['units'] * $r['price']; if ($g > $gap) { $gap = $g; $best = $i; } }
    if ($best === null || $gap < $rows[$best]['price'] / 2) break; // stop when a unit would overshoot its target by more than it fills
    $rows[$best]['units']++; $rows[$best]['amount'] = round($rows[$best]['units'] * $rows[$best]['price']); $spent += $rows[$best]['price'];
  }
  $today = $date; $ym = substr($today, 0, 7);
  $next = mom_first_weekday(date('Y-m', strtotime($ym . '-01 +1 month')));
  return ['tradeable' => !empty($P['tradeable']), 'strategy' => $P['strategy'], 'school' => $P['school'] ?? null, 'method' => $plan['method'], 'band_pts' => 5,
          'capital' => $capital, 'rows' => $rows, 'invested' => round($spent), 'left' => round($capital - $spent), 'next_check' => $next,
          'check_today' => $today === mom_first_weekday($ym), 'evidence' => $P];
}

/* one Telegram reminder a month, on the first trading day */
function core_announce() {
  $ym = substr(id_today(), 0, 7);
  if (id_today() !== mom_first_weekday($ym) || md_store_get('core_announced') === $ym) return null;
  $V = core_view(); if (empty($V['tradeable'])) return null;
  md_store_set('core_announced', $ym);
  return id_tg_compose(function ($lang) use ($V) { return core_msg($V, $lang); });
}
function core_msg(array $V, $lang = 'en') {
  $hi = $lang === 'hi';
  $L = [$hi ? '🧺 <b>कोर पोर्टफ़ोलियो — मासिक जाँच</b>' : '🧺 <b>Core portfolio — monthly check</b>'];
  $L[] = $hi ? 'लक्ष्य मिश्रण (' . id_money($V['capital']) . ' पर):' : 'Target mix (for ' . id_money($V['capital']) . '):';
  foreach ($V['rows'] as $r) $L[] = '• ' . htmlspecialchars((string) $r['symbol']) . ' ' . $r['target_pct'] . '% — ' . ($hi ? $r['units'] . ' यूनिट' : $r['units'] . ' units') . ($r['price'] ? ' @ ' . id_money($r['price']) : '');
  $L[] = $hi ? 'अगर कोई ETF अपने लक्ष्य से 5 अंक से ज़्यादा दूर है तो ही संतुलित करें (ऐप में अपनी यूनिट डालें, वह बता देगा क्या खरीदें/बेचें)। वरना कुछ न करें।'
             : 'Rebalance only if an ETF is more than 5 points off its target (enter your units in the app and it shows what to buy/sell). Otherwise do nothing.';
  return implode("\n", $L);
}
