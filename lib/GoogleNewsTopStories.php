<?php

declare(strict_types=1);

/**
 * The top stories of a Google News feed, as an RSS document that follows each story while it is in the top N: when it
 * entered it, for how long it stayed, its best rank.
 *
 * Google News lists its stories in its order of importance, each with up to 5 articles from different outlets. An
 * item’s ID is its lead article, which Google changes often, so that a reader shows the same story again and again.
 * Here a story is recognised by its articles from one refresh to the next, so it stays one entry when its lead
 * changes, or when it leaves the top and comes back. It is dated when it enters the top N, or when it has been in it
 * for a minimum time, and its entry says how long it has been in it.
 *
 * What is known of a feed’s stories between refreshes, its state, is kept by the caller ({@see track()}).
 * A feed’s URL may set its own settings, e.g. https://news.google.com/rss?hl=en-US&gl=US&ceid=US:en&top=5&min_hours=1.
 *
 * Independent of FreshRSS, so it can be tested without it.
 */
final class GoogleNewsTopStories {
	public const HOST = 'https://news.google.com';
	public const TOP_DEFAULT = 10;
	public const TOP_MAX = 100;
	public const MIN_HOURS_MAX = 48;
	/** How a story is dated: when it entered the feed, i.e. the top N, or when its lead article was published */
	public const DATES = ['entered', 'published'];
	public const DATE_DEFAULT = 'entered';
	/** A story out of the top for this long is forgotten: if it comes back, it is a new story */
	public const KEEP_DAYS = 3;
	/** The longest time between two refreshes that counts as time in the top, for a story in it at both */
	public const MAX_INTERVAL = 3 * 3600;
	/** The most keys of articles kept per story, the latest */
	private const MAX_KEYS = 40;
	private const STATE_VERSION = 1;
	/** The extension’s own query parameters, which Google does not get */
	private const PARAMS = ['top', 'min_hours', 'date', 'title_time'];

	/**
	 * The feed URL for a Google News page or feed of ranked stories, in any form (http://, the page rather than its
	 * RSS), else null: the home page, whose first 10 stories are the top stories, then a few of each section; a topic,
	 * such as Headlines or Technology; or a section. Searches and single stories are left alone.
	 * The URL keeps Google’s own query parameters, such as the edition (hl, gl, ceid), followed by the feed’s own
	 * settings if any ({@see urlSettings()}).
	 */
	public static function feedUrl(string $url): ?string {
		$parts = self::parts($url);
		if ($parts === null) {
			return null;
		}
		$settings = self::urlSettings($url);
		$own = [];
		foreach ($settings as $name => $value) {
			if ($value !== null) {
				$own[] = $name . '=' . match (true) {
					is_bool($value) => $value ? '1' : '0',
					default => rawurlencode((string)$value),
				};
			}
		}
		$query = implode('&', [...$parts['query'], ...$own]);
		return self::HOST . $parts['path'] . ($query !== '' ? '?' . $query : '');
	}

	/** The URL to read from Google for a feed URL ({@see feedUrl()}): without the feed’s own settings; else null */
	public static function googleUrl(string $url): ?string {
		$parts = self::parts($url);
		if ($parts === null) {
			return null;
		}
		return self::HOST . $parts['path'] . ($parts['query'] !== [] ? '?' . implode('&', $parts['query']) : '');
	}

