<?php

declare(strict_types=1);

/**
 * Checks of {@see GoogleNewsTopStories} without FreshRSS or network: php tests/topstories.php
 */

require dirname(__DIR__) . '/lib/GoogleNewsTopStories.php';

date_default_timezone_set('UTC');
$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void {
	global $failures;
	if ($actual === $expected) {
		echo "ok   $name\n";
	} else {
		$failures++;
		echo "FAIL $name\n     expected " . var_export($expected, true) . "\n     got      " . var_export($actual, true) . "\n";
	}
}

/**
 * A Google News RSS document, as Google gives it: each item’s lead article is the first of its articles.
 * @param list<array{0:list<array{0:string,1:string,2:string}>,1?:int,2?:string}> $items each its articles as [ID, title, outlet],
 *  then its date, then a link to its story if any
 */
function feed(array $items, string $title = 'Top stories - Google News'): string {
	$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/"><channel>'
		. '<generator>NFE/5.0</generator><title>' . $title . '</title><link>https://news.google.com/?hl=en-US&amp;gl=US&amp;ceid=US:en</link>'
		. '<language>en-US</language><description>Google News</description>';
	foreach ($items as $item) {
		[$articles] = $item;
		[$id, $lead, $source] = $articles[0];
		$link = 'https://news.google.com/rss/articles/' . $id . '?oc=5';
		$description = '';
		foreach ($articles as [$articleId, $articleTitle, $articleSource]) {
			$description .= '<li><a href="https://news.google.com/rss/articles/' . $articleId . '?oc=5" target="_blank">'
				. htmlspecialchars($articleTitle) . '</a>&nbsp;&nbsp;<font color="#6f6f6f">' . htmlspecialchars($articleSource) . '</font></li>';
		}
		$description = count($articles) > 1 ? '<ol>' . $description . '</ol>' : substr($description, 4, -5);
		if (($item[2] ?? '') !== '') {
			$description .= '<strong><a href="' . $item[2] . '" target="_blank">See more headlines &amp; perspectives on Google News</a></strong>';
		}
		$xml .= '<item><title>' . htmlspecialchars($lead . ' - ' . $source) . '</title><link>' . $link . '</link>'
			. '<guid isPermaLink="false">' . $id . '</guid><pubDate>' . gmdate(DATE_RSS, $item[1] ?? 1791000000) . '</pubDate>'
			. '<description>' . htmlspecialchars($description) . '</description>'
			. '<source url="https://www.example.com">' . htmlspecialchars($source) . '</source></item>';
	}
	return $xml . '</channel></rss>';
}

/** @return array{title:string,link:string,items:list<array<string,mixed>>} */
function parsed(array $items, string $title = 'Top stories - Google News'): array {
	$feed = GoogleNewsTopStories::parse(feed($items, $title));
	if ($feed === null) {
		throw new RuntimeException('not parsed');
	}
	return $feed;
}

/** @return list<array{string,string,string}> articles of a story, from IDs */
function articles(string ...$ids): array {
	return array_map(static fn(string $id): array => ['CBMi' . $id, "Headline $id", 'Outlet ' . strtoupper($id[0])], $ids);
}

/** A summary of the stories of a state: ID => [rank, best, time, entered, first, before] */
function summary(array $state): array {
	$summary = [];
	foreach ($state['stories'] as $id => $story) {
		$summary[$id] = [$story['rank'], $story['best'], $story['time'], $story['entered'], $story['first'], $story['before']];
	}
	ksort($summary);
	return $summary;
}

/** A time as a story’s content gives it */
function at(int $time, string $timezone = 'America/Chicago'): string {
	return (new DateTimeImmutable('@' . $time))->setTimezone(new DateTimeZone($timezone))->format('D j M, H:i');
}

/** @return list<string> the values of an element of the items of an RSS document */
function items(string $rss, string $element): array {
	$xml = simplexml_load_string($rss);
	if (!$xml instanceof SimpleXMLElement) {
		return ['not XML'];
	}
	$values = [];
	foreach ($xml->channel->item as $item) {
		$values[] = $element === 'pubDate' ? gmdate('Y-m-d H:i:s', (int)strtotime((string)$item->pubDate))
			: ($element === 'dc:creator' ? (string)$item->children('http://purl.org/dc/elements/1.1/')->creator : (string)$item->{$element});
	}
	return $values;
}

