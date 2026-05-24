---
title: Building Fwitter: a personal microblog with AI-powered social syndication
slug: building-fwitter
date: 2026-05-11
excerpt: How I built a microblog on my own domain that automatically routes posts to X, Bluesky, and Threads using Gemini AI.
tags: [engineering, php, ai]
published: true
---

For the longest time I've had this mental block that prevented me from posting online.

Every time I have a Twitter-thought, the activation energy I need to actually open the app, write the post, and publish it feels abnormally high. I'm not on social media often; I don't doomscroll or even upvote/like/<insert your mechanic here> posts, and I almost never leave comments or replies.

Then there is the fact that these platforms have a clear bias toward certain sentiments, styles, and opinions. How do I, as a social-media minimalist, navigate these treacherous waters and build my social capital?

And of course, there's always the risk of opening the app and getting sucked into reading posts and being a "consumer" instead of a "producer".

So to fix all my problems, I built [Fwitter](/fwitter/): a personal microblog that lives on my domain, stores everything in my own database, and syndicates to social platforms automatically. The interesting part is the routing: instead of cross-posting the same thing everywhere, I use Gemini (because they have a generous free tier) to decide which platform each fweet is best suited for, then route the content to that platform using its API.

Here's how it's built.

{{toc}}

# The design philosophy

When I started building Fwitter, I hoped it would reduce the friction of posting my thoughts online more. To that end, it has succeeded beyond my expectations, and I think it is safe to say I have developed a habit of opening and posting every day. This meant I needed my own database, which was easy to set up on MySQL, cPanel's default SQL DB. Even now, it is the source of truth for my posts, and routing only happens after the post has successfully been written to the database.

In Fwitter, a post exists the moment it is written to my database. Everything after that - Gemini routing, publishing to X, publishing to Bluesky, publishing to Threads - is bonus. Those operations are fire-and-forget. If they fail, the post still exists. If they succeed, great. Social platforms are distribution, not storage.

This also means the system degrades gracefully. If Gemini is down, the post still gets saved and syndication is skipped. If the X API is flaky, the post still exists on Fwitter. The only failure mode that matters is the database write.

# The database

The schema is deliberately minimal:

```sql
CREATE TABLE micro_posts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_published TINYINT(1) NOT NULL DEFAULT 1
);
```

Syndication results are logged to `error_log` for debugging, but they are not persisted in the schema. This might change if I add structured retry tracking, but the simplicity of not caring about syndication at the schema level has been worth it. I have never needed to query "which posts failed to publish to Bluesky" - and if I did, I would add the column then.

# The API

The backend is a single PHP file. I'm hosting on cPanel, which is Just PHP with PDO and curl.

Authentication is an admin token passed as an `X-Admin-Token` header, compared with `hash_equals()` to prevent timing attacks. The API supports `GET` for reading the feed and `POST` for creating posts. `GET` is public; `POST` requires the token.

CORS is handled with an explicit allowlist: `flawnson.com` and localhost variants for development. The `Vary: Origin` header is set so caches do not serve one origin's response to another.

Pagination uses a `before_id` cursor rather than offset-based pages. Offset pagination is simpler to implement but gets unstable under concurrent writes: if a new post arrives between page 1 and page 2, offset-based pagination either skips an item or shows it twice. ID cursors do not have this problem. The query fetches `limit + 1` rows - if I get back more than `limit`, there is a next page, and I return the last ID as the cursor.

# Routing with Gemini

Deciding which platform to post to is the fun part.

X, Bluesky, and Threads all have different personalities:
- X rewards sharp, opinion-dense takes - startup energy, systems thinking, technical insight in as few words as possible.
- Bluesky has a softer, more reflective community: creative work, books, thoughtful observations, writing that is a little more comfortable being uncertain.
- Threads is casual and warm - fitness updates, internet culture, relatable moments, the kind of thing I'd say to a friend.

