<?php
/* =====================================================================
   Market Desk — Intraday Top 10 (intraday.php)
   ---------------------------------------------------------------------
   Every trading day:
     1. Screen ~170 liquid F&O stocks on daily data (trend, strength vs
        Nifty, setup, liquidity, daily range, pivot width).
     2. Research the best 30 one by one: 60-day intraday backtest of the
        rules on that stock, its own news and event risk, prior-session
        5-minute behaviour, and — after the open — gap, opening range,
        VWAP, relative volume and the live intraday score.
     3. Keep the 10 best (max 3 per sector). The list is provisional before
        the open and LOCKED at the first build after 9:25 AM.
     4. All day, every pick runs through the signal engine
        (mk_intraday_replay): BUY / SELL-short on confirmed triggers, stop,
        Target 1 (book half, stop to cost), trailing stop, Target 2,
        square-off 3:15 PM.
     5. Desk rules on top: at most 5 positions at once, no new entries
        after the book has lost 3R in the day, max 20% of capital per
        position.
     6. After the close the day is written to a track record.

   Prices come from Upstox (the source that answers this server); news from
   Google News. Everything is cached in data/ so a refresh every minute
   costs ~11 small requests.
   ===================================================================== */

const ID_MAX_OPEN = 5;          // positions at once
const ID_DAY_STOP_R = -3.0;     // no new entries once the day is down this much
const ID_POS_CAP = 0.20;        // max share of capital in one position

function id_universe() {
  $n50 = mkt_universes()['nifty50']['symbols'];
  $more = ['ABB', 'ADANIENSOL', 'ADANIGREEN', 'ADANIPOWER', 'AMBUJACEM', 'AUROPHARMA', 'BANKBARODA', 'BHEL', 'BOSCHLTD', 'BPCL', 'BRITANNIA', 'CANBK', 'CHOLAFIN', 'COLPAL',
    'DABUR', 'DIVISLAB', 'DLF', 'DMART', 'GAIL', 'GODREJCP', 'HAL', 'HAVELLS', 'HDFCAMC', 'HEROMOTOCO', 'ICICIGI', 'ICICIPRULI', 'INDHOTEL', 'INDUSINDBK', 'IOC', 'IRCTC',
    'IRFC', 'JINDALSTEL', 'JSWENERGY', 'LICI', 'LODHA', 'LTIM', 'LUPIN', 'MARICO', 'MOTHERSON', 'NAUKRI', 'NHPC', 'PFC', 'PIDILITIND', 'PNB', 'POLYCAB', 'RECLTD',
    'SBICARD', 'SHREECEM', 'SIEMENS', 'SRF', 'TATAPOWER', 'TORNTPHARM', 'TVSMOTOR', 'UNITDSPR', 'VBL', 'VEDL', 'ZYDUSLIFE', 'PERSISTENT', 'COFORGE', 'MPHASIS', 'DIXON',
    'CUMMINSIND', 'PAGEIND', 'TATAELXSI', 'KPITTECH', 'IDFCFIRSTB', 'FEDERALBNK', 'AUBANK', 'BANDHANBNK', 'YESBANK', 'IDEA', 'SAIL', 'NMDC', 'NATIONALUM', 'HINDZINC',
    'HINDPETRO', 'OIL', 'PETRONET', 'IGL', 'MGL', 'CONCOR', 'ASHOKLEY', 'BALKRISIND', 'MRF', 'APOLLOTYRE', 'EXIDEIND', 'ASTRAL', 'BERGEPAINT', 'VOLTAS', 'CROMPTON',
    'DALBHARAT', 'ACC', 'UPL', 'PIIND', 'DEEPAKNTR', 'TATACHEM', 'COROMANDEL', 'GLENMARK', 'BIOCON', 'ALKEM', 'IPCALAB', 'LAURUSLABS', 'FORTIS', 'MUTHOOTFIN',
    'MANAPPURAM', 'LICHSGFIN', 'M&MFIN', 'ANGELONE', 'BSE', 'CDSL', 'MCX', 'KALYANKJIL', 'NYKAA', 'PAYTM', 'POLICYBZR', 'DELHIVERY', 'SUZLON', 'RVNL', 'IREDA',
    'MAZDOCK', 'BDL', 'COCHINSHIP', 'GMRAIRPORT', 'PRESTIGE', 'OBEROIRLTY', 'GODREJPROP', 'PHOENIXLTD', 'TATACOMM', 'INDUSTOWER', 'OFSS', 'LTF', 'ABCAPITAL',
    'SONACOMS', 'UNOMINDA', 'TIINDIA', 'SOLARINDS', 'KEI', 'CGPOWER', 'BHARATFORG', 'APLAPOLLO', 'JUBLFOOD', 'SUPREMEIND', 'TORNTPOWER', 'MANKIND', 'HUDCO', 'NBCC'];
  return array_values(array_unique(array_merge($n50, $more)));
}