	/**
	 * The path of the RSS of a Google News URL of ranked stories, and its query parameters other than the extension’s,
	 * as given; else null.
	 * @return array{path:string,query:list<string>}|null
	 */
	private static function parts(string $url): ?array {
		if (preg_match('#^(?:https?://)?news\.google\.com(/[^?\#]*)?(?:\?([^\#]*))?(?:\#.*)?$#i', trim($url), $matches) !== 1) {
			return null;
		}
		$path = rtrim($matches[1] ?? '', '/');
		$path = preg_replace('#^/rss(?=/|$)#i', '', $path) ?? $path;
		$path = match (true) {
			in_array(strtolower($path), ['', '/home', '/topstories'], true) => '',
			preg_match('#^/topics/[\w-]+$#', $path) === 1,
			preg_match('#^/headlines/section/(?:topic|geo)/[^/]+$#i', $path) === 1 => $path,
			default => null,
		};
		if ($path === null) {
			return null;
		}
		$query = [];
		foreach (explode('&', $matches[2] ?? '') as $param) {
			$name = strtolower(urldecode(explode('=', $param, 2)[0]));
			if ($param !== '' && !in_array($name, self::PARAMS, true)) {
				$query[] = $param;
			}
		}
		return ['path' => '/rss' . $path, 'query' => $query];
	}

	/**
	 * The settings of a feed URL, or null when it does not give them in a known form: `top`, how many of Google’s
	 * stories are followed, `min_hours`, how long a story must have been in the top before it enters the feed, `date`,
	 * how stories are dated ({@see date()}), and `title_time`, whether titles start with the time in the top and the best
	 * rank, as 1 or 0 (or true/false, yes/no, on/off)
	 * @return array{top:int|null,min_hours:float|null,date:string|null,title_time:bool|null}
	 */
	public static function urlSettings(string $url): array {
		parse_str(preg_match('/\?([^#]*)/', $url, $matches) === 1 ? $matches[1] : '', $params);
		$params = array_change_key_case($params);
		$top = $params['top'] ?? null;
		$minHours = $params['min_hours'] ?? null;
		$date = $params['date'] ?? null;
		$titleTime = $params['title_time'] ?? null;
		return [
			'top' => is_string($top) && ctype_digit($top) ? self::boundTop((int)$top) : null,
			'min_hours' => is_string($minHours) && is_numeric($minHours) ? self::boundMinHours((float)$minHours) : null,
			'date' => is_string($date) ? self::date($date) : null,
			'title_time' => is_string($titleTime) ? match (strtolower($titleTime)) {
				'1', 'true', 'yes', 'on' => true,
				'0', 'false', 'no', 'off' => false,
				default => null,
			} : null,
		];
	}

	/** One of the ways of dating stories ({@see DATES}), in any case, else null */
	public static function date(string $value): ?string {
		$value = strtolower(trim($value));
		return in_array($value, self::DATES, true) ? $value : null;
	}

	public static function boundTop(int $top): int {
		return max(1, min(self::TOP_MAX, $top));
	}

	/** Within its bounds, to a hundredth of an hour */
	public static function boundMinHours(float $minHours): float {
		return is_finite($minHours) ? round(max(0.0, min((float)self::MIN_HOURS_MAX, $minHours)), 2) : 0.0;
	}

	/**
	 * The channel and the items of a Google News RSS document, in its order, else null if it is not one. An item’s
	 * articles are those of its description, the lead first; its keys identify them, so that an item can be matched
	 * with the story it was at the last refresh, whatever its lead: the IDs of the articles, their titles with their
	 * outlets, as an article may come with another ID, and the ID of the story on Google News, when it is given.
	 * @return array{title:string,link:string,items:list<array{id:string,title:string,source:string,link:string,date:int,story:string,articles:list<array{title:string,source:string,link:string}>,keys:list<string>}>}|null
	 */
	public static function parse(string $xml): ?array {
		$errors = libxml_use_internal_errors(true);
		$rss = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
		libxml_clear_errors();
		libxml_use_internal_errors($errors);
		if (!$rss instanceof SimpleXMLElement || $rss->getName() !== 'rss' || !isset($rss->channel)) {
			return null;
		}
		$items = [];
		foreach ($rss->xpath('channel/item') ?: [] as $item) {
			$id = self::text((string)$item->guid);
			$link = self::text((string)$item->link);
			$source = self::text((string)$item->source);
			$title = self::text((string)$item->title);
			if ($source !== '' && str_ends_with($title, ' - ' . $source)) {
				$title = substr($title, 0, -strlen(' - ' . $source));
			}
			if ($id === '') {
				$id = self::articleId($link);
			}
			if ($id === '' || $title === '') {
				continue;
			}
			[$articles, $story] = self::articles((string)$item->description);
			if ($articles === []) {
				$articles = [['title' => $title, 'source' => $source, 'link' => $link]];
			}
			$keys = [self::key('a', $id)];
			foreach ($articles as $article) {
				$articleId = self::articleId($article['link']);
				if ($articleId !== '') {
					$keys[] = self::key('a', $articleId);
				}
				$keys[] = self::key('t', mb_strtolower($article['title'] . '|' . $article['source'], 'UTF-8'));
			}
			$storyId = preg_match('#/stories/([\w-]+)#', $story, $matches) === 1 ? $matches[1] : '';
			if ($storyId !== '') {
				$keys[] = self::key('s', $storyId);
			}
			$date = strtotime((string)$item->pubDate);
			$items[] = ['id' => $id, 'title' => $title, 'source' => $source, 'link' => $link, 'date' => $date !== false ? $date : 0,
				'story' => $story, 'articles' => $articles, 'keys' => array_values(array_unique($keys))];
		}
		return ['title' => self::text((string)$rss->channel->title), 'link' => self::text((string)$rss->channel->link), 'items' => $items];
	}