// URLs
$home = 'https://news.google.com/rss?hl=en-US&gl=US&ceid=US:en';
$topic = 'CAAqJggKIiBDQkFTRWdvSUwyMHZNRFZxYUdjU0FtVnVHZ0pWVXlnQVAB';
foreach ([
	$home => $home,
	'news.google.com/rss?hl=en-US&gl=US&ceid=US:en' => $home,
	' http://NEWS.google.com/rss/?hl=en-US&gl=US&ceid=US:en#top ' => $home,
	'https://news.google.com/?hl=en-US&gl=US&ceid=US:en' => $home,
	'https://news.google.com/home?hl=en-US&gl=US&ceid=US%3Aen' => 'https://news.google.com/rss?hl=en-US&gl=US&ceid=US%3Aen',
	'https://news.google.com/topstories?hl=en-US&gl=US&ceid=US:en' => $home,
	'https://news.google.com/rss' => 'https://news.google.com/rss',
	"https://news.google.com/topics/$topic?hl=en-US&gl=US&ceid=US:en" => "https://news.google.com/rss/topics/$topic?hl=en-US&gl=US&ceid=US:en",
	"https://news.google.com/rss/topics/$topic" => "https://news.google.com/rss/topics/$topic",
	'https://news.google.com/rss/headlines/section/topic/WORLD?hl=en-US&gl=US&ceid=US:en' => 'https://news.google.com/rss/headlines/section/topic/WORLD?hl=en-US&gl=US&ceid=US:en',
	"$home&TOP=05&min_hours=1.5&date=Published&title_time=yes" => "$home&top=5&min_hours=1.5&date=published&title_time=1",
	"https://news.google.com/rss?top=3&hl=en-US&gl=US&ceid=US:en&title_time=off" => "$home&top=3&title_time=0",
	"$home&top=abc&min_hours=x&date=&title_time=maybe" => $home,
	"$home&top=0&min_hours=-1" => "$home&top=1&min_hours=0",
	"$home&top=500&min_hours=100" => "$home&top=100&min_hours=48",
	"$home&min_hours=0.333333" => "$home&min_hours=0.33",
] as $url => $expected) {
	check("feedUrl($url)", GoogleNewsTopStories::feedUrl($url), $expected);
}
foreach ([
	'https://news.google.com/rss/search?q=waymo&hl=en-US&gl=US&ceid=US:en', 'https://news.google.com/search?q=waymo',
	"https://news.google.com/rss/stories/$topic", 'https://news.google.com/rss/articles/CBMiabc?oc=5', 'https://news.google.com/foryou',
	'https://www.google.com/rss', 'https://evilnews.google.com/rss', 'https://news.google.com.example.com/rss', 'https://example.com/?u=news.google.com/rss',
	'https://news.google.com/topics/a/b',
] as $url) {
	check("feedUrl($url) is not ours", GoogleNewsTopStories::feedUrl($url), null);
}
check('googleUrl() drops the settings', GoogleNewsTopStories::googleUrl("https://news.google.com/rss?top=5&hl=en-US&gl=US&ceid=US:en&Title_Time=1&min_hours=2&date=published"), $home);
check('googleUrl() of a page', GoogleNewsTopStories::googleUrl("https://news.google.com/topics/$topic?hl=en-US&gl=US&ceid=US:en&top=3"),
	"https://news.google.com/rss/topics/$topic?hl=en-US&gl=US&ceid=US:en");
check('googleUrl() of another site', GoogleNewsTopStories::googleUrl('https://hnrss.org/newest'), null);
check('urlSettings()', GoogleNewsTopStories::urlSettings("$home&top=7&min_hours=0.5&date=entered&title_time=1"),
	['top' => 7, 'min_hours' => 0.5, 'date' => 'entered', 'title_time' => true]);
check('urlSettings() without settings', GoogleNewsTopStories::urlSettings($home), ['top' => null, 'min_hours' => null, 'date' => null, 'title_time' => null]);

