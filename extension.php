<?php

declare(strict_types=1);

/**
 * The top stories of Google News as a feed that follows each story while it is in the top N, subscribed to as
 * https://news.google.com/rss?hl=en-US&gl=US&ceid=US:en, or a topic or section of Google News.
 *
 * The feed is built from Google’s own RSS ({@see GoogleNewsTopStories}): a story stays one entry when Google changes its
 * lead article, is dated when it enters the top N, and says how long it has been in it. What is known of a feed’s
 * stories is kept between refreshes in a file of the user’s data, one per feed.
 * How many stories, a minimum time in the top, the dates and the time in the titles are settings, which a feed’s URL may
 * override: https://news.google.com/rss?hl=en-US&gl=US&ceid=US:en&top=5&min_hours=1&date=published&title_time=1.
 */
final class GoogleNewsTopStoriesExtension extends Minz_Extension {
	/** @var array<int,true> IDs of the feeds read by this extension during this request */
	private array $feedIds = [];

	#[\Override]
	public function init(): void {
		parent::init();
		require_once __DIR__ . '/lib/GoogleNewsTopStories.php';
		$this->registerTranslates();
		// Hook names as strings rather than Minz_HookType, to stay compatible with FreshRSS 1.28
		$this->registerHook('check_url_before_add', [$this, 'checkUrlBeforeAdd']);
		$this->registerHook('simplepie_before_init', [$this, 'simplepieBeforeInit']);
		$this->registerHook('entry_before_update', [$this, 'entryBeforeUpdate']);
	}

	/**
	 * “Add a feed” accepts Google News’s home page, a topic or a section, as a page or as RSS, which becomes its RSS URL,
	 * with its own `top`, `min_hours`, `date` and `title_time` if given.
	 */
	public function checkUrlBeforeAdd(string $url): string {
		return GoogleNewsTopStories::feedUrl($url) ?? $url;
	}

	/**
	 * Before SimplePie fetches the feed, read Google’s RSS, follow its top stories ({@see track()}), and give SimplePie
	 * the RSS of the stories followed instead.
	 * @throws FreshRSS_Feed_Exception when Google’s RSS cannot be read
	 */
	public function simplepieBeforeInit(FreshRSS_SimplePieCustom $simplePie, FreshRSS_Feed $feed): void {
		if (!in_array($feed->kind(), [FreshRSS_Feed::KIND_RSS, FreshRSS_Feed::KIND_RSS_FORCED], true)) {
			return;
		}
		$url = htmlspecialchars_decode($feed->url(), ENT_QUOTES);
		$googleUrl = GoogleNewsTopStories::googleUrl($url);
		if ($googleUrl === null || $googleUrl === '') {
			return;
		}
		$parsed = GoogleNewsTopStories::parse(self::fetch($googleUrl, $feed));
		if ($parsed === null || $parsed['items'] === []) {
			throw new FreshRSS_Feed_Exception(_t('ext.google_news_top_stories.error.format', $googleUrl));
		}
		$settings = GoogleNewsTopStories::urlSettings($url);
		$state = $this->track($feed, $parsed, $googleUrl, $settings['top'] ?? $this->top(), $settings['min_hours'] ?? $this->minHours());
		$rss = GoogleNewsTopStories::rss($state, $settings['date'] ?? $this->date(), $settings['title_time'] ?? $this->titleTime(), self::timezone());
		$this->feedIds[$feed->id()] = true;

		// SimplePie would answer a refresh within its cache duration (800 s) from its cache rather than from this RSS, which
		// reflects the current settings. Drop the cache as FreshRSS’s own option to clear it does, without its favicon rebuild.
		@unlink($feed->cacheFilename());

		// With a Content-Type rather than force_feed(true), which would make SimplePie cache the feed under another name
		// than the one FreshRSS reads the time of the last refresh from
		$response = (new \SimplePie\HTTP\RawTextResponse($rss, $url))->with_header('content-type', 'application/rss+xml; charset=UTF-8');
		$file = \SimplePie\File::fromResponse($response);
		$simplePie->set_file($file);	// takes a reference
	}