/* ---------- dates & phases (IST) ---------- */
function id_today() { return gmdate('Y-m-d', time() + MK_IST); }
function id_is_weekday($d) { return (int) date('N', strtotime($d)) < 6; }
function id_next_weekday($d) { do { $d = date('Y-m-d', strtotime($d . ' +1 day')); } while (!id_is_weekday($d)); return $d; }
function id_prev_weekday($d) { do { $d = date('Y-m-d', strtotime($d . ' -1 day')); } while (!id_is_weekday($d)); return $d; }
function id_phase() {
  $d = id_today(); $m = mk_ist_min(time());
  if (!id_is_weekday($d)) return ['date' => id_prev_weekday($d), 'phase' => 'closed', 'label' => 'Weekend — showing the last session'];
  if ($m < 555) return ['date' => $d, 'phase' => 'preopen', 'label' => 'Pre-market — provisional watchlist from yesterday\'s data'];
  if ($m < 565) return ['date' => $d, 'phase' => 'opening', 'label' => 'Opening range forming — list locks at 9:25 AM'];
  if ($m < 930) return ['date' => $d, 'phase' => 'live', 'label' => 'Market open — live signals'];
  return ['date' => $d, 'phase' => 'closed', 'label' => 'Market closed — today\'s results'];
}

/* ---------- data: Upstox with per-part caching ---------- */
function id_fetch(array $syms, array $parts, $date) {
  $base = 'https://api.upstox.com'; $isToday = $date === id_today();
  $from = function ($days) use ($date) { return date('Y-m-d', strtotime($date . " -$days days")); };
  $want = []; $out = [];
  foreach ($syms as $sym) {
    $key = mkt_upstox_key(mkt_norm_symbol($sym) ?: $sym); if (!$key) continue; $ek = rawurlencode($key[0]);
    $out[$sym] = ['key' => $key[0], 'name' => $key[1]];
    foreach ($parts as $p) {
      $ck = "id|$p|$sym|$date"; $ttl = ($p === 't5' && $isToday) ? (mk_market_status()['open'] ? 45 : 900) : 72000;
      $hit = mkt_cache_get($ck, $ttl); if ($hit !== null) { $out[$sym][$p] = $hit; continue; }
      if ($p === 'd') $u = "$base/v2/historical-candle/$ek/day/" . $from(1) . '/' . $from(420);                 // daily, up to yesterday
      elseif ($p === 'p5') $u = "$base/v3/historical-candle/$ek/minutes/5/" . $from(1) . '/' . $from(9);         // 5-minute, previous sessions
      elseif ($p === 'h15') $u = "$base/v3/historical-candle/$ek/minutes/15/" . $from(1) . '/' . $from(29);        // 15-minute, last month (backtest)
      elseif ($p === 'h15b') $u = "$base/v3/historical-candle/$ek/minutes/15/" . $from(30) . '/' . $from(58);      // and the month before
      elseif ($p === 't5') $u = $isToday ? "$base/v3/historical-candle/intraday/$ek/minutes/5" : "$base/v3/historical-candle/$ek/minutes/5/$date/$date";
      else continue;
      $want["$sym|$p"] = ['url' => $u, 'headers' => ['Accept: application/json'], 'ck' => $ck];
    }
  }
  if ($want) {
    $res = mkt_http_multi($want, 20, 8);
    foreach ($want as $k => $r) {
      list($sym, $p) = explode('|', $k); $C = ($res[$k]['code'] ?? 0) === 200 ? mkt_upstox_parse($res[$k]['body']) : null;
      if ($C !== null) { $C = mk_clean($C); mkt_cache_set($r['ck'], $C); }
      $out[$sym][$p] = $C;
    }
  }
  return $out;
}
function id_join(array $a = null, array $b = null) {
  if (!$a || !count($a['c'] ?? [])) return $b; if (!$b || !count($b['c'] ?? [])) return $a;
  return mkt_candles_merge($a, $b);
}

