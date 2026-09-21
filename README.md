# Fili

A WordPress plugin that finds **the internal links your site is missing** and **the posts that tell the same news twice**.

It proposes, it does not write. You approve every link, and one click undoes it.

The judgement comes from [Jev](https://typesafe.ai) by TypeSafe AI, a model that writes no text: it answers yes, no, or pick one of these. That is why it costs almost nothing.

On the site Fili was built for, 777 posts, a full run inside WordPress made **13,705 decisions for about $0.29, using 53 MB of memory**.

*[Leggimi in italiano](README.it.md)*

> **Status: 0.1.5, tested on one site.** Not ready for other people's production sites yet. The code-level checks are tuned for Italian; the English lists are a first draft that has not been measured.

---

## What it does

1. **Reads** your published posts and builds an index in tables of its own.
2. **Proposes** the links: for every post it finds the closest ones and asks Jev whether the connection holds and which phrase, *already written in the post*, can carry it.
3. **You review**: every proposal is shown inside the sentence it would live in. Keep it or drop it, one at a time or a whole page at once.
4. **Applies** only what you approved, a few links at a time over hours or days.
5. **Reports duplicates**: groups of posts about the same piece of news. It only points them out, it never merges anything.

## The rules

**On your content**

- It never writes without approval. Freshly installed the safety catch is on, so it cannot touch a post at all.
- The anchor is text that already exists, word for word, outside any existing link, heading, quote, caption, code block, shortcode or Gutenberg comment.
- At most three new links per post, never to itself, never where a link already exists, never between duplicates, never a link inside another link.
- Every change is a WordPress revision and undoes back to a byte-identical post.
- The modified date moves, because [Google counts a change to a page's links as a significant update](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap) and an inaccurate `lastmod` is worse than none. Undo restores the original date too.

**On quality**

- Rules a machine verifies better than a model live in the code: a verb inside the anchor, a fragment starting on a preposition, the site's own boilerplate. Jev is left to do the one thing that needs reading.
- **The threshold is measured on your site.** After twenty judged proposals, Fili works out the score above which you kept at least 85%. There is no number that is right for everyone.

**On privacy**

- Only the title and text of **already public** content leave your site. Drafts and private posts are never read.
- Fili talks to one service: the one you choose, with your key. **No telemetry, no external fonts or scripts.** One file makes network calls, `includes/class-fili-jev.php`. Read it.
- The key goes in `wp-config.php` (recommended) or in the database, shown masked and never printed back in full. You can move it between the two from the settings screen, and the plugin leaves no copy of `wp-config.php` in your site folder: a copy there is downloadable by anyone who guesses the name.
- A monthly spending cap stops everything when reached, and the spend is visible on every screen.

## What we measured, mistakes included

All figures come from a real 777-post site, on a bench with PHP capped at 128 MB like shared hosting.

**Timing, stated honestly.** The time is not Jev's, which answers in a fraction of a second: it is network waiting.

| How | Time for the links of 777 posts |
|---|---|
| External prototype, 24 requests in parallel, via OpenRouter | 16 seconds |
| External prototype, 24 in parallel, official TypeSafe API | 145 seconds |
| Fili inside WordPress, one request at a time | about 12 minutes |
| Fili inside WordPress, four in parallel (the default) | about 4 minutes, extrapolated from 60 measured posts |

The cost is the same in every case: you pay for the characters read, not for the time.

**Quality.** The first version of the questions gave **64%** of proposals worth keeping. Rewriting the questions against the rejected cases: **91%** (20 out of 22 in a hand-read sample).

**Undo.** On six posts, three Gutenberg and three classic: links applied, then undone, and the content came back **byte-identical six times out of six**, blocks intact, no link nested inside another.

**The mistakes**

- Using how often a phrase occurs on the site as a measure of quality **does not work**: it only identifies boilerplate (a signature line present in 430 of 777 posts).
- Of three questions written to tell a duplicate from a genuine follow-up, **two were useless**: one always answered yes, the other always no. A question is judged by how its answers spread out, not by how it is worded.
- The `confidence` a `choice` question returns measures how clear-cut the pick was, not whether it is right: across 6,202 picks the median was 0.63 where the link was strong and 0.55 where it was weak.
- The spend shown is an **estimate** from the characters sent, because the official API does not report the cost. On a full run it said $0.29 against $0.26 measured another way.

## What it found on the site it was built for

- **48 groups of posts about the same piece of news**, 16 of them with three or more, the worst with five in six days.
- The two halves of the site barely speak to each other: 190 posts up to 2021 about photography, 439 from 2025 about AI, and **one single proposal crossing between them out of 223**.
- Two typos, found because an anchor landed on them.

## Install

Copy the `fili/` folder into `wp-content/plugins/`, activate it, put your key in *Fili → Settings*, start the run.

**Requirements**: WordPress 6.4+, PHP 8.0+, a [TypeSafe](https://typesafe.ai) or [OpenRouter](https://openrouter.ai) API key.

From the command line: `wp fili run`, `wp fili status`, `wp fili roundtrip <id>...` (applies, undoes and checks the post came back identical).

## Languages

The interface ships in **English** and **Italian** and follows the site language. Translations live in `languages/`; to add one, copy `fili-it_IT.po` and translate it.

The code-level checks (verbs, prepositions, question words) are per language, in `lang/`. The Italian list is tuned on a real site; the English one is a first draft that has not been measured. On a site in any other language those checks catch nothing and quality falls back towards 64%, so Fili says so rather than pretending otherwise.

## Licence

MIT. Use it, change it, redistribute it: just keep the copyright notice.

Fili is not affiliated with TypeSafe AI. Jev is their product.