	/**
	 * The articles of an item’s description, as Google gives them: a list of links, each followed by its outlet; and
	 * the link to the story on Google News (“See more headlines & perspectives”), if any.
	 * @return array{list<array{title:string,source:string,link:string}>,string}
	 */
	private static function articles(string $html): array {
		if (trim($html) === '') {
			return [[], ''];
		}
		$errors = libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$loaded = $dom->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>', LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($errors);
		if (!$loaded) {
			return [[], ''];
		}
		$articles = [];
		$story = '';
		foreach ($dom->getElementsByTagName('a') as $a) {
			$href = trim($a->getAttribute('href'));
			if (preg_match('#^https?://news\.google\.com/(?:rss/)?stories/#i', $href) === 1) {
				$story = $story !== '' ? $story : $href;
				continue;
			}
			$title = self::text($a->textContent);
			if (preg_match('#^https?://#i', $href) !== 1 || $title === '') {
				continue;
			}
			$source = '';
			for ($next = $a->nextSibling; $next !== null; $next = $next->nextSibling) {
				if ($next instanceof DOMElement) {
					$source = $next->tagName === 'font' ? self::text($next->textContent) : '';
					break;
				}
			}
			$articles[] = ['title' => $title, 'source' => $source, 'link' => $href];
		}
		return [$articles, $story];
	}

	/** The ID of an article on Google News, from its link, e.g. CBMi… in https://news.google.com/rss/articles/CBMi…?oc=5 */
	private static function articleId(string $link): string {
		return preg_match('#^https?://news\.google\.com/(?:rss/)?articles/([\w-]+)#i', $link, $matches) === 1 ? $matches[1] : '';
	}

	/** A short key for a kind of identity of an article: `a` its ID, `t` its title and outlet, `s` its story’s ID */
	private static function key(string $kind, string $value): string {
		return substr(sha1($kind . ':' . $value), 0, 12);
	}

