# Website

This repository contains the code for my website and blog as hosted on CPanel.

## Hosting
Domains are managed on ~~GoDaddy~~ NameCheap, both .com and .ca domains are registered on my Comend account (TODO: transfer to my personal NameCheap account).
Web hosting is managed by CPanel. You can access the CPanel admin via NameCheap's control panel [here](https://ap.www.namecheap.com/ProductList/HostingSubscriptions).
You'll need to log into CPanel, pull the most recent changes, and deploy HEAD after making changes for them to appear on the production site.

## Libraries and Packages

The main dependencies are Bootstrap 3 (I didn't use 4 because my previous website used 3), fontawesome (and it's cousin, Academicicons), and fonts (Raleway, Roboto, and Karla) supplied by Google's CDN. Everything else is coded from scratch. The home page has a link to my blog.

## Fwitter
I've had this problem for many years where I get ideas for tweets but decide not to share them.
Fwitter is my solution to this problem.
It's my own custom Twitter client that I use to share ideas and thoughts.
It comes with a small app that I built and installed on my phone that I can use to instantly post something to my website.
### Database
I use cPanel's MySQL database to store my posts.
Initialized with:
```SQL
CREATE TABLE micro_posts (
                             id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                             body TEXT NOT NULL,
                             syndicated_platforms VARCHAR(255) DEFAULT NULL,
                             created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                             is_published TINYINT(1) NOT NULL DEFAULT 1
);
```
Emoji support:
```SQL
ALTER TABLE micro_posts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
If upgrading an existing table (add `syndicated_platforms` column):
```SQL
ALTER TABLE micro_posts ADD COLUMN syndicated_platforms VARCHAR(255) DEFAULT NULL;
```
I manually write and upload a small php config script that holds my config info (keeps it off VCS)
### API
I use a custom php CRUD API to post to my website.
All endpoints are in the micro-posts.php file.

Optional social syndication is also handled from `micro-posts.php`. The database insert happens first; Gemini routing and external platform posting happen afterward, so Fwitter remains the source of truth even if Gemini, X, Bluesky, or Threads fail. The request waits for syndication to finish and returns a `syndication_result`, but the database insert is already committed before any downstream API call runs.

### Social platform configuration
All secrets live in `/home/flawhvna/private/microblog-config.php`, never in this repository. The social credentials must all be account-scoped credentials for my own personal accounts. If a social platform fails, the Fwitter database post should still be considered successful.

The full optional config shape is:
```php
$socialSyndicationEnabled = true;

// Gemini routing
$geminiApiKey = '...';
$geminiModel = 'gemini-2.5-flash';
$geminiTimeoutSeconds = 30;

// X, posting as my personal X account
$xApiKey = '...';
$xApiSecret = '...';
$xAccessToken = '...';
$xAccessTokenSecret = '...';

// Bluesky, posting as my personal Bluesky account
$blueskyHandle = 'flawnson.bsky.social';
$blueskyAppPassword = '...';
$blueskyService = 'https://bsky.social';

// Threads, posting as my personal Threads account
$threadsUserId = '...';
$threadsAccessToken = '...';

```

Start with `$socialSyndicationEnabled = false`, deploy, confirm normal Fwitter posting still works, then turn it on after the platform credentials are configured.

#### Gemini routing
Gemini decides which platform receives the post. The PHP code calls the Gemini REST API with an explicit API key and expects exactly one platform token from `x`, `bluesky`, `threads`. If Gemini returns anything else, syndication is skipped for that post.
For the default `gemini-2.5-flash` model, the router disables thinking with `thinkingBudget = 0` because this is a classification task. The Gemini request waits up to `$geminiTimeoutSeconds`, defaulting to 30 seconds. If Gemini is not configured, fails, or returns an unrecognised value, syndication is skipped for that post.

1. Go to Google AI Studio.
2. Create or open the project for this app.
3. Create a Gemini API key. The Google project name and project number are not needed by this PHP code.
4. Add the key:

```php
$geminiApiKey = 'AIza...';
$geminiModel = 'gemini-2.5-flash';
$geminiTimeoutSeconds = 30;
```

#### X
The X integration uses OAuth 1.0a user-context credentials and posts to `POST https://api.x.com/2/tweets`. Do not use the app-only bearer token for this implementation.

