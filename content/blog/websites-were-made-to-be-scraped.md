---
title: Websites are made to be scraped
slug: websites-are-made-to-be-scraped
date: 2026-03-18
excerpt: You either die a hero, or live long enough to scrape data. This is how I like to do it.
tags: [engineering, programming, development]
published: true
---

![Animated flow](batman-batman-the-animated-series.gif)

I’ve scraped over 200 sites that include Shopify stores, event catalogs, company marketplaces, and much more. In my opinion, chasing perfect evasion is pointless. It’s fun to figure out how to outsmart the site, I’m here for the data. Assume you will face guardrails, captchas, and rate limits. A good scraper is one that is stable, portable, restartable, polite, and shaped around the path of least resistance to the data. If you do that consistently, you can get very far without turning the job into a cat-and-mouse game.

I’m writing this both to document what has worked for me and to hopefully help someone figuring this out. At the end of the article is a copy and pasteable LLM instruction prompt, so you can just give it the site you want scraped and it can just pump out a script you can run right away. LLMs were indispensable every time I had a site to scrape.

<aside>
‼️ Scraping is easy to get wrong. Even when the data looks public, you can still run into rate limits, brittle page changes, bad data quality, privacy issues, terms-of-service problems, or legal risk if you access the wrong surfaces or collect the wrong information. Treat scraping as an engineering and policy problem, not just a scripting problem: be conservative, be polite, and assume that “it works” is not the same thing as “it is safe.”
</aside>

# 1. Start by asking what the site is really giving you

Inspect the site like an engineer, not a tourist. You are looking for the cheapest trustworthy source of truth.

That usually means checking, in order:

- public JSON endpoints
- XHR or fetch requests in the network tab
- pagination endpoints
- sitemap or index pages
- stable server-rendered HTML
- only then, a headless browser

You want to avoid writing a scraping script for a site before realizing that it has an exposed endpoint you can use. A lot of people jump straight to Playwright or Puppeteer when it’s usually overkill. If the site already exposes public structured data, use that.