	/**
	 * Follows the stories through a refresh, with the first $top items of the feed, in Google’s order.
	 *
	 * Each item is matched with the story of the last refreshes with which it shares the most keys of articles
	 * ({@see parse()}), each story with one item at most, else it is a new story, whose ID is the item’s, its lead
	 * article. A story’s time in the top grows by the time since the last refresh when it was in the top at both,
	 * up to {@see MAX_INTERVAL}. It enters the feed when its time reaches $minHours, at once when that is 0, and on the
	 * feed’s first refresh, when its earlier time is unknown. A story out of the top for {@see KEEP_DAYS} is forgotten.
	 *
	 * @param array<mixed> $state as returned by the last refresh, or [] for the first
	 * @param array{title:string,link:string,items:list<array{id:string,title:string,source:string,link:string,date:int,story:string,articles:list<array{title:string,source:string,link:string}>,keys:list<string>}>} $feed
	 *  as given by {@see parse()}
	 * @param string $url the feed’s URL at Google, which a state is for: with another, the state starts anew
	 * @return array{version:int,url:string,refreshed:int,top:int,title:string,link:string,stories:array<string,array<string,mixed>>}
	 */
	public static function track(array $state, array $feed, int $now, int $top, float $minHours, string $url = ''): array {
		$stories = [];
		$last = 0;
		if (($state['version'] ?? null) === self::STATE_VERSION && ($state['url'] ?? null) === $url && is_array($state['stories'] ?? null)) {
			foreach ($state['stories'] as $id => $story) {
				if (is_array($story) && is_array($story['keys'] ?? null)) {
					$stories[(string)$id] = self::story($story);
				}
			}
			$last = is_int($state['refreshed'] ?? null) ? $state['refreshed'] : 0;
		}
		$first = $last === 0;
		$items = array_slice($feed['items'], 0, max(1, $top));

		// Match the items with the stories, the most keys in common first, then the higher rank, then the more recent story
		$candidates = [];
		$storyKeys = array_map(static fn(array $story): array => array_flip($story['keys']), $stories);
		foreach ($items as $rank => $item) {
			$keys = array_flip($item['keys']);
			foreach ($storyKeys as $id => $keysOfStory) {
				$shared = count(array_intersect_key($keys, $keysOfStory));
				if ($shared > 0) {
					$candidates[] = [$shared, $rank, $stories[$id]['last'], (string)$id];
				}
			}
		}
		usort($candidates, static fn(array $a, array $b): int => [$b[0], $a[1], $b[2], $a[3]] <=> [$a[0], $b[1], $a[2], $b[3]]);
		$matched = [];
		$taken = [];
		foreach ($candidates as [, $rank, , $id]) {
			if (!isset($matched[$rank]) && !isset($taken[$id])) {
				$matched[$rank] = $id;
				$taken[$id] = true;
			}
		}

		foreach ($stories as $id => $story) {
			if (!isset($taken[$id])) {
				$stories[$id]['rank'] = 0;
			}
		}
		foreach ($items as $rank => $item) {
			$id = $matched[$rank] ?? null;
			if ($id === null) {
				$id = $item['id'];
				for ($n = 2; isset($stories[$id]); $n++) {
					$id = $item['id'] . '#' . $n;
				}
				$stories[$id] = self::story(['first' => $now, 'before' => $first, 'best' => $rank + 1, 'published' => $item['date']]);
			}
			$story = $stories[$id];
			if ($last > 0 && $story['last'] === $last && $now > $last) {
				$story['time'] += min($now - $last, self::MAX_INTERVAL);
			}
			$story['last'] = $now;
			$story['rank'] = $rank + 1;
			$story['best'] = min($story['best'], $rank + 1);
			$story['keys'] = array_slice(array_values(array_unique([...$item['keys'], ...$story['keys']])), 0, self::MAX_KEYS);
			$story['title'] = $item['title'];
			$story['source'] = $item['source'];
			$story['link'] = $item['link'];
			$story['articles'] = $item['articles'];
			$story['story'] = $item['story'] !== '' ? $item['story'] : $story['story'];
			if ($item['date'] > 0 && ($story['published'] === 0 || $item['date'] < $story['published'])) {
				$story['published'] = $item['date'];
			}
			if ($story['entered'] === 0 && ($first || $story['time'] >= $minHours * 3600)) {
				$story['entered'] = $now - $rank;	// one second apart, the higher rank later, so first in a reader
			}
			$stories[$id] = $story;
		}

		$stories = array_filter($stories, static fn(array $story): bool => $story['last'] >= $now - self::KEEP_DAYS * 86400);
		return ['version' => self::STATE_VERSION, 'url' => $url, 'refreshed' => $now, 'top' => max(1, $top),
			'title' => $feed['title'], 'link' => $feed['link'], 'stories' => $stories];
	}