// Parsing
check('parse() of HTML', GoogleNewsTopStories::parse('<!DOCTYPE html><html><body>Consent</body></html>'), null);
check('parse() of not XML', GoogleNewsTopStories::parse('{"items":[]}'), null);
check('parse() of an Atom feed', GoogleNewsTopStories::parse('<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>x</title></feed>'), null);
check('parse() of an empty feed', GoogleNewsTopStories::parse(feed([])), ['title' => 'Top stories - Google News',
	'link' => 'https://news.google.com/?hl=en-US&gl=US&ceid=US:en', 'items' => []]);
$feed = parsed([
	[[['CBMia1', 'Plane missing off Nantucket', 'The Boston Globe'], ['CBMia2', 'Search for plane & crew', 'WCVB'], ['CBMia3', 'Coast Guard searches', 'AP News']], 1791000000],
	[[['CBMib1', 'Ebola “center” burned', 'ABC News - Breaking News, Latest News and Videos']], 1790990000, 'https://news.google.com/stories/CAAqStory1?hl=en-US&gl=US&ceid=US:en&oc=5'],
]);
check('parse() items', count($feed['items']), 2);
$item = $feed['items'][0];
check('parse() id', $item['id'], 'CBMia1');
check('parse() title without its outlet', $item['title'], 'Plane missing off Nantucket');
check('parse() source', $item['source'], 'The Boston Globe');
check('parse() link', $item['link'], 'https://news.google.com/rss/articles/CBMia1?oc=5');
check('parse() date', $item['date'], 1791000000);
check('parse() articles', $item['articles'], [
	['title' => 'Plane missing off Nantucket', 'source' => 'The Boston Globe', 'link' => 'https://news.google.com/rss/articles/CBMia1?oc=5'],
	['title' => 'Search for plane & crew', 'source' => 'WCVB', 'link' => 'https://news.google.com/rss/articles/CBMia2?oc=5'],
	['title' => 'Coast Guard searches', 'source' => 'AP News', 'link' => 'https://news.google.com/rss/articles/CBMia3?oc=5'],
]);
check('parse() keys: 3 IDs and 3 titles', count($item['keys']), 6);
check('parse() no story', $item['story'], '');
$item = $feed['items'][1];
check('parse() single article', $item['articles'], [['title' => 'Ebola “center” burned', 'source' => 'ABC News - Breaking News, Latest News and Videos',
	'link' => 'https://news.google.com/rss/articles/CBMib1?oc=5']]);
check('parse() single article title', $item['title'], 'Ebola “center” burned');
check('parse() story', $item['story'], 'https://news.google.com/stories/CAAqStory1?hl=en-US&gl=US&ceid=US:en&oc=5');
check('parse() keys: ID, title, story', count($item['keys']), 3);

// Tracking: a story keeps its ID when its lead changes, and its time in the top grows while it stays in it
$t0 = 1791000000;
$url = $home;
$s = GoogleNewsTopStories::track([], parsed([[articles('a1', 'a2', 'a3')], [articles('b1', 'b2')]]), $t0, 10, 0.0, $url);
check('first refresh: stories already in the top, dated when they entered, one second apart', summary($s), [
	'CBMia1' => [1, 1, 0, $t0, $t0, true],
	'CBMib1' => [2, 2, 0, $t0 - 1, $t0, true],
]);
$s1 = $s;
$s = GoogleNewsTopStories::track($s, parsed([[articles('b2', 'b3')], [articles('a4', 'a2')]]), $t0 + 1800, 10, 0.0, $url);
check('new leads: same stories, ranks swapped, 30 min each', summary($s), [
	'CBMia1' => [2, 1, 1800, $t0, $t0, true],
	'CBMib1' => [1, 1, 1800, $t0 - 1, $t0, true],
]);
check('new lead: title and link follow it', [$s['stories']['CBMib1']['title'], $s['stories']['CBMib1']['link']],
	['Headline b2', 'https://news.google.com/rss/articles/CBMib2?oc=5']);