	/**
	 * Follows the stories of a feed through this refresh, from its state of the last refresh, which is then saved, a file
	 * of the user’s data per feed, under a lock as refreshes of a feed may overlap. A feed being added, which FreshRSS
	 * reads before it has an ID and then refreshes, is not saved.
	 * @param array{title:string,link:string,items:list<array{id:string,title:string,source:string,link:string,date:int,story:string,articles:list<array{title:string,source:string,link:string}>,keys:list<string>}>} $parsed
	 * @return array{version:int,url:string,refreshed:int,top:int,title:string,link:string,stories:array<string,array<string,mixed>>}
	 */
	private function track(FreshRSS_Feed $feed, array $parsed, string $googleUrl, int $top, float $minHours): array {
		if ($feed->id() <= 0) {
			return GoogleNewsTopStories::track([], $parsed, time(), $top, $minHours, $googleUrl);
		}
		$dir = $this->getExtensionUserPath();
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}
		$path = $dir . '/feed-' . $feed->id() . '.json';
		$lock = @fopen($path . '.lock', 'c');
		if ($lock !== false) {
			flock($lock, LOCK_EX);
		}
		try {
			$json = @file_get_contents($path);
			$state = is_string($json) ? json_decode($json, true) : null;
			$state = GoogleNewsTopStories::track(is_array($state) ? $state : [], $parsed, time(), $top, $minHours, $googleUrl);
			$json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
			if (!is_string($json) || @file_put_contents($path . '.tmp', $json) === false || !@rename($path . '.tmp', $path)) {
				Minz_Log::warning('Google News Top Stories: could not save ' . $path);
			}
			return $state;
		} finally {
			if ($lock !== false) {
				flock($lock, LOCK_UN);
				fclose($lock);
			}
		}
	}

	/**
	 * Google’s RSS, which FreshRSS caches for its cache duration (800 s): feeds refreshed together share it.
	 * @param non-empty-string $url
	 * @throws FreshRSS_Feed_Exception
	 */
	private static function fetch(string $url, FreshRSS_Feed $feed): string {
		$response = FreshRSS_http_Util::httpGet($url, $feed->cacheFilename($url), 'xml', $feed->attributes(), $feed->curlOptions());
		if ($response['fail'] || $response['body'] === '') {
			// `status` and `error` are only returned since FreshRSS 1.29
			$status = $response['status'] ?? 0;
			$status = is_int($status) ? abs($status) : 0;
			$error = $response['error'] ?? '';
			throw new FreshRSS_Feed_Exception(_t('ext.google_news_top_stories.error.fetch', $url,
				$status > 0 ? 'HTTP ' . $status : (is_string($error) && $error !== '' ? $error : '?')), $status);
		}
		return $response['body'];
	}

	/** The user’s time zone, in which a story’s content gives its times, as FreshRSS shows the dates */
	private static function timezone(): ?DateTimeZone {
		$timezone = FreshRSS_Context::hasUserConf() ? FreshRSS_Context::userConf()->timezone : '';
		try {
			return new DateTimeZone($timezone !== '' ? $timezone : date_default_timezone_get());
		} catch (Exception) {
			return null;
		}
	}

	/**
	 * A story in the top changes at each refresh, with its time in it, its rank and its lead article. FreshRSS then
	 * updates it with the date it is given, which with `published` is its new lead’s; keep its first date.
	 */
	public function entryBeforeUpdate(FreshRSS_Entry $entry): FreshRSS_Entry {
		if (isset($this->feedIds[$entry->feedId()])) {
			$stored = FreshRSS_Factory::createEntryDao()->searchByGuid($entry->feedId(), $entry->guid());
			if ($stored !== null) {
				$entry->_date($stored->date(true));
			}
		}
		return $entry;
	}

	#[\Override]
	public function handleConfigureAction(): void {
		require_once __DIR__ . '/lib/GoogleNewsTopStories.php';	// FreshRSS also configures a disabled extension, without init()
		$this->registerTranslates();
		if (Minz_Request::isPost()) {
			$minHours = str_replace(',', '.', Minz_Request::paramString('min_hours'));
			$this->setUserConfiguration([
				'top' => GoogleNewsTopStories::boundTop(Minz_Request::paramInt('top')),
				'min_hours' => GoogleNewsTopStories::boundMinHours(is_numeric($minHours) ? (float)$minHours : 0.0),
				'date' => GoogleNewsTopStories::date(Minz_Request::paramString('date')) ?? GoogleNewsTopStories::DATE_DEFAULT,
				'title_time' => Minz_Request::paramBoolean('title_time'),
			]);
		}
	}

	// getUserConfigurationValue() rather than the typed getters, which need FreshRSS 1.29
	/** How many of Google’s stories are followed, for the feeds whose URL does not say */
	public function top(): int {
		$top = $this->getUserConfigurationValue('top');
		return is_int($top) ? GoogleNewsTopStories::boundTop($top) : GoogleNewsTopStories::TOP_DEFAULT;
	}

	/** How long a story must have been in the top before it enters the feed, in hours, for the feeds whose URL does not say */
	public function minHours(): float {
		$minHours = $this->getUserConfigurationValue('min_hours');
		return is_int($minHours) || is_float($minHours) ? GoogleNewsTopStories::boundMinHours((float)$minHours) : 0.0;
	}

	/** How the stories of the feeds whose URL does not say are dated: `entered` or `published` */
	public function date(): string {
		$date = $this->getUserConfigurationValue('date');
		return (is_string($date) ? GoogleNewsTopStories::date($date) : null) ?? GoogleNewsTopStories::DATE_DEFAULT;
	}

	/** Whether the titles start with the time in the top and the best rank, for the feeds whose URL does not say */
	public function titleTime(): bool {
		return $this->getUserConfigurationValue('title_time') === true;
	}
}