	/**
	 * A story of a state, with all its fields and their types
	 * @param array<mixed> $story
	 * @return array{keys:list<string>,title:string,source:string,link:string,articles:list<array{title:string,source:string,link:string}>,story:string,first:int,last:int,time:int,best:int,rank:int,entered:int,published:int,before:bool}
	 */
	private static function story(array $story): array {
		$int = static fn(string $name): int => is_int($story[$name] ?? null) ? $story[$name] : 0;
		$string = static fn(string $name): string => is_string($story[$name] ?? null) ? $story[$name] : '';
		$articles = [];
		foreach (is_array($story['articles'] ?? null) ? $story['articles'] : [] as $article) {
			if (is_array($article) && is_string($article['title'] ?? null) && is_string($article['link'] ?? null)) {
				$articles[] = ['title' => $article['title'], 'source' => is_string($article['source'] ?? null) ? $article['source'] : '',
					'link' => $article['link']];
			}
		}
		return [
			'keys' => array_values(array_filter(is_array($story['keys'] ?? null) ? $story['keys'] : [], 'is_string')),
			'title' => $string('title'), 'source' => $string('source'), 'link' => $string('link'), 'articles' => $articles,
			'story' => $string('story'), 'first' => $int('first'), 'last' => $int('last'), 'time' => $int('time'),
			'best' => max(1, $int('best')), 'rank' => $int('rank'), 'entered' => $int('entered'), 'published' => $int('published'),
			'before' => ($story['before'] ?? false) === true,
		];
	}

