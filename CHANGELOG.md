# Changelog

## 0.1.0 — 2026-10-04

First release.

* `https://news.google.com/rss?hl=en-US&gl=US&ceid=US:en`, a topic or a section of Google News as a feed of its first
  N stories, 10 by default, read from Google’s own RSS, one request per refresh. The address of the page works too.
* A story is recognised by its articles from one refresh to the next, so it stays one entry when Google changes its
  lead article, and when it leaves the top and comes back within 3 days.
* Stories are dated when they enter the top; on a feed’s first refresh, when their lead article was published. Or, as
  a setting, always when their lead article was published. Updates keep their date and read state.
* Each story gives its rank now, its time in the top, since when, and its best rank; after it leaves, from when to
  when. It lists its articles with their outlets, and links to its full coverage when Google gives it.
* A minimum time in the top before a story arrives: a setting, 0 by default.
* Time in the top and best rank at the start of the titles, e.g. “3 h · #1 · Title”: a setting, off by default.
* A feed’s own settings in its address, e.g. `…&top=5&min_hours=1&date=published&title_time=1`. The extension’s
  settings are the defaults.
* What a feed knows of its stories is kept in a file of the user’s data per feed.
* Works on FreshRSS 1.28 and newer.
