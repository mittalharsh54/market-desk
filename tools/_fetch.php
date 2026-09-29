<?php
/* Shared by the studies: fetch many URLs politely.
   - A file cache (tools/.cache, kept between GitHub runs with actions/cache): price history
     that ends more than 3 days ago never changes, so it is fetched once; recent data is
     re-fetched after 20 hours. This cuts a study from ~1,000 requests to a few hundred.
   - Throttled or failed requests (429, 5xx, timeouts) are retried with growing pauses
     and lower concurrency, and the failure codes are reported. */
function study_cache_file($url) { $d = __DIR__ . '/.cache'; if (!is_dir($d)) @mkdir($d, 0775, true); return $d . '/' . md5($url) . '.json'; }
function study_cache_ttl($url) {
  /* Upstox URLs end with /{to}/{from}: history ending 3+ days ago is final */
  if (preg_match('#/(\d{4}-\d{2}-\d{2})/(\d{4}-\d{2}-\d{2})$#', $url, $m) && strtotime($m[1]) < time() - 3 * 86400) return 400 * 86400;
  return 20 * 3600;
}
function study_fetch(array $want, $concurrency = 4) {
  $res = []; $todo = [];
  foreach ($want as $k => $r) {
    $f = study_cache_file($r['url']);
    if (is_file($f) && filemtime($f) > time() - study_cache_ttl($r['url'])) { $res[$k] = ['code' => 200, 'body' => file_get_contents($f)]; continue; }
    $todo[$k] = $r;
  }
  fwrite(STDERR, count($res) . " from cache, " . count($todo) . " to fetch\n");
  for ($round = 0; $round < 6 && $todo; $round++) {
    if ($round) { $wait = [0, 10, 30, 60, 120, 180][$round]; fwrite(STDERR, count($todo) . " requests to retry in {$wait}s (round $round)\n"); sleep($wait); }
    $next = []; $codes = [];
    foreach (array_chunk($todo, 40, true) as $batch) { // small batches with a pause between them
      $got = mkt_http_multi($batch, 30, max(1, $concurrency - $round));
      foreach ($batch as $k => $r) {
        $c = $got[$k]['code'] ?? 0; $codes[$c] = ($codes[$c] ?? 0) + 1;
        if ($c === 200) { $res[$k] = $got[$k]; @file_put_contents(study_cache_file($r['url']), $got[$k]['body']); }
        elseif ($c === 429 || $c === 0 || $c >= 500) $next[$k] = $r; else $res[$k] = $got[$k];
      }
      usleep(300000);
    }
    ksort($codes); fwrite(STDERR, 'round ' . $round . ' response codes: ' . json_encode($codes) . "\n");
    $todo = $next;
  }
  return $res;
}
/* stop a study rather than publish results from a thin universe */
function study_require_coverage($loaded, $wanted, $min = 0.9) {
  fwrite(STDERR, "$loaded of $wanted stocks loaded\n");
  if ($wanted > 0 && $loaded < $min * $wanted) { fwrite(STDERR, "Too few stocks loaded (need " . round($min * 100) . "%) — not publishing results.\n"); exit(2); }
}