/* ---------- news per stock, in one parallel batch ---------- */
function id_news(array $picks) {
  $reqs = []; $out = [];
  foreach ($picks as $sym => $name) {
    $ck = "idnews|$sym|" . id_today(); $hit = mkt_cache_get($ck, 3600); if ($hit) { $out[$sym] = $hit; continue; }
    $clean = trim(preg_replace('/\b(limited|ltd\.?|corporation|corp\.?|industries)\b/i', '', (string) $name)) ?: $sym;
    $reqs[$sym] = ['url' => 'https://news.google.com/rss/search?q=' . rawurlencode('"' . $clean . '" (share OR stock OR NSE) when:3d') . '&hl=en-IN&gl=IN&ceid=IN:en', 'ck' => $ck];
  }
  if ($reqs) {
    $res = mkt_http_multi($reqs, 12, 8);
    foreach ($reqs as $sym => $r) {
      $items = ($res[$sym]['code'] ?? 0) === 200 ? mkt_parse_rss($res[$sym]['body'], 'Google News') : [];
      $sc = mk_news_score(array_slice($items, 0, 15)); $flags = [];
      foreach ($sc['items'] as $it) {
        $t = strtolower($it['title']);
        if (preg_match('/\b(results?|earnings|board meeting)\b.{0,25}\b(today|tomorrow)\b|\b(today|tomorrow)\b.{0,25}\b(results?|earnings|board meeting)\b|\bto (announce|declare|report)\b.{0,20}\b(results?|earnings)\b/', $t)) $flags['results_due'] = 'Results / board meeting due today or tomorrow';
        elseif (preg_match('/\b(q[1-4] results?|quarterly results|earnings|net profit|revenue)\b/', $t)) $flags['results'] = 'Recent results in the news (catalyst)';
        if (preg_match('/\b(block deal|bulk deal|stake sale|ofs|offer for sale)\b/', $t)) $flags['block'] = 'Block/bulk deal or stake sale';
        if (preg_match('/\b(order|contract)\b.*\b(win|wins|bags|secures|receives)\b|\b(wins|bags|secures)\b.*\b(order|contract)\b/', $t)) $flags['order'] = 'Order win';
        if (preg_match('/\b(sebi|probe|raid|fraud|penalty|ban|default)\b/', $t)) $flags['reg'] = 'Regulatory / legal risk';
        if (preg_match('/\b(upgrade|downgrade|target price|raises target|cuts target)\b/', $t)) $flags['broker'] = 'Broker rating change';
        if (preg_match('/\b(dividend|bonus|split|buyback)\b/', $t)) $flags['corp'] = 'Corporate action';
      }
      $v = ['score' => $sc['score'], 'scored' => $sc['scored'], 'flags' => array_values($flags), 'results_risk' => isset($flags['results_due']),
            'headlines' => array_map(function ($i) { return ['title' => $i['title'], 'link' => $i['link'], 'ts' => $i['ts'], 'sentiment' => $i['sentiment']]; }, array_slice($sc['items'], 0, 4))];
      mkt_cache_set($r['ck'], $v); $out[$sym] = $v;
    }
  }
  return $out;
}