1. Open the X Developer Console for the app.
2. Make sure the app has `Read and write` permissions.
3. In `Keys & Tokens`, use the `OAuth 1.0 Keys` section.
4. Copy the `Consumer Key` into `$xApiKey`.
5. Copy the `Consumer Secret` / `Consumer Key Secret` into `$xApiSecret`.
6. Generate an `Access Token` for my personal account, such as `For @FlawnsonTong`.
7. Copy the generated `Access Token` into `$xAccessToken`.
8. Copy the generated `Access Token Secret` into `$xAccessTokenSecret`.

```php
$xApiKey = 'consumer_key_from_x';
$xApiSecret = 'consumer_secret_from_x';
$xAccessToken = 'access_token_for_my_personal_x_account';
$xAccessTokenSecret = 'access_token_secret_for_my_personal_x_account';
```

The access token must say it is for the account I want to post from. That is what locks posting to my personal X account.

#### Bluesky
The Bluesky integration logs in with my handle and an app password, then creates an `app.bsky.feed.post` record through the AT Protocol API.

1. In Bluesky, open `Settings -> App Passwords`.
2. Create an app password named something like `Fwitter`.
3. Use my Bluesky handle without the leading `@`.
4. Keep the service as `https://bsky.social` unless the account uses a different personal data server.

```php
$blueskyHandle = 'flawnson.bsky.social';
$blueskyAppPassword = 'xxxx-xxxx-xxxx-xxxx';
$blueskyService = 'https://bsky.social';
```

If the visible Bluesky profile handle is `@flawnson.bsky.social`, the config value is `flawnson.bsky.social`. Only use `flawnson.com` if the Bluesky app itself shows the profile as `@flawnson.com`.

#### Threads
The Threads integration needs a numeric Threads user ID and a long-lived Threads user access token. The app ID and app secret are used during setup to generate the token, but the runtime PHP config only needs:

```php
$threadsUserId = '123456789';
$threadsAccessToken = 'long_lived_threads_access_token';
```

Threads setup is strict about testers and redirect URLs:

1. In Meta Developer Console, open the app.
2. Go to `Use cases -> Access the Threads API -> Customize`.
3. Confirm the `Threads app ID` and reveal the `Threads app secret`; keep both private during setup.
4. Add this exact URL to `Redirect Callback URLs` / `Valid OAuth Redirect URIs`:

```text
https://flawnson.com/
```

5. Add temporary callback URLs if Meta requires them:

```text
Uninstall Callback URL: https://flawnson.com/
Delete Callback URL: https://flawnson.com/
```

6. Make sure the Threads account is public.
7. Add the Threads account as a `Threads Tester`.
8. Accept the pending tester invite from the Threads account. In Threads, this is under profile settings, usually `Website permissions`.
9. Request only these scopes:

```text
threads_basic
threads_content_publish
```

Do not request `threads_delete` for this integration.

If the `User Token Generator` appears and lists the Threads account, use it to generate a long-lived token directly. Then get the user ID:

```bash
curl "https://graph.threads.net/v1.0/me?fields=id,username&access_token=LONG_LIVED_THREADS_ACCESS_TOKEN"
```

If using the manual OAuth flow, open this URL with the real Threads app ID:

```text
https://threads.net/oauth/authorize?client_id=THREADS_APP_ID&redirect_uri=https%3A%2F%2Fflawnson.com%2F&scope=threads_basic,threads_content_publish&response_type=code
```

After approval, the browser redirects to a URL like:

```text
https://flawnson.com/?code=AQ...
```

Copy only the `code` value. Do not include `code=` or the trailing `#_` fragment. Exchange it immediately for a short-lived token:

```bash
curl -X POST "https://graph.threads.net/oauth/access_token" \
  --data-urlencode "client_id=THREADS_APP_ID" \
  --data-urlencode "client_secret=THREADS_APP_SECRET" \
  --data-urlencode "grant_type=authorization_code" \
  --data-urlencode "redirect_uri=https://flawnson.com/" \
  --data-urlencode "code=FRESH_CODE_WITHOUT_HASH_FRAGMENT"
```

The response contains:

```json
{
  "access_token": "SHORT_LIVED_ACCESS_TOKEN",
  "user_id": "123456789"
}
```

Save the `user_id`, then exchange the short-lived token for a long-lived token:

```bash
curl -G "https://graph.threads.net/access_token" \
  --data-urlencode "grant_type=th_exchange_token" \
  --data-urlencode "client_secret=THREADS_APP_SECRET" \
  --data-urlencode "access_token=SHORT_LIVED_ACCESS_TOKEN"
```

