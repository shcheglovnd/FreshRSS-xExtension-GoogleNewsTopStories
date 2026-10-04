<?php

declare(strict_types=1);

/**
 * Replays saved downloads of a Google News feed through the tracking, without FreshRSS or network, as if FreshRSS had
 * refreshed the feed at each, and prints the stories followed: when each entered the top, for how long, its best rank,
 * and how many lead articles it had, i.e. how many entries a reader would have shown without the extension.
 * A file’s time is in its name, as 20261003T222421Z….xml or .xml.gz.
 *
 *   php tests/replay.php 'samples/*_root.xml.gz'                # the top 10
 *   php tests/replay.php 'samples/*_root.xml.gz' 5 1            # the top 5, entering the feed after 1 hour in it
 *   php tests/replay.php 'samples/*_root.xml.gz' 10 0 --rss     # the RSS of the last refresh
 *   php tests/replay.php 'samples/*_root.xml.gz' 10 0 --state=state.json   # also writes the state of the last refresh
 */

require dirname(__DIR__) . '/lib/GoogleNewsTopStories.php';

$args = array_values(array_filter(array_slice($argv, 1), static fn(string $a): bool => !str_starts_with($a, '--')));
$files = glob($args[0] ?? '') ?: [];
sort($files);
$top = GoogleNewsTopStories::boundTop((int)($args[1] ?? GoogleNewsTopStories::TOP_DEFAULT));
$minHours = GoogleNewsTopStories::boundMinHours((float)($args[2] ?? 0));
$url = 'https://news.google.com/rss?hl=en-US&gl=US&ceid=US:en';

$state = [];
$leads = [];
$refreshes = 0;
foreach ($files as $file) {
	if (preg_match('/(\d{8}T\d{6}Z)[^\/]*$/', $file, $matches) !== 1) {
		continue;
	}
	$time = (int)strtotime($matches[1]);
	$xml = str_ends_with($file, '.gz') ? gzdecode((string)file_get_contents($file)) : file_get_contents($file);
	$feed = is_string($xml) ? GoogleNewsTopStories::parse($xml) : null;
	if ($feed === null || $feed['items'] === []) {
		fwrite(STDERR, "skipped, not a feed: $file\n");
		continue;
	}
	$state = GoogleNewsTopStories::track($state, $feed, $time, $top, $minHours, $url);
	$refreshes++;
	foreach (array_slice($feed['items'], 0, $top) as $item) {
		foreach ($state['stories'] as $id => $story) {
			if ($story['last'] === $time && $story['link'] === $item['link']) {
				$leads[$id][$item['id']] = true;
			}
		}
	}
}
if ($state === []) {
	fwrite(STDERR, "no feed among: " . ($args[0] ?? '') . "\n");
	exit(1);
}
foreach ($argv as $arg) {
	if (str_starts_with($arg, '--state=')) {
		file_put_contents(substr($arg, 8), json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}
}
if (in_array('--rss', $argv, true)) {
	echo GoogleNewsTopStories::rss($state, titleTime: true);
	exit(0);
}

$stories = $state['stories'];
uasort($stories, static fn(array $a, array $b): int => $a['first'] <=> $b['first']);
$entered = array_filter($stories, static fn(array $story): bool => $story['entered'] > 0);
printf("%d refreshes, %s to %s: %d stories in the top %d, %d entered the feed (min %s h); %d lead articles\n\n", $refreshes,
	date('D H:i', (int)strtotime(preg_replace('/.*(\d{8}T\d{6}Z).*/', '$1', $files[0]) ?? '')), date('D H:i', $state['refreshed']),
	count($stories), $top, count($entered), $minHours, array_sum(array_map('count', $leads)));
foreach ($stories as $id => $story) {
	printf("%s %s-%s %5.1f h  best #%-2d  %s  leads %-2d  %s\n", $story['entered'] > 0 ? '+' : ' ', date('D H:i', $story['first']),
		date('H:i', $story['last']), $story['time'] / 3600, $story['best'], $story['rank'] > 0 ? sprintf('now #%-2d', $story['rank']) : 'out    ',
		count($leads[$id] ?? []), mb_strimwidth($story['title'], 0, 70, '…'));
}
