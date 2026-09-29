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
const ID_DAY_TARGET_R = 3.0;    // stop opening new trades once the day is up this much — protect a good day
const ID_MIN_QTY = 3;           // a stock must be affordable in at least this many shares per position

/* ---------- desk settings (saved on the server so the 9:27 lock uses them) ---------- */
function id_settings() {
  $j = json_decode((string) md_store_get('desk_settings'), true); $j = is_array($j) ? $j : [];
  return ['capital' => max(1000, (float) ($j['capital'] ?? 10000)), 'risk_pct' => min(5, max(0.1, (float) ($j['risk_pct'] ?? 1))), 'leverage' => in_array($lv = (int) ($j['leverage'] ?? 1), [1, 2, 3, 4, 5], true) ? $lv : 1,
          'long_only' => !empty($j['long_only']), // "Buy only": no short selling
          'tg_lang' => in_array($j['tg_lang'] ?? 'hi', ['hi', 'en', 'both'], true) ? ($j['tg_lang'] ?? 'hi') : 'hi']; // Telegram message language
}
function id_settings_set(array $in) {
  $cur = id_settings(); foreach (['capital', 'risk_pct', 'leverage'] as $k) if (isset($in[$k]) && is_numeric($in[$k])) $cur[$k] = $in[$k] + 0;
  if (isset($in['long_only'])) $cur['long_only'] = (bool) $in['long_only'];
  if (isset($in['tg_lang']) && in_array($in['tg_lang'], ['hi', 'en', 'both'], true)) $cur['tg_lang'] = $in['tg_lang'];
  md_store_set('desk_settings', json_encode($cur)); return id_settings();
}
function id_pos_budget(array $st) { return $st['capital'] * ID_POS_CAP * $st['leverage']; }

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
  return array_values(array_unique(array_merge($n50, $more, id_darkhorse_pool())));
}
/* less-followed midcaps and smallcaps with enough liquidity to day-trade — where dark horses come from */
function id_darkhorse_pool() {
  return ['HBLENGINE', 'GRSE', 'KAYNES', 'NETWEB', 'CAMS', 'KFINTECH', 'ZENTEC', 'DATAPATTNS', 'IRCON', 'RAILTEL', 'NLCINDIA', 'SJVN', 'HINDCOPPER', 'JBMA', 'OLECTRA',
    'RADICO', 'AMBER', 'CYIENT', 'SONATSOFTW', 'INTELLECT', 'TRIDENT', 'ANANTRAJ', 'NCC', 'ENGINERSIN', 'BEML', 'TITAGARH', 'JWL', 'SCHNEIDER', 'INOXWIND', 'KPIGREEN',
    'WAAREEENER', 'PREMIERENE', 'SWIGGY', 'HYUNDAI', 'OLAELEC', 'BAJAJHFL', 'PNBHOUSING', 'CGCL', 'CHAMBLFERT', 'RCF', 'FACT', 'GNFC', 'NATCOPHARM', 'GRANULES',
    'JUBLPHARMA', 'ZENSARTECH', 'BSOFT', 'RBLBANK', 'IDBI', 'IOB', 'UCOBANK', 'CENTRALBK', 'MAHABANK', 'KARURVYSYA', 'EQUITASBNK', 'ASTERDM', 'CESC', 'NBCC', 'HUDCO', 'IRB'];
}
function id_darkhorse(array $st, $sym) {
  static $n50 = null; if ($n50 === null) $n50 = array_flip(mkt_universes()['nifty50']['symbols']);
  if (isset($n50[$sym]) || ($st['vol_ratio'] ?? 0) < 1.5) return null;
  $up = ($st['ret_5d'] ?? 0) + ($st['rs_5d'] ?? 0) >= 0;
  $brk = $up ? ($st['dist_20h'] > -1.5 ? 1 : 0) : ($st['dist_20l'] < 1.5 ? 1 : 0);
  $score = 0.35 * tanh(($st['vol_ratio'] - 1) / 1.2) + 0.25 * tanh(abs($st['rs_5d'] ?? 0) / 5) + 0.2 * $brk + 0.2 * tanh(abs($st['ret_1d'] ?? 0) / 3);
  $why = [round($st['vol_ratio'], 1) . 'x normal volume yesterday', sprintf('%+.1f pts vs Nifty in 5 days', $st['rs_5d'] ?? 0)];
  if ($brk) $why[] = $up ? 'at a 20-day breakout' : 'at a 20-day breakdown';
  if (abs($st['ret_1d'] ?? 0) >= 2) $why[] = sprintf('moved %+.1f%% yesterday', $st['ret_1d']);
  return ['score' => round($score, 3), 'dir' => $up ? 'LONG' : 'SHORT', 'why' => $why];
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
    $ck = "idnews2|$sym|" . id_today(); $hit = mkt_cache_get($ck, 3600); if ($hit) { $out[$sym] = $hit; continue; }
    /* exchange names are truncated upper-case ("CHOLAMANDALAM IN & FIN CO L"): search the ticker or the first real word of the name */
    $words = preg_split('/[^A-Za-z]+/', (string) $name, -1, PREG_SPLIT_NO_EMPTY); $w = $words[0] ?? $sym;
    if (strlen($w) < 5 && isset($words[1])) $w .= ' ' . $words[1];
    $q = '(' . $sym . ' OR "' . ucwords(strtolower($w)) . '") (share OR stock OR NSE OR results) when:3d';
    $reqs[$sym] = ['url' => 'https://news.google.com/rss/search?q=' . rawurlencode($q) . '&hl=en-IN&gl=IN&ceid=IN:en', 'ck' => $ck];
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
function id_select($date, $phase, array $prev = null) {
  @set_time_limit(240);
  $U = id_universe(); $live = in_array($phase, ['opening', 'live'], true) && $date === id_today();
  $SET = id_settings(); $budget = id_pos_budget($SET);
  $N = id_fetch(['^NSEI'], $live ? ['d', 'p5', 't5'] : ['d', 'p5'], $date)['^NSEI'] ?? [];
  $ctx = id_market_context($N);
  $D = id_fetch($U, ['d'], $date);
  /* live prices only for the stocks that can still make the list (keeps the 9:25 build fast) */
  if ($live) {
    $pre = [];
    foreach ($D as $sym => $x) if (!empty($x['d']) && count($x['d']['c']) >= 60) { $c = end($x['d']['c']); if ($c * ID_MIN_QTY <= $budget && $c >= 50) { $st0 = mk_daily_setup($x['d'], $N['d'] ?? null); if ($st0) $pre[$sym] = abs($st0['trend']) + 0.5 * abs(tanh(($st0['rs_5d'] ?? 0) / 3)) + (($st0['vol_ratio'] ?? 1) > 1.5 ? 0.3 : 0); } }
    arsort($pre); $T5 = id_fetch(array_slice(array_keys($pre), 0, 90), ['t5'], $date);
    foreach ($T5 as $sym => $x) if (isset($D[$sym])) $D[$sym]['t5'] = $x['t5'] ?? null;
  }
  $cands = []; $rejected = []; $dark = [];
  foreach ($D as $sym => $x) {
    if (empty($x['d']) || count($x['d']['c']) < 60) { $rejected[$sym] = 'no price history'; continue; }
    $st = mk_daily_setup($x['d'], $N['d'] ?? null); if (!$st) continue;
    if ($st['close'] < 50) { $rejected[$sym] = 'price below ₹50'; continue; }
    if ($st['close'] * ID_MIN_QTY > $budget) { $rejected[$sym] = 'too expensive for ₹' . number_format($budget) . ' per position (₹' . number_format($st['close']) . '/share)'; continue; }
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
    $dh = id_darkhorse($st, $sym); if ($dh) $dark[$sym] = $dh;
    $cands[$sym] = ['sym' => $sym, 'name' => $x['name'], 'sector' => $sk, 'setup' => $st, 'pre' => $P['quality'], 'dark' => $dh];
  }
  uasort($cands, function ($a, $b) { return $b['pre'] <=> $a['pre']; });
  $deep = array_slice($cands, 0, 60, true);
  /* stocks already on the list are always re-researched, so a small wobble in the first screen can't drop them */
  foreach (($prev['picks'] ?? []) as $pp) if (!isset($deep[$pp['symbol']]) && isset($cands[$pp['symbol']])) $deep[$pp['symbol']] = $cands[$pp['symbol']];
  /* dark horses get the same deep research even if they rank lower on the first screen */
  uasort($dark, function ($a, $b) { return $b['score'] <=> $a['score']; });
  foreach (array_slice(array_keys($dark), 0, 8) as $sym) if (!isset($deep[$sym]) && isset($cands[$sym])) $deep[$sym] = $cands[$sym];
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
    /* the method must have worked on this stock: no picks where the rules have been losing money */
    if ($edge && ($edge['trades'] ?? 0) >= 20 && $edge['profit_factor'] !== null && $edge['profit_factor'] < 0.9) { $rejected[$sym] = 'rules lost money here over 60 days (PF ' . $edge['profit_factor'] . ', ' . $edge['trades'] . ' trades)'; continue; }
    $P = mk_pick_score($c['setup'], $edge, $liveS, $nw, $ctx['sectors'][$c['sector']] ?? null, $ctx['regime']);
    if ($SET['long_only'] && $P['dir'] === 'SHORT') { $rejected[$sym] = 'short setup — you chose Buy only'; continue; }
    $scored[$sym] = $c + ['dir' => $P['dir'], 'quality' => $P['quality'], 'factors' => $P['factors'], 'edge' => $edge ? array_diff_key($edge, ['recent' => 1]) : null, 'news' => $nw, 'live_at_pick' => $liveS];
  }
  /* stability: a stock already on the list keeps its place unless a newcomer is clearly better (0.06 quality margin) */
  $was = array_column($prev['picks'] ?? [], 'symbol');
  foreach ($scored as $sym => $c) { $scored[$sym]['kept'] = in_array($sym, $was, true); $scored[$sym]['rank_q'] = $c['quality'] + ($scored[$sym]['kept'] ? 0.06 : 0); }
  uasort($scored, function ($a, $b) { return $b['rank_q'] <=> $a['rank_q']; });
  $picks = []; $perSector = [];
  foreach ($scored as $sym => $c) {
    if (count($picks) >= 10) break;
    if (($perSector[$c['sector']] ?? 0) >= 3) continue;
    $perSector[$c['sector']] = ($perSector[$c['sector']] ?? 0) + 1;
    $label = mk_sector_sensitivity()[$c['sector']]['label'] ?? $c['sector'];
    $picks[] = ['rank' => count($picks) + 1, 'symbol' => $sym, 'name' => $c['name'], 'sector' => $label, 'sector_key' => $c['sector'], 'dir' => $c['dir'], 'quality' => $c['quality'],
                'factors' => $c['factors'], 'setup' => $c['setup'], 'edge' => $c['edge'], 'news' => $c['news'], 'live_at_pick' => $c['live_at_pick'], 'darkhorse' => $c['dark'], 'kept' => $c['kept']];
  }
  $horses = [];
  foreach ($scored as $sym => $c) if ($c['dark']) $horses[] = ['symbol' => $sym, 'name' => $c['name'], 'dir' => $c['dir'], 'quality' => $c['quality'], 'dark_score' => $c['dark']['score'], 'why' => $c['dark']['why'],
    'price' => $c['setup']['close'], 'picked' => in_array($sym, array_column($picks, 'symbol'), true), 'edge' => $c['edge'] ? $c['edge']['trades'] . ' trades, win ' . $c['edge']['win_rate'] . '%, PF ' . $c['edge']['profit_factor'] : null];
  usort($horses, function ($a, $b) { return ($b['quality'] + $b['dark_score']) <=> ($a['quality'] + $a['dark_score']); });
  $runners = array_slice(array_map(function ($c) { return ['symbol' => $c['sym'], 'dir' => $c['dir'], 'quality' => $c['quality']]; }, array_values(array_filter($scored, function ($c) use ($picks) { return !in_array($c['sym'], array_column($picks, 'symbol'), true); }))), 0, 10);
  return ['date' => $date, 'built_at' => time(), 'phase_at_build' => $phase, 'locked' => $live && mk_ist_min(time()) >= 565, 'picks' => $picks, 'runners_up' => $runners,
          'darkhorses' => array_slice($horses, 0, 6), 'settings' => $SET, 'pos_budget' => $budget,
          'universe' => count($U), 'screened' => count($cands), 'researched' => count($deep), 'rejected' => array_slice($rejected, 0, 40, true),
          'market' => ['regime' => $ctx['regime'], 'label' => $ctx['label']]];
}