Check the network tab of inspect element. You’d be surprised how many projects leave their API endpoints available for querying. You can often guess at what frameworks they’re using just based on the shape of the URL endpoint args. Back when [AminoChain](https://aminochain.io/) were still in beta, I guessed at their beta’s sign in page (often an orphan link at the /signin or /login page), signed in like a normal user, and queried their API to access all >300K biosamples they had at the time (they were using elasticsearch, which required some tricks to get around their **`max_result_window`**). I could download any subset of it that I wanted.

That is one of the strongest recurring patterns in my own scripts. On Shopify-like storefronts, the best move is often not “scrape the page.” It is “find the public product JSON and start there.” One script I wrote deliberately tries collection JSON first, then falls back to the global products endpoint, and only uses HTML when it adds real value. A products.json that might just have all the data you need, or at least serve as a starting point for further scraping. Data will be cleaner, load faster, and in bulk.

The first rule of robust scraping is simple:

**Do not scrape unless you have to.**

# 2. Decide whether your source is breadth, depth, or both

Once you know where the data lives, decide what each source is good for. Generally, the data you’re looking for can be classified as either one involving breadth or depth.

Breadth: all products, all listings, all records, all result pages.

Depth: metadata, detail fields, descriptions, attachments, normalized labels.

A good example of this pattern in my own work is the tea scrapers that uses Shopify collection JSON to get the full product catalog, then fetches product HTML only to enrich each record with the fields that were missing or messy in the JSON feed. That is a much saner design than pretending HTML should do everything. JSON gives you coverage. HTML gives you nuance. If nothing else, at least you can cross reference what rendered sites give you against what the API serves.

If you are building your own scraper, decide early:

- what endpoint gives me the inventory of things
- what page gives me the full detail for each thing
- whether I actually need both

A surprising amount of scraper quality comes from making that split explicitly.

# 3. Shape your output before you scrape anything

This is where most scripts quietly become unmaintainable.

Don’t start by “just grabbing fields,” then six hours later end up with a weird bag of half-clean strings and no idea how to use them.

I try to define the output shape first.

Not just `title` and `url`, but an actual record with structure:

- source metadata
- canonical entity fields
- raw extracted fields
- derived fields
- images or attachments
- provenance

Ideally you have a well thought-out and written schema, complete with attribute names, types, and relations. In many of my Shopify scrapers, every record already looks like an application object instead of a dump: source, company, vendor, tea, purchaseLinks, images, tags, categories. In another, the output is organized around `teaCore`, `purchaseLinks`, `images`, `notes`, and `raw`. That kind of structure saves you from doing a second ETL job later just to make the first one usable.

If you are writing your own scraper, decide up front:

- what is canonical data
- what is best-effort inferred data (and potentially nullable/optional)
- what raw context should be preserved for debugging

That one decision will improve the rest of the script more than any library choice.

# 4. Build discovery separately from extraction

There are really two problems in most scraping jobs:

1. find the things
2. extract the things

Give each problem due effort. A lot of more reliable scrapers start with a list of targets:

- product handles
- detail page URLs
- result page URLs
- sitemap entries
- search result links

Then they run extraction over that stable list.

Several of my scripts follow this pattern. The contract research scrapers treat URL discovery as a separate stage, then iterate over a filtered set of target pages for the actual extraction. My scripts to scrape rare disease patient groups first gathers links from results pages, deduplicates them, and only then goes page by page for detail extraction. That separation makes failure recovery much easier because you know whether discovery failed or extraction failed. If you ever have to resume the job, re-run part of it, or inspect why output is incomplete, you want discovery and extraction to be separable.

# 5. Prefer stable selectors over clever selectors

The best selectors are usually based on:

- stable labels
- repeated content blocks
- table structure
- semantic headings
- predictable URLs
- obvious DOM regions

The worst selectors are usually styling-dependent keywords that are prone to changing.

A good pattern is to anchor extraction to human-readable labels like “Year Established,” “Website,” or “Certifications,” then read the nearby content. Another is to identify a repeated listing card and extract the same internal fields from each card. That’s the kind of thing I’ve done in directory-style scrapers and logged-in data extractors. It’s much easier to maintain than trying to reverse-engineer every CSS flourish on the page.

If the designer changes colors and spacing tomorrow, does the scraper still work?

If the answer is no, the selector is probably too fragile.

# 6. Normalize as you extract, not six scripts later

Raw scraped text is full of garbage:

- duplicated whitespace
- HTML entities
- inconsistent capitalization
- missing units
- multi-value fields jammed into one string
- empty placeholders that look real
- artifacts from unclean renders or requests

You want small utilities for normalization from day one.

Things like:

- trim and collapse whitespace
- decode common entities
- normalize URLs
- parse tags consistently
- coerce numbers safely
- dedupe arrays
- strip HTML into readable text

These are simple, reusable functions that will keep the data you scrape clean.

You can see this pattern all over my scripts: helper functions for clean text, unique arrays, safe number parsing, normalized URLs, tag parsing, basic HTML stripping, title-cased field names. Those helpers make the rest of the extractor boring, which is exactly what you want.

Good scraping code is often just disciplined text hygiene wrapped around network requests.

But how do you decide what belongs in the scraping script vs what belongs in a seed script, or maybe a processing/normalization script?

A good rule is that the scraping script should do only what’s necessary to reliably fetch and extract the source data. If a piece of logic depends on discovering targets first, like generating URLs, collecting handles, or expanding a sitemap, that usually belongs in a seed script. If a piece of logic is about cleaning, reshaping, deduplicating, classifying, or enriching data after extraction, that usually belongs in a processing or normalization script.

I try not to make the scraper too smart. The scraper’s job is to get the data out without losing useful context. The seed script’s job is to decide what to fetch. The processing script’s job is to make the output more usable. It should be a funnel; you should only lose data going from raw to processed. Keeping those concerns separate makes the whole system easier to debug, easier to resume, and much easier to change when the site inevitably moves something around.

# 7. Capture both extracted fields and inferred fields

A lot of scraped data becomes useful only after you interpret it a little.

When I was scraping teas, that meant:

- inferring category from title, tags, and description
- inferring caffeine level from text
- extracting labelled fields from product descriptions
- mapping tasting notes into a normalized vocabulary
- collapsing variant options into a purchase unit

The important thing is not to blur the line between extracted and inferred.

Keep both.

One of my tea scripts does this well. It stores the original fields it extracted from the HTML, then separately adds inferred category, caffeine level, forms, brewing guidance, and mapped tasting notes. That gives you something standardized without losing the underlying evidence.

That is a pattern worth copying:

- preserve the raw source
- preserve the parsed field
- preserve the inferred interpretation

That way future you can debug the logic without having to re-scrape the world.

# 8. Make the scraper restartable before you think you need it

Assume interruption is normal; networks fail, sites flake, laptops sleep, containers restart, long jobs die. The plus side is that with this pattern, you can leave your script running with confidence that even if it fails halfway, you can always just resume it when you get back.

That means your script should do at least some of the following:

- write incrementally
- save current progress
- resume from a checkpoint
- handle `SIGINT` cleanly
- avoid holding everything in memory until the end

A few of my scrapers are built exactly this way. One saves a `currentIndex` and `results` object so it can resume after interruption. Another saves after every results page. Another appends JSONL continuously and closes the browser on shutdown. Unless I’m 100% confident that my script can get all the data I want in one shot (and in less than 10 mins), I always make my script restartable.

If your job is long enough to care about, it is long enough to checkpoint.

A very practical default is JSONL. One record per line. Easy to append. Easy to inspect. Easy to recover from partial runs.

# 9. Handle rate limits like an adult

A lot of scraping pain is self-inflicted.

If you hammer a site with aggressive concurrency, fixed timing, and no retry logic, you are not writing a strong scraper. You are writing a short-lived one.

The patterns that have held up best for me are:

- small bounded concurrency
- randomized jitter between requests
- exponential backoff for transient failures
- special handling for 429s
- global pause windows when the site tells you to slow down
- retry only the status codes that deserve it

My Shopify tea scraping scripts use this pattern: randomized delays, capped concurrency, retry budgets, and shared pause logic when the site returns 429. That makes the job slower in the small and much faster in the large because the run actually finishes.

A resilient scraper is not the one with the highest requests per second, it’s the one that still works later.

# 10. Use a browser only when the page deserves it

Headless browsers are great. They are also heavy, slower, and more failure-prone than direct HTTP.

So use them when you need them:

- JS-rendered listings
- tabbed content
- client-side pagination
- interactions required to reveal data
- authenticated sessions you are permitted to access

My own scripts reflect that split. Shopify JSON gets handled over HTTP because that is the right tool. Directory sites and JS-heavy pages get Puppeteer because a browser is genuinely required to surface the content. The logged-in Scientist script also uses a browser because the content and navigation justify it.

# 11. Separate orchestration from page scraping for large jobs

Once a scrape gets big enough, you should stop treating it like one script.

Split it up.

For example:

- one script discovers targets
- one script extracts a page
- one runner fans out jobs
- one sink writes to storage

That pattern shows up in my contract research workflow. One version delegates page scraping to a cloud function and just orchestrates requests plus result collection. That is a very useful shift once you have enough pages, enough runtime, or enough instability that you want the heavy work isolated.

You don’t need to overengineer it on day one.

If the scrape is important enough, make it composable/modular.

# 12. Log enough to know what failed without reading your code like a detective

A scraper without decent logging is miserable to operate.

At minimum, log:

- current URL or page
- current index
- page count or progress count
- reason for skipping
- retry attempts
- failure class
- output location

The scripts I trust most tend to have obvious progress bars, page-by-page logs, and explicit save points. That is not cosmetic. It is operational visibility. When something goes wrong halfway through page 143 of 400, you want the answer in the logs, not in your imagination.

It’s also nice to have an ETA for when the script will finish.

# 13. Treat legality and ethics as design constraints, not footnotes

This is where a lot of scraping advice gets stupid.

A robust scraper is not just a technically successful one. It is also one that respects the boundaries of the surface you are working with.

That means:

- prefer public data
- do not bypass authentication or technical controls
- do not collect personal data casually
- do not pretend rate limits are optional
- do not build around evasion as your main strategy
- check the site’s terms and the legal context that applies to you

That is not me being sanctimonious. It’s just practical. If your script depends on breaking access controls or constantly disguising itself, it is not robust. It is brittle in a different direction.

The patterns I trust most are the ones that still look reasonable when someone asks, “What exactly does this script do?”

# 14. Build the script around a simple pipeline

If you want a reliable default architecture, this is the one I recommend:

## Phase 1: configuration

Store your base URL, endpoint strategy, concurrency, delay ranges, max pages, and output path in one place.

## Phase 2: discovery

Find the inventory of targets: handles, IDs, URLs, page numbers, or result pages.

## Phase 3: fetch

Request each target with polite delay, bounded concurrency, retries, and backoff.

## Phase 4: extract

Pull out the raw fields using stable selectors or structured JSON parsing.

## Phase 5: normalize

Clean text, parse numbers, normalize URLs, dedupe arrays, standardize tags.

## Phase 6: infer

Add derived fields only after the raw extraction is stable.

## Phase 7: persist

Write incrementally. Prefer append-friendly formats for long runs.

## Phase 8: resume

Save checkpoints and make reruns idempotent where possible.

That architecture works in a local script, a container, or a serverless workflow because it does not depend on magic. It depends on separation of concerns.

# 15. A practical skeleton

This is the shape I would start from for almost any scraper:

```
typeRecordOut= {
  source: {
    url:string;
    page?:number;
    id?:string|number;
  };
  data:Record<string,unknown>;
  raw?:Record<string,unknown>;
};

asyncfunctiondiscoverTargets():Promise<string[]> {
// Return URLs, handles, page endpoints, etc.
return [];
}

asyncfunctionfetchWithRetry(url:string):Promise<string> {
// Add jitter, retry, backoff, and 429 handling
return"";
}

functionextract(htmlOrJson:string):Record<string,unknown> {
// Parse structured source or HTML
return {};
}

functionnormalize(data:Record<string,unknown>):Record<string,unknown> {
// Trim, coerce, decode, dedupe, standardize
returndata;
}

asyncfunctionmain() {
consttargets=awaitdiscoverTargets();

for (consttargetoftargets) {
try {
constbody=awaitfetchWithRetry(target);
constextracted=extract(body);
constcleaned=normalize(extracted);

constrow:RecordOut= {
        source: { url:target },
        data:cleaned,
        raw:extracted,
      };

// append row to JSONL or checkpointed output
    }catch (err) {
// log and continue
    }
  }
}
```

That is obviously incomplete, but the point is the shape.

The shape matters more than the framework.

# 16. What actually makes a scraper “portable”

Not “runnable anywhere.” Portable.

Those are different.

A portable scraper tends to have these traits:

- no hidden local assumptions
- environment variables for secrets and config
- clear dependency list
- deterministic output location
- graceful shutdown
- no GUI requirement unless absolutely necessary
- bounded memory use
- append-friendly persistence
- ability to resume after interruption

This is another reason I like simple Node or TypeScript scripts with straightforward helpers. They run locally, in a VM, in a container, or behind a job runner without much drama if the design is clean.

Portability is mostly about avoiding accidental coupling.

# 17. The real trick

There is no secret trick.

The real trick is resisting the temptation to write a clever scraper when a boring one will survive longer.

That means:

- inspect first
- choose the least fragile source
- separate discovery from extraction
- normalize early
- preserve provenance
- checkpoint aggressively
- slow down when the site tells you to
- only use a browser when the page forces your hand

That is the pattern I have used over and over because it keeps paying off.

A scraper does not need to be flashy. It needs to be understandable, restartable, and honest about the surface it is scraping.

That is what actually scales.

# The prompt

```markdown
I want you to generate a production-grade scraping script for the target site I give you.

Your job is not to produce a toy example. Produce a complete, durable, restartable scraper built with tried-and-true patterns used in real scraping workflows.

Target site:
[PASTE URL OR SITE DESCRIPTION HERE]

Goal:
[DESCRIBE EXACTLY WHAT DATA TO EXTRACT]

Environment:
- Language: [TypeScript / JavaScript / Python]
- Runtime: [Node / Bun / Deno / Python]
- Preferred libraries: [axios / fetch / cheerio / playwright / puppeteer / bs4 / lxml / etc.]
- Output format: [JSON / JSONL / CSV / DB insert / parquet]
- Run environment: [local / Docker / VM / serverless / CI]
- Authentication: [none / cookie / API key / session / unknown]
- Allowed scope: public pages only unless I explicitly say otherwise
- Do not include any credential theft, login bypass, CAPTCHA bypass, fingerprint spoofing, or access-control evasion

What I want you to do:

1. First, reason about the site architecture before writing code.
   - Identify the least fragile source of truth.
   - Prefer public JSON/XHR/API endpoints over HTML scraping where possible.
   - Separate breadth sources from depth sources:
     - breadth = listing pages, feeds, search results, sitemaps, product indexes, paginated directories
     - depth = detail pages, profile pages, attachments, per-record endpoints
   - If a hybrid approach is best, use it.

2. Decide what belongs in:
   - a seed/discovery layer
   - a scraping/extraction layer
   - a processing/normalization layer
   Keep those concerns separate unless the job is small enough that combining them is clearly better.

3. Generate a scraper that includes everything it may realistically need:
   - config section
   - CLI arguments or environment variables
   - input validation
   - URL normalization
   - polite rate limiting
   - bounded concurrency
   - randomized jitter
   - retries with exponential backoff
   - special handling for 429 and transient 5xx errors
   - timeout handling
   - pagination support
   - deduplication
   - stable progress logging
   - resumability/checkpointing
   - graceful shutdown on SIGINT/SIGTERM
   - incremental writes
   - structured output
   - raw source preservation where useful
   - normalization helpers
   - optional enrichment/inference helpers
   - clear error handling and skip/recover behavior

4. Design the output shape before the extraction logic.
   Include:
   - source metadata
   - canonical extracted fields
   - raw extracted fragments where useful
   - normalized fields
   - inferred/enriched fields separated from raw extracted fields
   - timestamps and provenance where appropriate

5. Be explicit about tool choice.
   - If plain HTTP + HTML parser is enough, use that.
   - Only use a headless browser if the site genuinely requires rendering, interaction, JS pagination, or authenticated flows I explicitly authorize.
   - Explain why that choice was made.

6. Write the code as if it will actually be run by a competent engineer.
   That means:
   - complete imports
   - real helper functions
   - no pseudocode unless unavoidable
   - comments only where they add value
   - minimal hidden assumptions
   - consistent naming
   - production-sane defaults

7. Include these implementation patterns:
   - discovery and extraction should be separable
   - selectors should anchor to stable labels, repeated blocks, tables, URLs, or semantic structure rather than fragile styling
   - normalization should happen during extraction, not as an afterthought
   - inferred fields should be kept separate from raw extracted fields
   - JSONL should be preferred for long-running jobs unless another format is clearly better
   - checkpoint files should allow restart after interruption
   - write incrementally instead of buffering everything in memory
   - logs should make failures diagnosable without opening the code

8. At the top of the answer, give me:
   - the recommended architecture for this specific site
   - why you chose it
   - whether this should be one script or split into seed + scrape + process scripts
   - the likely brittle points
   - what I should inspect in DevTools first

9. Then output:
   A. a concise explanation of the plan
   B. the full script
   C. any companion seed or normalization scripts if needed
   D. the expected directory structure
   E. install commands
   F. run commands
   G. example output
   H. notes on how to adapt the scraper if the site changes

10. Constraints:
   - Do not assume the site is stable
   - Do not assume the HTML is clean
   - Do not assume pagination is obvious
   - Do not overfit to one page if the site likely has multiple templates
   - Do not silently drop partial failures
   - Do not make the script “clever” at the expense of maintainability
   - Do not include anything that bypasses auth, CAPTCHAs, rate limits, or technical protections

11. Quality bar:
   I want the scraper to reflect the following philosophy:
   - prefer structured sources first
   - use hybrid breadth/depth scraping when appropriate
   - shape the data early
   - normalize on the way in
   - preserve provenance
   - make long runs restartable
   - be polite with concurrency and retries
   - use browsers only when the site earns it

If information about the site is missing, make the most reasonable assumptions, state them clearly, and still produce the best complete implementation you can.
```