	/**
	 * The RSS document of the stories of a state that entered the feed: those in the top first, in their order, then the
	 * others, the latest out first. Each is dated when it entered the feed, with $date `entered`; or, when it was in the
	 * top at the feed’s first refresh, when its lead article was published, rather than all at once; with $date
	 * `published`, always so. Its content says how long it has been in the top, from when, its best rank, and its rank
	 * now, in $timezone; and lists its articles. With $titleTime, its title starts with its time in the top and its best
	 * rank, e.g. “3 h · #1 · Title”.
	 * @param array{version:int,url:string,refreshed:int,top:int,title:string,link:string,stories:array<string,array<string,mixed>>} $state
	 */
	public static function rss(array $state, string $date = self::DATE_DEFAULT, bool $titleTime = false, ?DateTimeZone $timezone = null): string {
		$timezone ??= new DateTimeZone(date_default_timezone_get());
		$top = $state['top'];
		$stories = array_filter(array_map(self::story(...), $state['stories']), static fn(array $story): bool => $story['entered'] > 0);
		uksort($stories, static fn(int|string $a, int|string $b): int =>
			[$stories[$a]['rank'] === 0, $stories[$a]['rank'], -$stories[$a]['last'], (string)$a]
			<=> [$stories[$b]['rank'] === 0, $stories[$b]['rank'], -$stories[$b]['last'], (string)$b]);
		$name = self::name($state['title']);
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n"
			. "<channel>\n"
			. self::element('title', 'Google News: ' . $name . ' (top ' . $top . ')')
			. self::element('link', $state['link'] !== '' ? $state['link'] : self::HOST . '/')
			. self::element('description', 'The top ' . $top . ' of Google News’s ' . $name . ', each story dated when it '
				. ($date === 'published' ? 'was published' : 'entered it') . ', with how long it stayed');
		foreach ($stories as $id => $story) {
			$time = match (true) {
				$date === 'published' && $story['published'] > 0 => $story['published'],
				$story['before'] && $story['published'] > 0 => min($story['entered'], $story['published']),
				default => $story['entered'],
			};
			$xml .= "<item>\n"
				. self::element('title', ($titleTime ? self::shortDuration($story['time']) . ' · #' . $story['best'] . ' · ' : '') . $story['title'])
				. self::element('link', $story['link'])
				. '<guid isPermaLink="false">' . self::escape((string)$id) . "</guid>\n"
				. self::element('pubDate', gmdate(DATE_RSS, $time))
				. ($story['source'] !== '' ? self::element('dc:creator', $story['source']) : '')
				. '<description><![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', self::xmlChars(self::html($story, $top, $timezone))) . "]]></description>\n"
				. "</item>\n";
		}
		return $xml . "</channel>\n</rss>\n";
	}

	/** The name of a Google News feed from its title, e.g. Top stories, Headlines, Technology */
	private static function name(string $title): string {
		$name = trim(preg_replace('/(?:\s+-\s+(?:Latest|Google News))+$/i', '', $title) ?? '');
		return $name !== '' ? $name : 'Top stories';
	}

	/**
	 * A story’s content: its time in the top, then its articles, the lead first with its time, and its full coverage.
	 * @param array{keys:list<string>,title:string,source:string,link:string,articles:list<array{title:string,source:string,link:string}>,story:string,first:int,last:int,time:int,best:int,rank:int,entered:int,published:int,before:bool} $story
	 */
	private static function html(array $story, int $top, DateTimeZone $timezone): string {
		$at = static fn(int $time): string => (new DateTimeImmutable('@' . $time))->setTimezone($timezone)->format('D j M, H:i');
		$since = ($story['before'] ? 'before ' : '') . $at($story['first']);
		$for = ($story['before'] ? 'at least ' : '') . self::duration($story['time']);
		if ($story['rank'] > 0) {
			$facts = ['<strong>#' . $story['rank'] . ' in the top ' . $top . '</strong>',
				$story['time'] >= 60 ? 'in it for ' . $for . ' since ' . $since : 'in it since ' . $since];
		} else {
			$facts = [$story['time'] >= 60
				? 'Was in the top ' . $top . ' for ' . $for . ', from ' . $since . ' to ' . $at($story['last'])
				: 'Was in the top ' . $top . ' briefly, ' . ($story['before'] ? 'before ' : 'at ') . $at($story['last'])];
		}
		$facts[] = 'best #' . $story['best'];
		$html = '<p>' . implode(' · ', $facts) . "</p>\n<ol>\n";
		foreach ($story['articles'] as $n => $article) {
			$html .= '<li>' . self::link($article['link'], $article['title'])
				. ($article['source'] !== '' ? ' — ' . self::escape($article['source']) : '')
				. ($n === 0 && $article['link'] === $story['link'] && $story['published'] > 0 ? ', ' . $at($story['published']) : '')
				. "</li>\n";
		}
		$html .= "</ol>\n";
		if ($story['story'] !== '') {
			$html .= '<p>' . self::link($story['story'], 'Full coverage on Google News') . "</p>\n";
		}
		return $html;
	}

	/** E.g. 45 min, 3 h, 3 h 20 min */
	private static function duration(int $seconds): string {
		$minutes = intdiv(max(0, $seconds), 60);
		return match (true) {
			$minutes < 60 => $minutes . ' min',
			$minutes % 60 === 0 => intdiv($minutes, 60) . ' h',
			default => intdiv($minutes, 60) . ' h ' . ($minutes % 60) . ' min',
		};
	}

	/** Whole hours, for titles: <1 h, 3 h */
	private static function shortDuration(int $seconds): string {
		return $seconds < 3600 ? '<1 h' : intdiv($seconds, 3600) . ' h';
	}

	/** A string value, trimmed, with its runs of white space made one space */
	private static function text(string $value): string {
		return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
	}

	private static function link(string $url, string $text): string {
		return '<a href="' . self::escape($url) . '">' . self::escape($text) . '</a>';
	}

	private static function element(string $name, string $value): string {
		return '<' . $name . '>' . self::escape($value) . '</' . $name . ">\n";
	}

	private static function escape(string $text): string {
		return htmlspecialchars(self::xmlChars($text), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/** Drops characters XML 1.0 does not allow. */
	private static function xmlChars(string $text): string {
		return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
	}
}