/* ---------- market context (cheap: uses Market Pulse's cache when present) ---------- */
function id_market_context(array $nifty) {
  $macro = mkt_cache_get('macro_v1', 6 * 3600); $ctx = ['regime' => null, 'label' => null, 'sectors' => []];
  if ($macro) { $ctx['regime'] = $macro['regime']['score'] ?? null; $ctx['label'] = $macro['regime']['label'] ?? null; foreach (($macro['sectors'] ?? []) as $s) $ctx['sectors'][$s['key']] = $s['score']; }
  if ($ctx['regime'] === null && !empty($nifty['d']) && count($nifty['d']['c']) > 210) {
    $T = mk_tech_score_at(mk_daily_arrays($nifty['d']), count($nifty['d']['c']) - 1); $ctx['regime'] = round($T['score'] * 0.6, 3); $ctx['label'] = 'From Nifty trend (open Market pulse for the full regime)';
  }
  return $ctx;
}

/* ---------- selection ---------- */
function id_select($date, $phase) {
  @set_time_limit(240);
  $U = id_universe(); $live = in_array($phase, ['opening', 'live'], true) && $date === id_today();
  $N = id_fetch(['^NSEI'], $live ? ['d', 'p5', 't5'] : ['d', 'p5'], $date)['^NSEI'] ?? [];
  $ctx = id_market_context($N);
  $D = id_fetch($U, $live ? ['d', 't5'] : ['d'], $date);
  $cands = []; $rejected = [];
  foreach ($D as $sym => $x) {
    if (empty($x['d']) || count($x['d']['c']) < 60) { $rejected[$sym] = 'no price history'; continue; }
    $st = mk_daily_setup($x['d'], $N['d'] ?? null); if (!$st) continue;
    if ($st['close'] < 50) { $rejected[$sym] = 'price below ₹50'; continue; }
    if ($st['turnover_cr'] < 25) { $rejected[$sym] = 'illiquid (₹' . $st['turnover_cr'] . ' cr/day)'; continue; }
    if (($st['atr_pct'] ?? 0) < 0.8) { $rejected[$sym] = 'moves too little (ATR ' . $st['atr_pct'] . '%)'; continue; }
    $lite = null; $T = $x['t5'] ?? null;
    if ($live && $T && count($T['c']) >= 3) {
      $px = end($T['c']); $pv = $vv = 0.0; for ($i = 0; $i < count($T['c']); $i++) { $tp = ($T['h'][$i] + $T['l'][$i] + $T['c'][$i]) / 3; $pv += $tp * $T['v'][$i]; $vv += $T['v'][$i]; }
      $vw = $vv > 0 ? $pv / $vv : $px; $chg = ($px / $st['pdc'] - 1) * 100; $gap = ($T['o'][0] / $st['pdc'] - 1) * 100;
      if (abs($gap) > 5) { $rejected[$sym] = sprintf('gapped %+.1f%% — too extended to chase', $gap); continue; }
      $lite = ['score' => round(mk_clamp(0.6 * tanh($chg / 1.2) + 0.4 * tanh(($px - $vw) / max(0.001, $st['atr'] * 0.15))), 3), 'gap' => round($gap, 2), 'price' => $px, 'open' => $T['o'][0], 'rvol' => null];
    }
    $sk = mk_sector_of($sym . '.NS');
    $P = mk_pick_score($st, null, $lite, null, $ctx['sectors'][$sk] ?? null, $ctx['regime']);
    $cands[$sym] = ['sym' => $sym, 'name' => $x['name'], 'sector' => $sk, 'setup' => $st, 'pre' => $P['quality']];
  }
  uasort($cands, function ($a, $b) { return $b['pre'] <=> $a['pre']; });
  $deep = array_slice($cands, 0, 30, true);
  /* stage 2: the deep look */
  $E = id_fetch(array_keys($deep), $live ? ['p5', 'h15', 'h15b', 't5'] : ['p5', 'h15', 'h15b'], $date);
  $news = id_news(array_map(function ($c) { return $c['name']; }, $deep));
  $n5 = id_join($N['p5'] ?? null, $N['t5'] ?? null);
  $scored = [];
  foreach ($deep as $sym => $c) {
    $x = $E[$sym] ?? []; $h15 = id_join($x['h15b'] ?? null, $x['h15'] ?? null);
    $edge = ($h15 && count($h15['c']) > 200) ? mk_backtest_intraday(mk_intraday_arrays($h15)) : null;
    $liveS = null;
    if ($live && !empty($x['t5']) && count($x['t5']['c']) >= 3) {
      $sig = mk_intraday_signal(id_join($x['p5'] ?? null, $x['t5']), $n5, ['daily_score' => $c['setup']['trend'], 'market_score' => $ctx['regime']]);
      if (empty($sig['error'])) $liveS = ['score' => $sig['score'], 'rvol' => $sig['session']['rvol'], 'gap' => $sig['session']['prev_close'] ? round(($sig['session']['open'] / $sig['session']['prev_close'] - 1) * 100, 2) : 0,
                                          'price' => $sig['session']['price'], 'open' => $sig['session']['open'], 'vwap' => $sig['session']['vwap'], 'or_high' => $sig['session']['or_high'], 'or_low' => $sig['session']['or_low']];
    }
    $nw = $news[$sym] ?? null;
    if ($nw && $nw['results_risk']) { $rejected[$sym] = 'results / board meeting due — event risk'; continue; }
    $P = mk_pick_score($c['setup'], $edge, $liveS, $nw, $ctx['sectors'][$c['sector']] ?? null, $ctx['regime']);
    $scored[$sym] = $c + ['dir' => $P['dir'], 'quality' => $P['quality'], 'factors' => $P['factors'], 'edge' => $edge ? array_diff_key($edge, ['recent' => 1]) : null, 'news' => $nw, 'live_at_pick' => $liveS];
  }
  uasort($scored, function ($a, $b) { return $b['quality'] <=> $a['quality']; });
  $picks = []; $perSector = [];
  foreach ($scored as $sym => $c) {
    if (count($picks) >= 10) break;
    if (($perSector[$c['sector']] ?? 0) >= 3) continue;
    $perSector[$c['sector']] = ($perSector[$c['sector']] ?? 0) + 1;
    $label = mk_sector_sensitivity()[$c['sector']]['label'] ?? $c['sector'];
    $picks[] = ['rank' => count($picks) + 1, 'symbol' => $sym, 'name' => $c['name'], 'sector' => $label, 'sector_key' => $c['sector'], 'dir' => $c['dir'], 'quality' => $c['quality'],
                'factors' => $c['factors'], 'setup' => $c['setup'], 'edge' => $c['edge'], 'news' => $c['news'], 'live_at_pick' => $c['live_at_pick']];
  }
  $runners = array_slice(array_map(function ($c) { return ['symbol' => $c['sym'], 'dir' => $c['dir'], 'quality' => $c['quality']]; }, array_values(array_filter($scored, function ($c) use ($picks) { return !in_array($c['sym'], array_column($picks, 'symbol'), true); }))), 0, 10);
  return ['date' => $date, 'built_at' => time(), 'phase_at_build' => $phase, 'locked' => $live && mk_ist_min(time()) >= 565, 'picks' => $picks, 'runners_up' => $runners,
          'universe' => count($U), 'screened' => count($cands), 'researched' => count($deep), 'rejected' => array_slice($rejected, 0, 40, true),
          'market' => ['regime' => $ctx['regime'], 'label' => $ctx['label']]];
}