The response contains the long-lived token:

```json
{
  "access_token": "LONG_LIVED_THREADS_ACCESS_TOKEN",
  "token_type": "bearer",
  "expires_in": 5184000
}
```

Put the numeric `user_id` from the first response and the long-lived token from the second response into the private PHP config:

```php
$threadsUserId = '123456789';
$threadsAccessToken = 'LONG_LIVED_THREADS_ACCESS_TOKEN';
```

Verify the final token:

```bash
curl "https://graph.threads.net/v1.0/me?fields=id,username&access_token=LONG_LIVED_THREADS_ACCESS_TOKEN"
```

Test a direct text post before testing through Fwitter:

```bash
curl -X POST "https://graph.threads.net/v1.0/THREADS_USER_ID/threads" \
  -d "media_type=TEXT" \
  -d "text=Testing Fwitter Threads API setup." \
  -d "access_token=LONG_LIVED_THREADS_ACCESS_TOKEN"
```

That returns a creation ID. Publish it:

```bash
curl -X POST "https://graph.threads.net/v1.0/THREADS_USER_ID/threads_publish" \
  -d "creation_id=CREATION_ID" \
  -d "access_token=LONG_LIVED_THREADS_ACCESS_TOKEN"
```

Threads long-lived tokens expire. Refresh the token before it expires and replace `$threadsAccessToken`:

```bash
curl -G "https://graph.threads.net/refresh_access_token" \
  --data-urlencode "grant_type=th_refresh_token" \
  --data-urlencode "access_token=LONG_LIVED_THREADS_ACCESS_TOKEN"
```

#### Syndication diagnostics
There is a protected diagnostics mode for testing the social path without creating a Fwitter database post. It requires the same `X-Admin-Token` as normal posting.

Check config presence and Gemini routing without publishing:

```bash
curl -X POST "https://flawnson.com/api/micro-posts.php?syndication_debug=1" \
  -H "Content-Type: application/json" \
  -H "X-Admin-Token: ADMIN_TOKEN" \
  -d '{"body":"Rare disease software has to optimize for trust before growth."}'
```

Force-test one platform without Gemini and actually publish a test post:

```bash
curl -X POST "https://flawnson.com/api/micro-posts.php?syndication_debug=1" \
  -H "Content-Type: application/json" \
  -H "X-Admin-Token: ADMIN_TOKEN" \
  -d '{"body":"Testing Fwitter syndication diagnostics.","platform":"bluesky","publish":true}'
```

Valid `platform` values are `x`, `bluesky`, `threads`. Leave `publish` as `false` or omit it when only checking config and routing.

#### References
- Gemini API keys: https://ai.google.dev/gemini-api/docs/api-key
- X OAuth 1.0a: https://docs.x.com/fundamentals/authentication/oauth-1-0a/overview
- X create post endpoint: https://docs.x.com/x-api/posts/creation-of-a-post
- Bluesky creating a post: https://docs.bsky.app/docs/tutorials/creating-a-post
- Threads long-lived tokens: https://developers.facebook.com/docs/threads/get-started/long-lived-tokens/
- Threads API Postman collection: https://www.postman.com/meta/threads