/* ---------- live signals for the locked list, with desk rules ---------- */
function id_live(array $day, $capital, $riskPct) {
  $SET = id_settings(); $capital = $SET['capital']; $riskPct = $SET['risk_pct']; $budget = id_pos_budget($SET);
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
                                                                        'capital' => $capital, 'risk_pct' => $riskPct, 'max_position' => $budget, 'entries_from' => $entriesFrom, 'long_only' => $SET['long_only'],
                                                                        'th' => md_rules()['intraday']['th'], 'max_trades' => md_rules()['intraday']['max_trades']])
                                         : ['status' => 'NO DATA', 'events' => [], 'trades' => [], 'position' => null, 'levels' => [], 'live' => null, 'day_r' => 0, 'day_pnl' => 0];
    if (!empty($x['t5']) && count($x['t5']['c'])) { $f = max(0, count($x['t5']['c']) - 75); $R['spark'] = array_map(function ($v) { return round($v, 2); }, array_slice($x['t5']['c'], $f)); }
    $px = $R['live']['price'] ?? $p['setup']['close']; $mq = (int) floor($budget / max(1, $px));
    $out[] = $p + ['state' => $R, 'plan' => ['budget' => round($budget), 'price' => $px, 'max_qty' => $mq, 'max_value' => round($mq * $px), 'risk_budget' => round($capital * $riskPct / 100)]];
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
    if ($realized >= ID_DAY_TARGET_R) { $skip[] = $e + ['why' => 'daily profit target (+' . ID_DAY_TARGET_R . 'R) reached — protecting the day']; continue; }
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
  $book = ['trades' => 0, 'wins' => 0, 'losses' => 0, 'r' => 0.0, 'pnl' => 0, 'charges' => 0.0, 'open' => 0, 'open_pnl' => 0, 'capital' => $capital];
  foreach ($out as $p) {
    foreach ($p['state']['trades'] as $t) { if (!empty($t['skipped'])) continue; $book['trades']++; $book['r'] += $t['r']; $book['pnl'] += $t['pnl']; $book['charges'] += $t['charges'] ?? 0; if ($t['pnl'] > 0) $book['wins']++; elseif ($t['pnl'] < 0) $book['losses']++; }
    if ($p['state']['position']) { $book['open']++; $book['open_pnl'] += $p['state']['position']['open_pnl']; }
  }
  $book['r'] = round($book['r'], 2); $book['charges'] = round($book['charges'], 2); $book['pnl_pct'] = round($book['pnl'] / $capital * 100, 2);
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
  $H[] = ['date' => $day['date'], 'trades' => $b['trades'], 'wins' => $b['wins'], 'losses' => $b['losses'], 'r' => $b['r'], 'pnl' => $b['pnl'], 'capital' => $b['capital'], 'charges' => $b['charges'], 'picks' => $rows];
  usort($H, function ($a, $b) { return strcmp($a['date'], $b['date']); });
  md_store_set('id_history', json_encode(array_slice($H, -250), JSON_UNESCAPED_UNICODE));
  $day['final'] = true; id_day_put($day);
  return $day;
}
function id_track() {
  $H = id_history(); $n = count($H); if (!$n) return ['days' => 0];
  $t = array_sum(array_column($H, 'trades')); $w = array_sum(array_column($H, 'wins')); $l = array_sum(array_column($H, 'losses')); $r = array_sum(array_column($H, 'r'));
  $green = count(array_filter($H, function ($h) { return $h['r'] > 0; }));
  $pnl = array_sum(array_map(function ($h) { return $h['pnl'] ?? 0; }, $H));
  return ['days' => $n, 'trades' => $t, 'win_rate' => $t ? round($w / $t * 100, 1) : null, 'wins' => $w, 'losses' => $l, 'total_r' => round($r, 2), 'avg_r_per_trade' => $t ? round($r / $t, 2) : null, 'net_pnl' => round($pnl),
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
  /* a fixed research schedule, so refreshing the page never changes the list:
       1) built once for the next session (evening / overnight)
       2) re-researched once from 8:45 AM with overnight news and global cues
       3) locked at the first build after 9:25 AM, once the opening range is known */
  $morning = strtotime($date . ' 08:45:00 Asia/Kolkata');
  $stale = !$day || empty($day['picks'])
        || (!$day['locked'] && in_array($ph['phase'], ['preopen', 'opening'], true) && time() >= $morning && $day['built_at'] < $morning)
        || (!$day['locked'] && $ph['phase'] === 'live');
  if ($ph['phase'] === 'closed' && $day && !empty($day['picks'])) $stale = false;
  /* the 9:25 lock was missed (no scheduled run): a list researched before the open is still an honest
     prediction — lock it as it is instead of re-picking late with hindsight */
  $open = strtotime($date . ' 09:15:00 Asia/Kolkata');
  if (!$force && $day && !empty($day['picks']) && !$day['locked'] && $ph['phase'] === 'live' && mk_ist_min(time()) >= 575 && $day['built_at'] < $open) {
    $day['locked'] = true; $day['locked_late'] = time(); id_day_put($day); $stale = false;
  }
  if ($force && $ph['phase'] !== 'closed') $stale = true;
  $built = false;
  if ($stale) {
    $lock = @fopen(md_path('id_lock'), 'c');
    if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
      $sel = id_select($date, $ph['phase'], ($day && !empty($day['picks'])) ? $day : null);
      if ($day && $day['locked'] && $force) $sel['repicked_at'] = time();
      /* a thin data run (feed errors) must not replace a good list */
      if ($day && !empty($day['picks']) && $sel['screened'] < 0.8 * ($day['screened'] ?? 0)) {
        $day['refresh_failed'] = ['at' => time(), 'screened' => $sel['screened']];
        if (!$day['locked'] && !empty($sel['locked'])) { $day['locked'] = true; $day['built_at'] = time(); }
      } else { $sel['builds'] = ($day['builds'] ?? 0) + 1; $day = $sel; }
      id_day_put($day); $built = true; flock($lock, LOCK_UN);
    } elseif (!$day) throw new Exception('Another request is researching today\'s list right now — try again in a minute.');
    if ($lock) fclose($lock);
  }
  if ($day && empty($day['picks']) && id_settings()['long_only'] && in_array('short setup — you chose Buy only', $day['rejected'] ?? [], true))
    throw new Exception('No stock has a clean BUY setup today — the good setups are all shorts. Sit out today, or switch Trades to "Buy & short".');
  if (!$day || empty($day['picks'])) throw new Exception('No stock passed the filters — the price feed may be down. Try again shortly.');
  $live = id_live($day, $capital, $riskPct);
  if ($ph['phase'] === 'closed' && $date === id_today() && mk_ist_min(time()) >= 935) $day = id_finalize($day, $live);
  return ['phase' => $ph, 'day' => array_diff_key($day, ['picks' => 1]), 'built_now' => $built, 'entries_from' => $live['entries_from'], 'picks' => $live['picks'], 'book' => $live['book'], 'nifty' => $live['nifty'],
          'rules' => ['max_open' => ID_MAX_OPEN, 'day_stop_r' => ID_DAY_STOP_R, 'day_target_r' => ID_DAY_TARGET_R, 'pos_cap_pct' => ID_POS_CAP * 100, 'min_qty' => ID_MIN_QTY],
          'settings' => id_settings(), 'pos_budget' => round(id_pos_budget(id_settings())), 'track' => id_track(), 'server_time' => time(),
          'learned' => ['updated' => md_rules()['updated'], 'intraday' => md_rules()['intraday'], 'swing' => md_rules()['swing'], 'last' => md_rules()['log'][0] ?? null]];
}