$s = GoogleNewsTopStories::track($s, parsed([[articles('c1')], [articles('a4')]]), $t0 + 3600, 10, 0.0, $url);
check('a new story enters, dated then; one leaves', summary($s), [
	'CBMia1' => [2, 1, 3600, $t0, $t0, true],
	'CBMib1' => [0, 1, 1800, $t0 - 1, $t0, true],
	'CBMic1' => [1, 1, 0, $t0 + 3600, $t0 + 3600, false],
]);
$s = GoogleNewsTopStories::track($s, parsed([[articles('b3', 'b4')], [articles('c1')], [articles('a4')]]), $t0 + 5400, 10, 0.0, $url);
check('a story comes back: same entry, no time for its absence', summary($s), [
	'CBMia1' => [3, 1, 5400, $t0, $t0, true],
	'CBMib1' => [1, 1, 1800, $t0 - 1, $t0, true],
	'CBMic1' => [2, 1, 1800, $t0 + 3600, $t0 + 3600, false],
]);
$s = GoogleNewsTopStories::track($s, parsed([[articles('b4')], [articles('c1')]]), $t0 + 5400 + 5 * 3600, 10, 0.0, $url);
check('5 hours between refreshes count as 3', [$s['stories']['CBMib1']['time'], $s['stories']['CBMic1']['time']], [1800 + 3 * 3600, 1800 + 3 * 3600]);
$t = $t0 + 5400 + 5 * 3600;
$s = GoogleNewsTopStories::track($s, parsed([[articles('c1')]]), $t + 3 * 86400 + 60, 10, 0.0, $url);
check('stories out of the top for 3 days are forgotten', array_keys($s['stories']), ['CBMic1']);
$s = GoogleNewsTopStories::track($s, parsed([[articles('c1')], [articles('a4', 'a5')]]), $t + 3 * 86400 + 120, 10, 0.0, $url);
check('a forgotten story is new when it comes back, with its lead as ID', array_keys($s['stories']), ['CBMic1', 'CBMia4']);

// A minimum time in the top
$s = GoogleNewsTopStories::track([], parsed([[articles('a1')]]), $t0, 10, 1.0, $url);
check('min_hours: the first refresh takes the stories already in the top', $s['stories']['CBMia1']['entered'], $t0);
$s = GoogleNewsTopStories::track($s, parsed([[articles('d1')], [articles('a1')]]), $t0 + 1800, 10, 1.0, $url);
check('min_hours: a new story waits', $s['stories']['CBMid1']['entered'], 0);
$s = GoogleNewsTopStories::track($s, parsed([[articles('d1')]]), $t0 + 3000, 10, 1.0, $url);
check('min_hours: still waiting at 20 min', $s['stories']['CBMid1']['entered'], 0);
$s = GoogleNewsTopStories::track($s, parsed([[articles('a1')], [articles('d1')]]), $t0 + 5400, 10, 1.0, $url);
check('min_hours: enters at 1 h, dated then', [$s['stories']['CBMid1']['time'], $s['stories']['CBMid1']['entered']], [3600, $t0 + 5400 - 1]);
check('min_hours: only entered stories are in the RSS', items(GoogleNewsTopStories::rss(GoogleNewsTopStories::track($s1, parsed([[articles('e1')]]), $t0 + 60, 10, 1.0, $url)), 'guid'),
	['CBMia1', 'CBMib1']);