Posting the same thing verbatim to all three is suboptimal and would just be redundant. Having different posts on each platform gives readers incentive to check out my profile on other sites. And of course, if you just want to see everything I say, you can always just go to my website for the [full feed](https://flawnson.com/fwitter/).

But having to think about which platform to post on for every post introduces enough friction that I stop posting. So I gave the routing decision to Gemini.

The model gets the post's text and a short description of each platform's character, and it outputs a single platform token:

```text
You are a routing model. Your ONLY job is to decide which social platform(s)
a text-only post should be published to.

Output one platform token. No punctuation. No explanation. No quotes. No reasoning.

Valid tokens: x, bluesky, threads

[platform definitions and tie-breaker rules follow...]
```

The model is `gemini-2.5-flash` with temperature 0 (deterministic), thinking budget 0 (fast and cheap on 2.5 models), and max output tokens of 24. The response is validated against the three allowed values; invalid tokens are treated as a routing failure and syndication is skipped.

This is one of the easiest AI integrations I've built because it's not trying to generate anything creative - it's just making a classification decision that I'd otherwise have to make manually. The cost per call is a fraction of a cent. The latency is under a second. And it gets the routing right often enough that I rarely notice when it does not. Occasionally I'll see a post get routed somewhere I didn't expect, but it's never blatantly wrong. At some point I'll likely make some prompt adjustments to have the model optimize for and adapt to the current culture of each platform to improve the performance of the routing.

<aside class="blog-aside">

One thing I noticed: giving the model tie-breaker rules helped a lot. Serious or professional -> X. Thoughtful or artistic -> Bluesky. Casual or social -> Threads. Without tie-breakers, borderline posts tended to cluster on X because it is listed first.

</aside>

# Social platform integrations

Each platform has a completely different API design and auth system. With long-lived tokens, I shouldn't have to do this very often.

## X: OAuth 1.0a

<aside class="blog-aside">

X is the only platform that required I purchase credits to use their API. At the time of writing it costs $0.015 per create request (and $0.20 to create with a URL in the content).

</aside>

X uses OAuth 1.0a with four credentials: consumer key, consumer secret, access token, and access token secret. This is the older standard. Every request is signed with HMAC-SHA1: I collect your parameters, sort them, percent-encode them, build a signature base string from the method + URL + parameters, and compute the signature using a key derived from your consumer and token secrets. It is verbose to implement but the logic is deterministic and easy to test.

The endpoint is straightforward:

```text
POST https://api.x.com/2/tweets
Content-Type: application/json
Authorization: OAuth oauth_consumer_key="...", oauth_nonce="...", ...

{"text": "your post here"}
```

The main benefit of OAuth 1.0a over a simple bearer token is explicitness: these credentials are tied to exactly one account and one application. There is no ambiguity about which account a post comes from, and the signing overhead is the cost of that guarantee.

## Bluesky: AT Protocol

Bluesky uses the AT Protocol, which is newer and considerably cleaner to work with. Auth happens per request: I post my handle and app password to `/xrpc/com.atproto.server.createSession`, which returns an access JWT and a DID (decentralized identifier). I then use the JWT to create a record in the right collection.

```json
POST https://bsky.social/xrpc/com.atproto.repo.createRecord
Authorization: Bearer {accessJwt}

{
  "repo": "{did}",
  "collection": "app.bsky.feed.post",
  "record": {
    "$type": "app.bsky.feed.post",
    "text": "your post here",
    "createdAt": "2026-05-11T12:00:00Z"
  }
}
```

Creating a new session on every post means there is no persistent token to store or refresh. App passwords are long-lived; the session they generate is ephemeral. This makes the integration stateless: if the server restarts between posts, nothing breaks. The `$type` field and the ISO `createdAt` are required by the protocol; omitting either produces a validation error.

## Threads: the two-step dance

<aside class="blog-aside">

Threads was by far the most annoying to set up. There were multiple steps and it was generally not very well-documented. My biggest problem was the lack of clarity around what was under Meta's jurisdiction vs Instagram and Threads. My struggles here partly inspired me to write this blog in the first place.

</aside>

Threads uses Meta's Graph API and requires two requests per post. First I create a container:

```text
POST /{userId}/threads
media_type=TEXT&text=your+post+here&access_token={token}
```

That returns a creation ID. Then I publish it:

```text
POST /{userId}/threads_publish
creation_id={creationId}&access_token={token}
```

The two-step design apparently exists to support future batch operations or pre-scheduling, but for plain text posts it is just extra latency. The more annoying thing is the access token: it is long-lived but expires after roughly 60 days, and I have to refresh it proactively. Of the three integrations, Threads is the one I have to think about the most because of that expiry. The refresh endpoint is simple - a `GET` request with my current token - but it is a maintenance task the others do not require.

# Non-blocking syndication

Here's where the shared-hosting constraint got interesting.

The full syndication chain - Gemini routing plus a social media API call - can take a few seconds. If I block the HTTP response on all of that, posting feels slow even though the actual write succeeded before any of it started. The fix is to respond to the client as soon as the database write is done, then syndicate afterward.

In PHP-FPM, which is what cPanel runs, there is a function called `fastcgi_finish_request()` that flushes the HTTP response to the client and closes the connection while letting the PHP process continue executing. Combined with `ignore_user_abort(true)` at the top of the file, this gives me a clean "respond now, finish work later" pattern:

```php
$postId = (int)$pdo->lastInsertId();

http_response_code(201);
header('Content-Type: application/json');
$responseJson = json_encode(["ok" => true, "id" => $postId, "syndication" => "pending"]);
header('Content-Length: ' . strlen($responseJson));
echo $responseJson;

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request(); // client gets their 201 here
} else {
    ob_flush();
    flush();
}

run_syndication_safely($body, $postId); // runs after the client is gone
exit;
```

The `Content-Length` header is important: without it, some proxies and clients will buffer the response and wait for the connection to close before delivering it, defeating the whole point. Setting it explicitly tells the client exactly when the response body ends, so it can process the response without waiting for the underlying connection to drop.

The client sees an instant 201. Gemini and the social platforms run in whatever time they take. From the outside it feels like a fast write; the syndication is genuinely invisible.

# The frontend

The frontend is a small SwiftUI app I vibe coded. It's far from a feature rich editor; it's a low-friction (and low-effort) posting surface.

The main screen is a composer plus a feed. The composer is a `TextEditor` with a character count and a Post button. The feed is a plain SwiftUI `List` of recent posts with timestamps, copy support, and a few admin actions. Since this is my private app, it sends the same `X-Admin-Token` header the PHP API expects, which lets me create, edit, and delete posts from my phone.

The most useful feature is offline support. The app stores the current draft, cached posts, and pending creates in `UserDefaults`. If I post without a connection, the app gives the post a temporary local ID, shows it immediately as `Pending`, and queues it for later. An `NWPathMonitor` watches for the network to come back, then syncs pending posts before refreshing the feed.

The MySQL database is still the source of truth. The phone can temporarily pretend a post exists, but once the API accepts it, the normal flow takes over: database write first, then Gemini routing, then syndication.

The Swift app is my private write surface while the website is my public archive.

## App updates

**April 20th, 2026** — Added draft and scheduled posts. The composer now saves the current draft to `UserDefaults` automatically, so closing the app mid-thought never loses work. Scheduled posts let me queue something to go out at a specific time — useful when I want to post during peak hours without having to remember to open the app. This is all done on the app; no backend related changes were needed.

**May 8th, 2026** — Added automated @ mention detection. Before syndicating, Gemini runs a second pass over the post body using Google Search to find official handles for any named people or companies mentioned. It only substitutes when it has high confidence the handle is the correct official account — not a parody or fan page. Each platform gets the right handle format: `@username` on X and Threads, `@handle.bsky.social` on Bluesky.

# Reflections

A few things stand out after building and running this for a while.

The AI routing decision is the most interesting thing in the whole stack. Knowing that the content itself will determine where a post lands makes me think differently about each one.

The shared-hosting constraint was probably the most clarifying technical decision, even though it was not really a decision - it is just where the site lives. Not having access to a queue or background workers forced me to think carefully about what actually needs to block on what. A queue would have been more correct in a distributed sense; this is more correct for my simple use case.

The thing I'd change: I'd add a `syndication_status` column to the database and log the platform result back after each syndication run. Right now, whether a post made it to X or Bluesky is observable only through `error_log`, which is not a great debugging experience. Tracking status in the schema would make it easy to build a small retry UI and would give me better visibility into which platform is flaky and when.