/* =====================================================================
   ALERTS — Telegram messages for every new signal, sent by mkt_tick.
   mkt_tick is called every minute during market hours (by the
   "Live alerts" GitHub workflow), so alerts arrive with the page closed.
   ===================================================================== */
function id_tg_api($method, array $params) {
  $tok = md_config()['telegram_token']; if ($tok === '') return ['ok' => false, 'description' => 'no bot token'];
  if (isset($GLOBALS['MD_TG_MOCK'])) return call_user_func($GLOBALS['MD_TG_MOCK'], $method, $params);
  $r = mkt_http('https://api.telegram.org/bot' . $tok . '/' . $method . '?' . http_build_query($params), ['headers' => ['Accept: application/json']]);
  return json_decode($r['body'], true) ?: ['ok' => false, 'description' => 'HTTP ' . $r['code']];
}
/* the chat to send to: learnt from the first message you send the bot */
function id_tg_chat($refresh = false) {
  $c = md_store_get('tg_chat'); if ($c && !$refresh) return $c;
  $u = id_tg_api('getUpdates', ['limit' => 20]);
  foreach (array_reverse($u['result'] ?? []) as $upd) { $id = $upd['message']['chat']['id'] ?? ($upd['my_chat_member']['chat']['id'] ?? null); if ($id) { md_store_set('tg_chat', (string) $id); return (string) $id; } }
  return $c ?: null;
}
function id_tg_send($html) {
  $chat = id_tg_chat(); if (!$chat) return ['ok' => false, 'description' => 'Open your bot in Telegram and send it any message first.'];
  return id_tg_api('sendMessage', ['chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true']);
}
function id_tg_status() {
  if (md_config()['telegram_token'] === '') return ['configured' => false, 'chat' => false];
  return ['configured' => true, 'chat' => (bool) id_tg_chat()];
}
function id_money($x) { return '₹' . number_format((float) $x, (abs($x) < 1000 && floor($x) != $x) ? 2 : 0); }
/* ---------- Telegram messages in Hindi or English (setting: tg_lang = hi | en | both) ---------- */
function id_tg_lang() { return id_settings()['tg_lang']; }
/* build a message with $build('hi'|'en'); "both" sends Hindi first, then English */
function id_tg_compose(callable $build) {
  $l = id_tg_lang(); if ($l !== 'both') return $build($l);
  $hi = $build('hi'); $en = $build('en'); return ($hi && $en) ? $hi . "\n\n— English —\n" . $en : ($hi ?: $en);
}
function id_hi_reason($why) {
  $map = ['Stop-loss hit' => 'स्टॉप-लॉस लगा', 'Target 2 hit' => 'टारगेट 2 पूरा', 'Trailing stop hit' => 'ट्रेलिंग स्टॉप लगा', 'Stopped at cost after Target 1' => 'टारगेट 1 के बाद लागत पर बाहर',
          'Square-off at 3:15 PM' => '3:15 बजे स्क्वेयर-ऑफ़'];
  if (isset($map[$why])) return $map[$why];
  if (strpos($why, 'Signal reversed') === 0) return 'सिग्नल पलट गया';
  if (strpos($why, 'daily loss limit') === 0) return 'दिन की नुकसान सीमा (' . ID_DAY_STOP_R . 'R) पूरी';
  if (strpos($why, 'daily profit target') === 0) return 'दिन का मुनाफ़ा लक्ष्य (+' . ID_DAY_TARGET_R . 'R) पूरा — दिन की कमाई सुरक्षित';
  if (preg_match('/^already (\d+) positions open/', $why, $m)) return 'पहले से ' . $m[1] . ' पोज़िशन खुली हैं';
  return $why;
}
/* one event -> one clear message */
function id_event_msg(array $p, array $e, $lang = 'en') {
  $hi = $lang === 'hi';
  $sym = htmlspecialchars($p['symbol']); $P = $p['state']['position'] ?? null; $t = $e['time'];
  switch ($e['type']) {
    case 'BUY': case 'SELL (SHORT)':
      $buy = $e['type'] === 'BUY'; $q = $P['qty'] ?? null;
      $tr = null; foreach (array_reverse($p['state']['trades'] ?? []) as $x) if ($x['entry_time'] === $t) { $tr = $x; break; }
      $pos = $P && $P['entry_time'] === $t ? $P : null; $qty = $pos ? $pos['qty'] : ($tr['qty'] ?? $q);
      $stop = $pos ? $pos['init_stop'] : null; $t1 = $pos ? $pos['t1'] : null; $t2 = $pos ? $pos['t2'] : null;
      if (!$pos && preg_match('/Stop ₹([\d.]+), T1 ₹([\d.]+), T2 ₹([\d.]+), qty (\d+)/', $e['note'], $m)) { $stop = $m[1]; $t1 = $m[2]; $t2 = $m[3]; $qty = $m[4]; }
      if ($hi) return ($buy ? "🟢 <b>खरीदें $sym</b>" : "🔻 <b>शॉर्ट करें $sym</b> (पहले बेचें, आज ही वापस खरीदें)") . " — $qty शेयर " . id_money($e['price']) . ' पर (≈' . id_money($qty * $e['price']) . ")\n"
        . 'स्टॉप-लॉस ' . id_money($stop) . ' · टारगेट 1 ' . id_money($t1) . ' (आधा ' . ($buy ? 'बेचें' : 'वापस खरीदें') . ') · टारगेट 2 ' . id_money($t2) . "\n"
        . "इंट्राडे (MIS)। स्टॉप-लॉस ऑर्डर तुरंत लगाएँ। <i>$t</i>";
      return ($buy ? "🟢 <b>BUY $sym</b>" : "🔻 <b>SHORT $sym</b> (sell first, buy back later today)") . " — $qty shares at " . id_money($e['price']) . ' (≈' . id_money($qty * $e['price']) . ")\n"
        . 'Stop-loss ' . id_money($stop) . ' · Target 1 ' . id_money($t1) . ' (' . ($buy ? 'sell' : 'buy back') . ' half) · Target 2 ' . id_money($t2) . "\n"
        . "Intraday (MIS). Place the stop-loss order right away. <i>$t</i>";
    case 'TARGET 1 HIT':
      $half = $P ? (int) floor($P['qty'] / 2) : null; $buy = $P ? $P['side'] === 'LONG' : true;
      if ($hi) return "🎯 <b>$sym — टारगेट 1 पूरा</b> " . id_money($e['price']) . " पर\nअभी आधे " . ($half ? "($half शेयर) " : '') . ($buy ? 'बेचें' : 'वापस खरीदें') . ' और स्टॉप-लॉस को अपनी एंट्री ' . ($P ? id_money($P['entry']) : '') . " पर ले आएँ। <i>$t</i>";
      return "🎯 <b>$sym — Target 1 hit</b> at " . id_money($e['price']) . "\n" . ($buy ? 'SELL' : 'BUY BACK') . ' HALF' . ($half ? " ($half shares)" : '') . ' now and move the stop-loss to your entry ' . ($P ? id_money($P['entry']) : '') . ". <i>$t</i>";
    case 'TRAIL STOP':
      if ($hi) return "🔼 <b>$sym — स्टॉप-लॉस " . id_money($e['price']) . " पर ले जाएँ</b>\nबाकी शेयरों पर मुनाफ़ा अब सुरक्षित है। <i>$t</i>";
      return "🔼 <b>$sym — move stop-loss to " . id_money($e['price']) . "</b>\nProfit on the rest is now locked in. <i>$t</i>";
    case 'EXIT — PROFIT': case 'EXIT — LOSS': case 'EXIT — FLAT':
      $icon = $e['type'] === 'EXIT — PROFIT' ? '✅' : ($e['type'] === 'EXIT — LOSS' ? '🔴' : '⚪');
      $why = preg_replace('/\s*\(.*$/', '', $e['note']); $net = preg_match('/net ₹([-\d,]+)/', $e['note'], $m) ? $m[1] : null;
      if ($hi) return "$icon <b>$sym — " . id_money($e['price']) . " पर बाहर निकलें</b> (" . id_hi_reason($why) . ")\n" . ($net !== null ? 'नतीजा: चार्ज के बाद ₹' . $net . '। ' : '') . "बाकी शेयर भी बंद करें। <i>$t</i>";
      return "$icon <b>$sym — EXIT at " . id_money($e['price']) . "</b> ($why)\n" . ($net !== null ? 'Result: ₹' . $net . ' after charges. ' : '') . "Close any remaining shares. <i>$t</i>";
    case 'SKIPPED':
      $why = preg_replace(['/^Desk rule: /', '/\.$/'], '', $e['note']);
      if ($hi) return "⏸ <b>$sym</b> — सिग्नल नहीं लिया: " . htmlspecialchars(id_hi_reason($why)) . "। <i>$t</i>";
      return "⏸ <b>$sym</b> — signal not taken: " . htmlspecialchars($why) . ". <i>$t</i>";
  }
  return null;
}
function id_list_msg(array $R, $lang = 'en') {
  $hi = $lang === 'hi'; $S = $R['settings'];
  $lines = [$hi ? '📋 <b>आज के 10 शेयर</b> — ' . id_money($S['capital']) . ' की योजना (हर शेयर में अधिकतम ' . id_money($R['pos_budget']) . ', एक साथ अधिकतम ' . $R['rules']['max_open'] . ')'
                : '📋 <b>Today\'s 10 stocks</b> — plan for ' . id_money($S['capital']) . ' (up to ' . id_money($R['pos_budget']) . ' per stock, max ' . $R['rules']['max_open'] . ' at once)'];
  foreach ($R['picks'] as $p) {
    $lv = $p['state']['levels'] ?? []; $up = $p['dir'] === 'LONG';
    $trig = $up ? ($lv['buy_above'] ?? $p['setup']['pdh']) : ($lv['sell_below'] ?? $p['setup']['pdl']);
    $lines[] = $p['rank'] . '. <b>' . htmlspecialchars($p['symbol']) . '</b>' . ($p['darkhorse'] ? ' 🐎' : '') . ' — '
      . ($hi ? ($up ? '▲ ' . id_money($trig) . ' से ऊपर हो तो खरीदें' : '▼ ' . id_money($trig) . ' से नीचे हो तो शॉर्ट करें') . ' · अधिकतम ' . $p['plan']['max_qty'] . ' शेयर'
             : ($up ? '▲ BUY if above ' : '▼ SHORT if below ') . id_money($trig) . ' · up to ' . $p['plan']['max_qty'] . ' shares');
  }
  $short = (bool) array_filter($R['picks'], function ($p) { return $p['dir'] !== 'LONG'; });
  if ($hi) {
    $lines[] = "\nकार्रवाई से पहले 'खरीदें / शॉर्ट करें' मैसेज का इंतज़ार करें — सिर्फ़ स्तर पर पहुँचना सिग्नल नहीं है।";
    if ($short) $lines[] = 'शॉर्ट = भाव गिरने पर मुनाफ़ा: पहले बेचें (इंट्राडे/MIS), 3:15 बजे से पहले वापस खरीदें। शेयर आपके पास होना ज़रूरी नहीं।';
    $lines[] = '⚠ जाँच में ये इंट्राडे नियम चार्ज के बाद घाटे में रहे — पेपर-ट्रेड की सलाह।';
  } else {
    $lines[] = "\nWait for the BUY / SHORT message before acting — a level alone is not a signal.";
    if ($short) $lines[] = 'SHORT = profit if the price falls: sell first (Intraday/MIS), buy back before 3:15 PM. You don\'t need to own the shares.';
    $lines[] = '⚠ In testing these intraday rules lost money after charges — paper-trade them.';
  }
  return implode("\n", $lines);
}
function id_summary_msg(array $R, $lang = 'en') {
  $hi = $lang === 'hi'; $b = $R['book'];
  $lines = $hi ? ['🏁 <b>दिन का सार</b> — ' . ($b['pnl'] >= 0 ? 'मुनाफ़ा ' : 'नुकसान ') . id_money($b['pnl']) . ' (' . id_money($b['charges']) . ' चार्ज के बाद, ' . sprintf('%+.2f', $b['pnl_pct']) . '%)', $b['trades'] . ' ट्रेड · ' . $b['wins'] . ' जीते · ' . $b['losses'] . ' हारे']
               : ['🏁 <b>Day summary</b> — ' . ($b['pnl'] >= 0 ? 'profit ' : 'loss ') . id_money($b['pnl']) . ' after ' . id_money($b['charges']) . ' charges (' . sprintf('%+.2f', $b['pnl_pct']) . '%)', $b['trades'] . ' trades · ' . $b['wins'] . ' won · ' . $b['losses'] . ' lost'];
  foreach ($R['picks'] as $p) foreach ($p['state']['trades'] as $t) if (empty($t['skipped']))
    $lines[] = '• ' . htmlspecialchars($p['symbol']) . ' ' . ($hi ? ($t['side'] === 'LONG' ? 'खरीद' : 'शॉर्ट') : $t['side']) . ' ' . $t['entry_time'] . '→' . $t['exit_time'] . ': ' . id_money($t['pnl']);
  return implode("\n", $lines);
}
/* called every minute: build/lock the list, work out signals, send what is new */
function id_tick() {
  $lock = @fopen(md_path('tick_lock'), 'c'); if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['busy' => true];
  try {
    $S = id_settings(); $R = id_top10(false, $S['capital'], $S['risk_pct']);
    $date = $R['day']['date']; $key = 'id_sent_' . str_replace('-', '', $date);
    $sent = json_decode((string) md_store_get($key), true) ?: ['events' => [], 'list' => false, 'summary' => false];
    $tg = id_tg_status(); $out = ['date' => $date, 'phase' => $R['phase']['phase'], 'sent' => [], 'telegram' => $tg];
    $now = mk_ist_min(time()); $isToday = $date === id_today();
    $send = function ($msg) use (&$out, $tg) { if (!$tg['chat']) { $out['sent'][] = ['queued_no_chat' => strip_tags($msg)]; return; } $r = id_tg_send($msg); $out['sent'][] = ['ok' => $r['ok'] ?? false, 'text' => strip_tags($msg), 'error' => $r['description'] ?? null]; };
    if (!empty($R['day']['locked']) && !$sent['list'] && $isToday) { $send(id_tg_compose(function ($l) use ($R) { return id_list_msg($R, $l); })); $sent['list'] = true; }
    foreach ($R['picks'] as $p) foreach ($p['state']['events'] as $e) {
      $k = $p['symbol'] . '|' . $e['time'] . '|' . $e['type']; if (isset($sent['events'][$k])) continue;
      $sent['events'][$k] = 1;
      list($h, $m) = array_map('intval', explode(':', $e['time']));
      if (!$isToday || $now - ($h * 60 + $m) > 15) continue; // old news (e.g. the job started late): record, don't spam
      $msg = id_tg_compose(function ($l) use ($p, $e) { return id_event_msg($p, $e, $l); }); if ($msg) $send($msg);
    }
    if ($isToday && $now >= 932 && !$sent['summary'] && !empty($R['day']['locked'])) { $send(id_tg_compose(function ($l) use ($R) { return id_summary_msg($R, $l); })); $sent['summary'] = true; }
    /* monthly momentum: announce a rebalance once (first trading day of the month) */
    if ($isToday && $tg['chat']) { try { mom_view(); $mm = mom_announce(); if ($mm) $send($mm); } catch (Exception $e) { $out['momentum_error'] = $e->getMessage(); } }
    md_store_set($key, json_encode($sent));
    $out['book'] = $R['book']; $out['open'] = array_values(array_map(function ($p) { return $p['symbol'] . ' ' . $p['state']['status']; }, array_filter($R['picks'], function ($p) { return !empty($p['state']['position']); })));
    return $out;
  } finally { flock($lock, LOCK_UN); fclose($lock); }
}

