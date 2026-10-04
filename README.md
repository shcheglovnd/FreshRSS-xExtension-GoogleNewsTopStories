# Google News Top Stories — FreshRSS extension

Follow the top stories of [Google News](https://news.google.com) in [FreshRSS](https://freshrss.org): each story is
one entry however often Google changes its lead article, dated when it enters the top 10 (or another number), and
with how long it stays there and its best rank.

## Why

* Google News has an RSS feed of its top stories, `https://news.google.com/rss?hl=en-US&gl=US&ceid=US:en`, in its own
  order: the first 10 are the top stories of its home page, then come 4 stories of each section (World, Business,
  Technology, Entertainment, Sports, Science, Health).
* An item’s ID is its lead article, and Google keeps changing it. From 3 October 2026 17:24 to the next morning 09:45
  (16 hours), 28 stories passed through the top 10 of the U.S. edition with 80 different lead articles. A reader
  would have shown 15 of them 2 to 9 times.
* An item is dated by its lead article, often hours before the story reached the top: 1 to 7 hours for the top 10 at
  one moment, up to 4 days below it. In a reader sorted by date, a new top story lands below what was already read.
* Google gives no measure of popularity: no counts of readers, and every story lists up to 5 articles. Its rank and
  how long it stays in the top are the measures.

## What it does

* **Subscribe to `https://news.google.com/rss?hl=en-US&gl=US&ceid=US:en`**, or paste the address of the Google News
  page. The feed follows the first 10 stories, a setting that a feed can have its own of (see below).
* **A story is one entry.** It is recognised by its articles from one refresh to the next: their IDs, their titles with
  their outlets, and its ID on Google News when given. So it stays one entry when Google changes its lead article, and
  when it leaves the top and comes back within 3 days.
* **A story arrives when it enters the top, and is dated then**, so it comes first in a reader sorted by date. Or,
  as a setting, after it has been in the top for a minimum time. The stories already in the top when the feed is
  added are dated when their lead article was published, rather than all at once.
* **Each story says** its rank now, how long it has been in the top, since when and its best rank; after it leaves,
  for how long it was in the top, from when to when. It lists its articles with their outlets, the lead’s with the
  time it was published, and links to its full coverage on Google News when Google gives it. Its title and link are
  its lead article’s, through Google’s link to it; its author is the lead’s outlet.
* **While a story is in the top, its entry is updated** at each refresh with its time, its rank and its lead. FreshRSS
  keeps its date and read state.

The time in the top is measured at the feed’s refreshes, so to within their interval. A refresh reads Google’s feed
once; FreshRSS keeps it for 13 minutes. A shorter refresh interval for the feed, in its settings, makes the time more
precise: 30 minutes or 15.

## Settings

Under *Settings → Extensions → Google News Top Stories*:

* **Stories in the top**, 10 by default. In the 16 hours above, 28 stories passed through the top 10, and 12 through
  the top 5. On the home page’s feed, after the 10 top stories come the sections’ stories, so a top above 10 takes
  them in. A topic, such as Headlines, ranks up to 70 (see below).
* **Minimum time in the top**, in hours, 0 by default: every story arrives as soon as it enters the top. Otherwise a
  story arrives once it has been in the top for this long in all, and is dated then. Of the 28 stories above, 22 stayed
  at least an hour, 17 at least 2 hours and 14 at least 3.
* **Date of a story**: when it arrives in the feed (the default), or when its lead article was published, as Google
  gives it. A change applies to the stories that arrive after it.
* **Time in the top and best rank at the start of the titles**, off by default: “3 h · #1 · Searchers find debris of
  missing medical plane”. FreshRSS updates the titles while the stories are in the top.

A refresh applies changed settings at once: the extension drops SimplePie’s cached copy of the feed.

These are the defaults. **A feed can have its own** in its address, so you can follow several tops:

```text
https://news.google.com/rss?hl=en-US&gl=US&ceid=US:en&top=5
https://news.google.com/rss?hl=en-US&gl=US&ceid=US:en&top=10&min_hours=2&title_time=1
https://news.google.com/rss?hl=en-US&gl=US&ceid=US:en&top=3&date=published
```

`top` is 1 to 100, `min_hours` 0 to 48, `date` `entered` or `published`, and `title_time` `1` or `0` (also
`true`/`false`, `yes`/`no`, `on`/`off`). Out-of-range values are brought within bounds. Google doesn’t get them.
A story in two feeds’ tops is in both, as separate entries. A feed is named after its top when added, e.g. “Google
News: Top stories (top 10)”, and FreshRSS doesn’t rename it later.

## Other Google News feeds

* **Editions**: `hl`, `gl` and `ceid` give Google’s edition, e.g. `hl=en-GB&gl=GB&ceid=GB:en`. Without them, Google
  picks one.
* **Topics and sections** are ranked too, and work the same: Headlines,
  `https://news.google.com/rss/topics/CAAqJggKIiBDQkFTRWdvSUwyMHZNRFZxYUdjU0FtVnVHZ0pWVXlnQVAB?hl=en-US&gl=US&ceid=US:en`,
  which ranks up to 70 stories and only partly matches the home page’s top 10, or e.g.
  `https://news.google.com/rss/headlines/section/topic/TECHNOLOGY?hl=en-US&gl=US&ceid=US:en`. The address of their
  page works too, e.g. `https://news.google.com/topics/CAAq…`.
* **Searches** (`/rss/search?q=…`) and single stories are not ranked tops, and are left alone.

## What it keeps

What a feed knows of its stories, their articles and times, is kept between refreshes in
`data/users/<user>/extensions/GoogleNewsTopStories/feed-<id>.json`, about 3 KB per story, so a few hundred KB at most
for a top 10. A story out of the top
for 3 days is dropped from it, and from the feed; FreshRSS keeps its entry as it keeps any other.

Without that file, a feed starts anew: the stories in the top are dated when their lead article was published, and
one whose lead changed since its entry arrived comes once more.

## Installation

1. Put this repository in FreshRSS’s `extensions/` folder, e.g. as
   `extensions/xExtension-GoogleNewsTopStories`:

   ```sh
   cd /path/to/FreshRSS/extensions
   git clone https://github.com/shcheglovnd/FreshRSS-xExtension-GoogleNewsTopStories.git xExtension-GoogleNewsTopStories
   ```

2. Enable **Google News Top Stories** under *Settings → Extensions*.
3. *Add a feed* with `https://news.google.com/rss?hl=en-US&gl=US&ceid=US:en`, or an address with its own settings.

Requires FreshRSS 1.28 or newer.

## Development

```sh
php tests/topstories.php                                 # URLs, parsing, tracking and RSS, without FreshRSS or network
php tests/preview.php ['https://news.google.com/rss?…']  # what a new feed would list now, from Google
php tests/replay.php 'samples/*.xml.gz' [top] [min_hours]  # saved downloads of Google’s feed, named by their time
```

`tests/replay.php` runs downloads such as `20261003T222421Z.xml.gz`, saved every 10 minutes, through the tracking as
if FreshRSS had refreshed at each, and lists the stories with their times in the top.

## License

[MIT](LICENSE)