/* ---------- live signals for the locked list, with desk rules ---------- */
function id_live(array $day, $capital, $riskPct) {
  $date = $day['date']; $syms = array_column($day['picks'], 'symbol');
  $isToday = $date === id_today();
  $now = $isToday ? time() : strtotime($date . ' 15:31:00 +05:30');
  /* no hindsight: a list built (or re-picked) after the open only counts signals from that moment on */
  $builtAt = $day['repicked_at'] ?? $day['built_at'];
  $entriesFrom = gmdate('Y-m-d', $builtAt + MK_IST) === $date ? mk_ist_min($builtAt) + 5 : 0;
  if ($entriesFrom <= 575) $entriesFrom = 0;
  $X = id_fetch(array_merge(['^NSEI'], $syms), ['p5', 't5'], $date);
  $n5 = id_join($X['^NSEI']['p5'] ?? null, $X['^NSEI']['t5'] ?? null);
  $out = [];
  foreach ($day['picks'] as $p) {
    $x = $X[$p['symbol']] ?? []; $C5 = id_join($x['p5'] ?? null, $x['t5'] ?? null);
    $R = ($C5 && count($C5['c']) > 20) ? mk_intraday_replay($C5, $n5, ['now' => $now, 'date' => $date, 'bias' => $p['dir'], 'daily_score' => $p['setup']['trend'], 'market_score' => $day['market']['regime'] ?? 0,
                                                                        'capital' => $capital, 'risk_pct' => $riskPct, 'max_position' => $capital * ID_POS_CAP, 'entries_from' => $entriesFrom])
                                         : ['status' => 'NO DATA', 'events' => [], 'trades' => [], 'position' => null, 'levels' => [], 'live' => null, 'day_r' => 0, 'day_pnl' => 0];
    if (!empty($x['t5']) && count($x['t5']['c'])) { $f = max(0, count($x['t5']['c']) - 75); $R['spark'] = array_map(function ($v) { return round($v, 2); }, array_slice($x['t5']['c'], $f)); }
    $out[] = $p + ['state' => $R];
  }
  /* desk rules, applied in time order across the whole book */
  $entries = [];
  foreach ($out as $k => $p) foreach ($p['state']['trades'] as $ti => $t) $entries[] = ['k' => $k, 'ti' => $ti, 'in' => $t['entry_time'], 'out' => $t['exit_time'], 'r' => $t['r']];
  foreach ($out as $k => $p) if ($p['state']['position']) $entries[] = ['k' => $k, 'ti' => 'open', 'in' => $p['state']['position']['entry_time'], 'out' => '99:99', 'r' => 0];
  usort($entries, function ($a, $b) { return strcmp($a['in'], $b['in']); });
  $open = []; $realized = 0.0; $skip = [];
  foreach ($entries as $e) {
    $open = array_filter($open, function ($o) use ($e, &$realized) { if ($o['out'] <= $e['in']) { $realized += $o['r']; return false; } return true; });
    if ($realized <= ID_DAY_STOP_R) { $skip[] = $e + ['why' => 'daily loss limit (' . ID_DAY_STOP_R . 'R) reached']; continue; }
    if (count($open) >= ID_MAX_OPEN) { $skip[] = $e + ['why' => 'already ' . ID_MAX_OPEN . ' positions open']; continue; }
    $open[] = $e;
  }
  foreach ($skip as $s) {
    $st = &$out[$s['k']]['state'];
    $until = $s['ti'] === 'open' ? '99:99' : $s['out'];
    /* the trade never happened: drop its entry/exit/target/trail events */
    $st['events'] = array_values(array_filter($st['events'], function ($e) use ($s, $until) { return !($e['time'] >= $s['in'] && $e['time'] <= $until && $e['type'] !== 'SKIPPED'); }));
    if ($s['ti'] === 'open') { $st['skipped'][] = 'Signal at ' . $s['in'] . ' not taken: ' . $s['why'] . '.'; $st['position'] = null; }
    else { $t = $st['trades'][$s['ti']]; $st['skipped'][] = 'Signal at ' . $s['in'] . ' not taken: ' . $s['why'] . '.'; $st['day_r'] -= $t['r']; $st['day_pnl'] -= $t['pnl']; $st['trades'][$s['ti']]['skipped'] = true; }
    $st['events'][] = ['time' => $s['in'], 'type' => 'SKIPPED', 'price' => null, 'note' => 'Desk rule: ' . $s['why'] . '.'];
    usort($st['events'], function ($a, $b) { return strcmp($a['time'], $b['time']); });
    $kept = array_filter($st['trades'], function ($t) { return empty($t['skipped']); }); $r = array_sum(array_column($kept, 'r'));
    if (!$st['position']) $st['status'] = $kept ? ($r > 0.05 ? 'DONE — PROFIT' : ($r < -0.05 ? 'DONE — LOSS' : 'DONE — FLAT')) : 'SIGNAL SKIPPED';
    $st['day_r'] = round($st['day_r'], 2);
    unset($st);
  }
  $book = ['trades' => 0, 'wins' => 0, 'losses' => 0, 'r' => 0.0, 'pnl' => 0, 'open' => 0, 'open_pnl' => 0];
  foreach ($out as $p) {
    foreach ($p['state']['trades'] as $t) { if (!empty($t['skipped'])) continue; $book['trades']++; $book['r'] += $t['r']; $book['pnl'] += $t['pnl']; if ($t['r'] > 0.05) $book['wins']++; elseif ($t['r'] < -0.05) $book['losses']++; }
    if ($p['state']['position']) { $book['open']++; $book['open_pnl'] += $p['state']['position']['open_pnl']; }
  }
  $book['r'] = round($book['r'], 2);
  return ['picks' => $out, 'book' => $book, 'nifty' => $n5 ? ['price' => round(end($n5['c']), 2)] : null,
          'entries_from' => $entriesFrom ? sprintf('%02d:%02d', intdiv($entriesFrom, 60), $entriesFrom % 60) : null];
}