// Matching
$s = GoogleNewsTopStories::track([], parsed([[articles('p1', 'p2', 'p3', 'p4')]]), $t0, 10, 0.0, $url);
$s = GoogleNewsTopStories::track($s, parsed([[articles('x1', 'p1')], [articles('p2', 'p3', 'p4', 'y1')]]), $t0 + 600, 10, 0.0, $url);
check('an item sharing more articles with a story gets it, whatever its rank', [$s['stories']['CBMip1']['rank'], $s['stories']['CBMix1']['rank'] ?? null], [2, 1]);
$s = GoogleNewsTopStories::track([], parsed([[[['CBMiz1', 'Same headline', 'Same outlet']]]]), $t0, 10, 0.0, $url);
$s = GoogleNewsTopStories::track($s, parsed([[[['CBMiz9', 'Same  headline', 'Same outlet'], ['CBMiz2', 'Other', 'Elsewhere']]]]), $t0 + 600, 10, 0.0, $url);
check('an article with another ID is recognised by its title and outlet', array_keys($s['stories']), ['CBMiz1']);
$s = GoogleNewsTopStories::track([], parsed([[[['CBMis1', 'One', 'A']], $t0, 'https://news.google.com/stories/CAAqS?oc=5']]), $t0, 10, 0.0, $url);
$s = GoogleNewsTopStories::track($s, parsed([[[['CBMis2', 'Two', 'B']], $t0, 'https://news.google.com/stories/CAAqS?hl=en-US&oc=5']]), $t0 + 600, 10, 0.0, $url);
check('an item is recognised by its story on Google News', array_keys($s['stories']), ['CBMis1']);
$s = GoogleNewsTopStories::track([], parsed([[articles('a1')], [articles('b1')], [articles('c1')]]), $t0, 2, 0.0, $url);
check('only the top N are followed', array_keys($s['stories']), ['CBMia1', 'CBMib1']);
$s = GoogleNewsTopStories::track($s, parsed([[articles('a1')]]), $t0 + 600, 2, 0.0, 'https://news.google.com/rss/topics/X');
check('another feed URL starts anew', summary($s), ['CBMia1' => [1, 1, 0, $t0 + 600, $t0 + 600, true]]);
check('a state of another version starts anew', summary(GoogleNewsTopStories::track(['version' => 99, 'url' => $url, 'refreshed' => $t0, 'stories' => []],
	parsed([[articles('a1')]]), $t0 + 600, 10, 0.0, $url)), ['CBMia1' => [1, 1, 0, $t0 + 600, $t0 + 600, true]]);
check('a broken state starts anew', array_keys(GoogleNewsTopStories::track(['version' => 1, 'url' => $url, 'refreshed' => 'x', 'stories' => ['a' => 'b']],
	parsed([[articles('a1')]]), $t0 + 600, 10, 0.0, $url)['stories']), ['CBMia1']);
$s = GoogleNewsTopStories::track([], parsed([[articles('a1')], [articles('a1', 'q1')]]), $t0, 10, 0.0, $url);
check('two items of one story in one refresh stay two stories', array_keys($s['stories']), ['CBMia1', 'CBMia1#2']);
$state = json_decode((string)json_encode($s), true);
check('a state survives JSON', is_array($state) ? summary(GoogleNewsTopStories::track($state, parsed([[articles('a1')]]), $t0 + 600, 10, 0.0, $url)) : null,
	['CBMia1' => [1, 1, 600, $t0, $t0, true], 'CBMia1#2' => [0, 2, 0, $t0 - 1, $t0, true]]);

// RSS
$s = GoogleNewsTopStories::track([], parsed([
	[articles('a1', 'a2'), $t0 - 7200],
	[articles('b1'), $t0 - 600, 'https://news.google.com/stories/CAAqB?oc=5'],
]), $t0, 10, 0.0, $url);
$s = GoogleNewsTopStories::track($s, parsed([[articles('b1')], [articles('c1'), $t0 - 300]]), $t0 + 1800, 10, 0.0, $url);
$s = GoogleNewsTopStories::track($s, parsed([[articles('c1'), $t0 - 300], [articles('b1')]]), $t0 + 2400, 10, 0.0, $url);
$rss = GoogleNewsTopStories::rss($s, timezone: new DateTimeZone('America/Chicago'));
check('rss() channel title', (string)(simplexml_load_string($rss)->channel->title ?? ''), 'Google News: Top stories (top 10)');
check('rss() order: in the top by rank, then out', items($rss, 'guid'), ['CBMic1', 'CBMib1', 'CBMia1']);
check('rss() titles', items($rss, 'title'), ['Headline c1', 'Headline b1', 'Headline a1']);
check('rss() links: the lead', items($rss, 'link'), ['https://news.google.com/rss/articles/CBMic1?oc=5', 'https://news.google.com/rss/articles/CBMib1?oc=5',
	'https://news.google.com/rss/articles/CBMia1?oc=5']);
check('rss() authors: the lead’s outlet', items($rss, 'dc:creator'), ['Outlet C', 'Outlet B', 'Outlet A']);
check('rss() dates: entered, or published for the stories of the first refresh', items($rss, 'pubDate'),
	[gmdate('Y-m-d H:i:s', $t0 + 1800 - 1), gmdate('Y-m-d H:i:s', $t0 - 600), gmdate('Y-m-d H:i:s', $t0 - 7200)]);
