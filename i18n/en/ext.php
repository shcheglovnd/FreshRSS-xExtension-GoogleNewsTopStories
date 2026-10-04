<?php

return [
	'google_news_top_stories' => [
		'intro' => 'Subscribe with “Add a feed” and <code>https://news.google.com/rss?hl=en-US&amp;gl=US&amp;ceid=US:en</code>, or the address of a Google News page: the home page, a topic or a section. The feed follows the top stories of Google News. A story arrives when it enters the top and is dated then. It stays one entry when Google changes its lead article, or when it leaves the top and comes back, and it says how long it has been in the top and its best rank.',
		'top' => 'Stories in the top',
		'top_help' => 'On the home page, the first 10 stories are Google’s top stories, as on news.google.com; after them come 4 of each section (World, Business, Technology…). A topic, such as Headlines, ranks up to 70. From 3 October 2026 17:24 to the next morning 09:45 (16 hours), 28 stories passed through the top 10 of the U.S. edition, and 12 through the top 5.',
		'min_hours' => 'Minimum time in the top, in hours',
		'min_hours_help' => '0 to take every story as soon as it enters the top. Otherwise a story arrives once it has been in the top for this long in all, and is dated then. Of those 28 stories, 22 stayed at least an hour, 17 at least 2 hours and 14 at least 3. The time is measured at the feed’s refreshes, so to within their interval. A change applies to the stories that arrive after it.',
		'date' => 'Date of a story',
		'dates' => [
			'entered' => 'When it arrives in the feed',
			'published' => 'When its lead article was published',
		],
		'date_help' => 'When it arrives: at the refresh that first finds it in the top, or after the minimum time, so that it comes first in a reader sorted by date. The stories already in the top when a feed is added are dated when their lead article was published. When its lead article was published: as Google gives it, often hours before the story reached the top. A change applies to the stories that arrive after it.',
		'title_time' => 'Time in the top and best rank at the start of the titles',
		'title_time_help' => 'E.g. “3 h · #1 · Searchers find debris of missing medical plane”. FreshRSS updates the titles while the stories are in the top, keeping their dates and read state.',
		'url_settings' => 'These are the defaults. A feed can have its own in its address, e.g. <code>https://news.google.com/rss?hl=en-US&amp;gl=US&amp;ceid=US:en&amp;top=5&amp;min_hours=1&amp;date=published&amp;title_time=1</code> (<code>date</code> is <code>entered</code> or <code>published</code>, and <code>title_time=0</code> is for none), so you can follow several tops. <code>hl</code>, <code>gl</code> and <code>ceid</code> are Google’s edition.',
		'error' => [
			'fetch' => 'Google News Top Stories: could not load %s (%s)',
			'format' => 'Google News Top Stories: %s did not answer with a list of stories; Google News may have changed',
		],
	],
];