/* ---------- the day file, locking, and the track record ---------- */
function id_day_get($date) { $j = json_decode((string) md_store_get('id_day_' . str_replace('-', '', $date)), true); return is_array($j) ? $j : null; }
function id_day_put(array $d) { md_store_set('id_day_' . str_replace('-', '', $d['date']), json_encode($d, JSON_UNESCAPED_UNICODE)); }
function id_history() { $j = json_decode((string) md_store_get('id_history'), true); return is_array($j) ? $j : []; }
function id_finalize(array $day, array $live) {
  if (!empty($day['final'])) return $day;
  if (empty($day['locked'])) { $day['final'] = true; $day['not_recorded'] = 'List was not locked during the session, so it is not part of the track record.'; id_day_put($day); return $day; }
  $rows = [];
  foreach ($live['picks'] as $p) $rows[] = ['symbol' => $p['symbol'], 'dir' => $p['dir'], 'status' => $p['state']['status'], 'r' => $p['state']['day_r'],
    'trades' => array_values(array_map(function ($t) { return array_intersect_key($t, array_flip(['side', 'entry', 'entry_time', 'exit', 'exit_time', 'reason', 'r'])); }, array_filter($p['state']['trades'], function ($t) { return empty($t['skipped']); })))];
  $b = $live['book'];
  $H = array_values(array_filter(id_history(), function ($h) use ($day) { return $h['date'] !== $day['date']; }));
  $H[] = ['date' => $day['date'], 'trades' => $b['trades'], 'wins' => $b['wins'], 'losses' => $b['losses'], 'r' => $b['r'], 'picks' => $rows];
  usort($H, function ($a, $b) { return strcmp($a['date'], $b['date']); });
  md_store_set('id_history', json_encode(array_slice($H, -250), JSON_UNESCAPED_UNICODE));
  $day['final'] = true; id_day_put($day);
  return $day;
}
function id_track() {
  $H = id_history(); $n = count($H); if (!$n) return ['days' => 0];
  $t = array_sum(array_column($H, 'trades')); $w = array_sum(array_column($H, 'wins')); $l = array_sum(array_column($H, 'losses')); $r = array_sum(array_column($H, 'r'));
  $green = count(array_filter($H, function ($h) { return $h['r'] > 0; }));
  return ['days' => $n, 'trades' => $t, 'win_rate' => $t ? round($w / $t * 100, 1) : null, 'wins' => $w, 'losses' => $l, 'total_r' => round($r, 2), 'avg_r_per_trade' => $t ? round($r / $t, 2) : null,
          'green_days' => $green, 'recent' => array_reverse(array_slice(array_map(function ($h) { return array_diff_key($h, ['picks' => 1]); }, $H), -20))];
}