check('rss() dates: published', items(GoogleNewsTopStories::rss($s, 'published'), 'pubDate'),
	[gmdate('Y-m-d H:i:s', $t0 - 300), gmdate('Y-m-d H:i:s', $t0 - 600), gmdate('Y-m-d H:i:s', $t0 - 7200)]);
check('rss() titles with the time and the best rank', items(GoogleNewsTopStories::rss($s, titleTime: true), 'title'),
	['<1 h · #1 · Headline c1', '<1 h · #1 · Headline b1', '<1 h · #1 · Headline a1']);
$descriptions = items($rss, 'description');
check('rss() content of a story in the top', $descriptions[0], "<p><strong>#1 in the top 10</strong> · in it for 10 min since " . at($t0 + 1800) . " · best #1</p>\n"
	. "<ol>\n<li><a href=\"https://news.google.com/rss/articles/CBMic1?oc=5\">Headline c1</a> — Outlet C, " . at($t0 - 300) . "</li>\n</ol>\n");
check('rss() content of a story of the first refresh, with its story', $descriptions[1],
	"<p><strong>#2 in the top 10</strong> · in it for at least 40 min since before " . at($t0) . " · best #1</p>\n"
	. "<ol>\n<li><a href=\"https://news.google.com/rss/articles/CBMib1?oc=5\">Headline b1</a> — Outlet B, " . at($t0 - 600) . "</li>\n</ol>\n"
	. "<p><a href=\"https://news.google.com/stories/CAAqB?oc=5\">Full coverage on Google News</a></p>\n");
check('rss() content of a story out of the top', $descriptions[2], "<p>Was in the top 10 briefly, before " . at($t0) . " · best #1</p>\n"
	. "<ol>\n<li><a href=\"https://news.google.com/rss/articles/CBMia1?oc=5\">Headline a1</a> — Outlet A, " . at($t0 - 7200) . "</li>\n"
	. "<li><a href=\"https://news.google.com/rss/articles/CBMia2?oc=5\">Headline a2</a> — Outlet A</li>\n</ol>\n");
$s = GoogleNewsTopStories::track($s, parsed([[articles('b1')]]), $t0 + 2400 + 3 * 3600 + 25 * 60, 10, 0.0, $url);
$s = GoogleNewsTopStories::track($s, parsed([[articles('b1')]]), $t0 + 2400 + 6 * 3600 + 25 * 60, 10, 0.0, $url);
check('rss() content of a story out of the top after hours', items(GoogleNewsTopStories::rss($s, titleTime: true), 'description')[1],
	"<p>Was in the top 10 for 10 min, from " . at($t0 + 1800, 'UTC') . ' to ' . at($t0 + 2400, 'UTC') . " · best #1</p>\n"
	. "<ol>\n<li><a href=\"https://news.google.com/rss/articles/CBMic1?oc=5\">Headline c1</a> — Outlet C, " . at($t0 - 300, 'UTC') . "</li>\n</ol>\n");
check('rss() titles after hours', items(GoogleNewsTopStories::rss($s, titleTime: true), 'title'),
	['6 h · #1 · Headline b1', '<1 h · #1 · Headline c1', '<1 h · #1 · Headline a1']);
check('rss() content: hours and minutes', str_contains(items(GoogleNewsTopStories::rss($s), 'description')[0], 'in it for at least 6 h 40 min since before'), true);
$s = GoogleNewsTopStories::track([], parsed([[[['CBMix', 'Tom & Jerry <3 ]]> “quotes”', 'A&B']]]], 'Technology - Latest - Google News'), $t0, 5, 0.0, $url);
$rss = GoogleNewsTopStories::rss($s);
check('rss() escapes', [items($rss, 'title'), str_contains(items($rss, 'description')[0], '>Tom &amp; Jerry &lt;3 ]]&gt; “quotes”</a> — A&amp;B')],
	[['Tom & Jerry <3 ]]> “quotes”'], true]);
check('rss() channel title of a topic', (string)(simplexml_load_string($rss)->channel->title ?? ''), 'Google News: Technology (top 5)');

echo $failures === 0 ? "\nAll checks passed\n" : "\n$failures checks failed\n";
exit($failures === 0 ? 0 : 1);