### App
I wrote a small iOS app with SwiftUI to post to my website from anywhere.
You can find it in the [Fwitter](https://github.com/flawnson/fwitter) repo on my GitHub.

# Health
A small integration that pulls my own health/fitness data (from my Fitbit) via the
**Google Health API** — the cloud REST successor to the Fitbit Web API, using Google OAuth 2.0.
The legacy Fitbit Web API turns down in September 2026 and the old Google Fit REST API at the
end of 2026, so this builds against the current Google Health API (`https://health.googleapis.com/v4`).

The metrics show on the homepage in the `#health-panel` section. The backend is intentionally
generic so any data type can be fetched, not just the default bundle.

## Endpoints (`api/`)
- `health-common.php` — shared include (config, CORS, curl wrappers, token store, cache, normalization). Not a public endpoint.
- `health-auth.php` — one-time OAuth flow. `?action=authorize&token=ADMIN_TOKEN` (admin-gated) → Google consent; `?action=callback` is the registered redirect URI that stores the refresh token.
- `health-metrics.php` — public read endpoint (uses the `:dailyRollUp` method for daily aggregates):
  - `?dataType=steps&days=7` (or `&start=YYYY-MM-DD&end=YYYY-MM-DD`) — any single data type. Valid ids: `steps`, `distance`, `active-energy-burned`, `active-zone-minutes`, `heart-rate`, `daily-resting-heart-rate`, `sleep`, etc.
  - no params → normalized bundle across a curated default set for the last 7 days (`?days=N`).
  - `?resource=identity|profile|pairedDevices` — account/device metadata.
  - Returns `{ ok, metrics:[{dataType,metric,start,end,value,unit,source,raw}], meta }`, or HTTP 409 `needs_reauth` when the token expired/was revoked.

The refresh + cached access token live in `/home/flawhvna/private/google-health-token.json`
(written by the callback; the private dir must be writable by PHP). Responses are cached for
5 minutes under `/home/flawhvna/private/cache/` so public traffic doesn't hit rate limits.

## Config
Add to `/home/flawhvna/private/microblog-config.php` (never in VCS):
```php
$googleHealthClientId     = '...';   // Google Cloud OAuth 2.0 client (Web application type)
$googleHealthClientSecret = '...';
$googleHealthRedirectUri  = 'https://flawnson.com/api/health-auth.php?action=callback';
// Space-delimited read-only scopes. All Google Health scopes are "Restricted" — add the same
// strings on the OAuth consent screen via "Manually add scopes". Drop ecg/irn/location if you
// don't want heart-rhythm/GPS data exposed on the public panel.
$googleHealthScopes       = 'https://www.googleapis.com/auth/googlehealth.activity_and_fitness.readonly https://www.googleapis.com/auth/googlehealth.health_metrics_and_measurements.readonly https://www.googleapis.com/auth/googlehealth.sleep.readonly https://www.googleapis.com/auth/googlehealth.nutrition.readonly https://www.googleapis.com/auth/googlehealth.location.readonly https://www.googleapis.com/auth/googlehealth.ecg.readonly https://www.googleapis.com/auth/googlehealth.irn.readonly https://www.googleapis.com/auth/googlehealth.profile.readonly https://www.googleapis.com/auth/googlehealth.settings.readonly';
$googleHealthUserId       = 'me';
// reuses the existing $adminToken to gate the authorize step
```

## Setup
1. Create a Google Cloud project at https://console.cloud.google.com (signed in as the account my Fitbit is on).
2. Enable the **Health API** (`health.googleapis.com`). If an access-request/allowlist form appears, submit it.
3. OAuth consent screen → User type **External**, add myself as a **Test user**, add the health read scopes. Keep publishing status **Testing** for personal use (refresh token then expires ~every 7 days; re-run authorize when the panel reports `needs_reauth`).
4. Credentials → **Create OAuth client ID** → type **Web application**. Authorized redirect URI: `https://flawnson.com/api/health-auth.php?action=callback`. Copy the client ID/secret into the config.
5. Make sure the Fitbit is linked to the same Google account and has synced recently (no synced data → empty responses, not an error).
6. After deploy, visit `https://flawnson.com/api/health-auth.php?action=authorize&token=ADMIN_TOKEN` once and approve consent. The callback stores the refresh token.

### References
- Google Health API: https://developers.google.com/health
- Google Health API REST reference: https://developers.google.com/health/reference/rest
- Google OAuth 2.0 (web server): https://developers.google.com/identity/protocols/oauth2/web-server

# Blog
This is a simple python-rendered markdown blog.

When I create or edit a post, I run the following command to build the blog:
```bash
python scripts/build-blog.py
```
Then deploy my site like normal.

To see changes to website update live:
```bash
python -m http.server 8000
```

## Images
How to use images in posts:
```markdown
![My screenshot](demo.png)
```
demo.png auto-resolves to /assets/images/blog/demo.png.
```markdown
![Architecture diagram](/assets/images/blog/diagram.webp)
![Animated flow](https://example.com/flow.gif)
```
Resize/align options (in alt text, after |):
```markdown
![Demo image|sm](demo.gif)
![Wide chart|full](chart.png)
![Logo|w=240](logo.svg)
![Hero|w=75%|max=900|right](hero.jpg)
![Thumb|xs|left](thumb.webp)
```

# Analytics
I use onedollarstats to track page views.

# Ideas
- [ ] A guestbook for site visitors to leave a note or sticker
- [x] A searchable feed of Fwitter
- [x] Table of contents for blog posts