/* ---------- "Signals now": one press, everything actionable at this moment ----------
   Same engine and rules as the Top 10 (no second opinion that could contradict it):
   today's list plus its runners-up and dark horses, replayed up to this minute. */
function id_now() {
  $S = id_settings(); $R = id_top10(false, $S['capital'], $S['risk_pct']);
  $date = $R['day']['date']; $day = id_day_get($date);
  $items = [];
  $classify = function ($p, $src) use (&$items) {
    $st = $p['state']; $P = $st['position'] ?? null; $L = $st['live'] ?? null; $lv = $st['levels'] ?? [];
    $mins = function ($hhmm) { if (!$hhmm) return null; list($h, $m) = array_map('intval', explode(':', $hhmm)); return mk_ist_min(time()) - ($h * 60 + $m); };
    $x = ['symbol' => $p['symbol'], 'source' => $src, 'dir' => $p['dir'], 'status' => $st['status'], 'price' => $L['price'] ?? ($p['setup']['close'] ?? null), 'chg_pct' => $L['chg_pct'] ?? null,
          'qty_max' => $p['plan']['max_qty'] ?? null, 'day_pnl' => $st['day_pnl'] ?? 0];
    if ($P) {
      $age = $mins($P['entry_time'] ?? null); $px = $x['price'] ?: $P['entry'];
      $drift = $P['risk'] ? ($px - $P['entry']) * ($P['side'] === 'LONG' || $P['side'] === 1 ? 1 : -1) / $P['risk'] : 0; // in R: how far it already ran
      $side = ($P['side'] === 'LONG' || $P['side'] === 1) ? 'LONG' : 'SHORT';
      $x += ['side' => $side, 'entry' => $P['entry'], 'entry_time' => $P['entry_time'] ?? null, 'minutes_since_entry' => $age, 'stop' => $P['stop'], 't1' => $P['t1'], 't2' => $P['t2'], 'qty' => $P['qty'],
             'ran_r' => round($drift, 2), 't1_hit' => !empty($P['t1_hit'])];
      /* fresh = just fired; late = fired earlier but price is still close to the entry, so taking it now is nearly the same trade */
      $x['action'] = $age !== null && $age <= 10 ? 'NOW' : (abs($drift) <= 0.3 && mk_ist_min(time()) < 865 && empty($P['t1_hit']) ? 'LATE_OK' : 'MISSED');
    } else {
      $x['action'] = preg_match('/DONE|NO TRADE|SKIPPED/', $st['status']) ? 'DONE' : 'WAIT';
      $x['trigger'] = $p['dir'] === 'LONG' ? ($lv['buy_above'] ?? ($p['setup']['pdh'] ?? null)) : ($lv['sell_below'] ?? ($p['setup']['pdl'] ?? null));
    }
    $items[] = $x;
  };
  foreach ($R['picks'] as $p) $classify($p, 'top10');
  /* backups researched with the same list: replay them with the same engine */
  if ($day) {
    $have = array_column($R['picks'], 'symbol'); $extra = [];
    foreach (array_merge($day['runners_up'] ?? [], $day['darkhorses'] ?? []) as $r) if (!in_array($r['symbol'], $have, true) && !isset($extra[$r['symbol']])) $extra[$r['symbol']] = $r;
    $extra = array_slice($extra, 0, 16, true);
    if ($extra) {
      $Dd = id_fetch(array_keys($extra), ['d'], $date); $N = id_fetch(['^NSEI'], ['d'], $date)['^NSEI']['d'] ?? null;
      $picks = [];
      foreach ($extra as $sym => $r) { $C = $Dd[$sym]['d'] ?? null; $st = $C ? mk_daily_setup($C, $N) : null; if ($st) $picks[] = ['symbol' => $sym, 'dir' => $r['dir'], 'setup' => $st]; }
      if ($picks) {
        $pseudo = ['date' => $date, 'picks' => $picks, 'built_at' => $day['built_at'], 'market' => $day['market'] ?? []];
        foreach (id_live($pseudo, $S['capital'], $S['risk_pct'])['picks'] as $p) $classify($p, 'backup');
      }
    }
  }
  $order = ['NOW' => 0, 'LATE_OK' => 1, 'WAIT' => 2, 'MISSED' => 3, 'DONE' => 4];
  usort($items, function ($a, $b) use ($order) { return [$order[$a['action']], $a['source'] === 'top10' ? 0 : 1] <=> [$order[$b['action']], $b['source'] === 'top10' ? 0 : 1]; });
  $mom = null; try { $M = mom_view(); $mom = ['market_on' => $M['rank']['market_on'], 'mode' => $M['mode'], 'holdings' => array_column($M['holdings'], 'symbol'), 'next' => $M['next_rebalance'], 'tradeable' => !empty($M['evidence']['tradeable'])]; } catch (Exception $e) {}
  return ['at_ist' => gmdate('H:i', time() + MK_IST), 'date' => $date, 'phase' => $R['phase'], 'market' => mk_market_status(), 'items' => $items, 'book' => $R['book'],
          'intraday_tested' => !empty(md_rules()['intraday']['tradeable']), 'momentum' => $mom, 'minutes_left' => max(0, 865 - mk_ist_min(time()))];
}
