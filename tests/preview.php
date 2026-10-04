<?php

declare(strict_types=1);

/**
 * Manual check against Google News, without FreshRSS: prints the top stories a new feed would list now.
 *
 *   php tests/preview.php                                       # the top 10 of the U.S. edition’s home page
 *   php tests/preview.php 'https://news.google.com/rss?hl=en-GB&gl=GB&ceid=GB:en&top=5'
 *   php tests/preview.php '' --rss                              # the generated RSS instead of a summary
 */

require dirname(__DIR__) . '/lib/GoogleNewsTopStories.php';

$args = array_values(array_filter(array_slice($argv, 1), static fn(string $a): bool => !str_starts_with($a, '--')));
$url = GoogleNewsTopStories::feedUrl(($args[0] ?? '') !== '' ? $args[0] : 'https://news.google.com/rss?hl=en-US&gl=US&ceid=US:en');
$googleUrl = $url !== null ? GoogleNewsTopStories::googleUrl($url) : null;
if ($url === null || $googleUrl === null) {
	fwrite(STDERR, "not a Google News feed of ranked stories: {$args[0]}\n");
	exit(1);
}
$settings = GoogleNewsTopStories::urlSettings($url);
$context = stream_context_create(['http' => ['header' => "User-Agent: FreshRSS Google News Top Stories test\r\n", 'timeout' => 60]]);
$feed = GoogleNewsTopStories::parse((string)file_get_contents($googleUrl, false, $context));
if ($feed === null || $feed['items'] === []) {
	fwrite(STDERR, "not a list of stories: $googleUrl\n");
	exit(1);
}
$now = time();
$state = GoogleNewsTopStories::track([], $feed, $now, $settings['top'] ?? GoogleNewsTopStories::TOP_DEFAULT, 0.0, $googleUrl);
if (in_array('--rss', $argv, true)) {
	echo GoogleNewsTopStories::rss($state, $settings['date'] ?? GoogleNewsTopStories::DATE_DEFAULT, $settings['title_time'] ?? true);
	exit(0);
}
printf("%s: the top %d of %d stories\n\n", $feed['title'], $state['top'], count($feed['items']));
foreach ($state['stories'] as $story) {
	printf("%3d. %-22s %4s  %d articles  %s\n", $story['rank'], mb_strimwidth($story['source'], 0, 22, '…'),
		$story['published'] > 0 ? round(($now - $story['published']) / 3600) . ' h' : '?', count($story['articles']),
		mb_strimwidth($story['title'], 0, 80, '…'));
}
