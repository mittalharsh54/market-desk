<?php
/* =====================================================================
   Market Desk — server cron (cron.php)
   ---------------------------------------------------------------------
   GitHub's scheduled workflows are best-effort and may not fire, so the
   server can keep time itself. Add ONE cron job in hPanel (Advanced →
   Cron Jobs → Custom), running every minute:
       php /path/to/markets/cron.php
   It exits at once outside the useful windows, and otherwise does what
   the page and the GitHub jobs do: research the next session overnight,
   re-check at 8:45, lock at 9:25, work out signals every minute, send
   Telegram alerts (incl. the monthly picks), and record the day after
   the close. Locks and sent-logs make it safe to run alongside the
   GitHub jobs or an open browser tab.
   ===================================================================== */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/market.php';
@set_time_limit(280);

$now = time(); $ist = $now + 19800; $dow = (int) gmdate('N', $ist); $m = (int) gmdate('G', $ist) * 60 + (int) gmdate('i', $ist);
$weekday = $dow <= 5;
$window = $weekday && (($m >= 520 && $m <= 950) || ($m >= 40 && $m <= 45)); // 8:40–15:50, and 00:40–00:45 for the overnight research
if (!$window) { exit(0); }

$t0 = microtime(true);
try {
  $out = id_tick();
  $line = ['at' => gmdate('Y-m-d H:i', $ist), 'phase' => $out['phase'] ?? null, 'sent' => count($out['sent'] ?? []), 'secs' => round(microtime(true) - $t0, 1)];
} catch (Throwable $e) {
  $line = ['at' => gmdate('Y-m-d H:i', $ist), 'error' => $e->getMessage()];
}
/* a small heartbeat the app can show ("server clock last ran at …") */
md_store_set('cron_last', json_encode($line));
echo json_encode($line), "\n";
