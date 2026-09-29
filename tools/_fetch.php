<?php
/* Shared by the studies: fetch many URLs politely. Throttled or failed requests (429, 5xx,
   timeouts) are retried up to 4 times with a pause and lower concurrency, so a busy data
   provider can't silently shrink the test universe. */
function study_fetch(array $want, $concurrency = 5) {
  $res = []; $todo = $want;
  for ($round = 0; $round < 5 && $todo; $round++) {
    if ($round) { fwrite(STDERR, count($todo) . " requests to retry (round $round)\n"); sleep(5 * $round); }
    $got = mkt_http_multi($todo, 30, max(1, $concurrency - $round));
    $next = [];
    foreach ($todo as $k => $r) { $c = $got[$k]['code'] ?? 0; if ($c === 200) $res[$k] = $got[$k]; elseif ($c === 429 || $c === 0 || $c >= 500) $next[$k] = $r; else $res[$k] = $got[$k]; }
    $todo = $next;
  }
  return $res;
}
/* stop a study rather than publish results from a thin universe */
function study_require_coverage($loaded, $wanted, $min = 0.9) {
  fwrite(STDERR, "$loaded of $wanted stocks loaded\n");
  if ($wanted > 0 && $loaded < $min * $wanted) { fwrite(STDERR, "Too few stocks loaded (need " . round($min * 100) . "%) — not publishing results.\n"); exit(2); }
}