function id_top10($force, $capital, $riskPct) {
  $ph = id_phase(); $date = $ph['date']; $day = id_day_get($date);
  if ($ph['phase'] === 'closed' && (!$day || empty($day['picks']))) {
    /* nothing was picked during that session: prepare the next one instead of a hindsight list */
    $date = id_next_weekday(id_today()); // 'closed' only happens after 3:30 PM or at weekends, so the next session is after today
    $ph = ['date' => $date, 'phase' => 'preopen', 'label' => 'Next session watchlist (' . date('D j M', strtotime($date)) . ') — provisional, locks at 9:25 AM'];
    $day = id_day_get($date);
  }
  /* a past session never finalised (nobody looked after the close): replay it now */
  foreach ([id_prev_weekday($date)] as $pd) { $pdDay = id_day_get($pd); if ($pdDay && empty($pdDay['final']) && !empty($pdDay['picks'])) id_finalize($pdDay, id_live($pdDay, $capital, $riskPct)); }
  $stale = !$day || empty($day['picks'])
        || (!$day['locked'] && in_array($ph['phase'], ['preopen', 'opening'], true) && time() - $day['built_at'] > ($ph['phase'] === 'preopen' ? 1800 : 240))
        || (!$day['locked'] && $ph['phase'] === 'live');
  if ($ph['phase'] === 'closed' && $day && !empty($day['picks'])) $stale = false;
  if ($force && $ph['phase'] !== 'closed') $stale = true;
  $built = false;
  if ($stale) {
    $lock = @fopen(md_path('id_lock'), 'c');
    if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
      $sel = id_select($date, $ph['phase']);
      if ($day && $day['locked'] && $force) $sel['repicked_at'] = time();
      $day = $sel; id_day_put($day); $built = true; flock($lock, LOCK_UN);
    } elseif (!$day) throw new Exception('Another request is researching today\'s list right now — try again in a minute.');
    if ($lock) fclose($lock);
  }
  if (!$day || empty($day['picks'])) throw new Exception('No stock passed the filters — the price feed may be down. Try again shortly.');
  $live = id_live($day, $capital, $riskPct);
  if ($ph['phase'] === 'closed' && $date === id_today() && mk_ist_min(time()) >= 935) $day = id_finalize($day, $live);
  return ['phase' => $ph, 'day' => array_diff_key($day, ['picks' => 1]), 'built_now' => $built, 'entries_from' => $live['entries_from'], 'picks' => $live['picks'], 'book' => $live['book'], 'nifty' => $live['nifty'],
          'rules' => ['max_open' => ID_MAX_OPEN, 'day_stop_r' => ID_DAY_STOP_R, 'pos_cap_pct' => ID_POS_CAP * 100], 'track' => id_track(), 'server_time' => time()];
}
