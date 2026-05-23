const PAGE_SIZE = 20;

const feedRoot = document.getElementById("fwitter-feed");
const statusEl = document.getElementById("feed-status");
const sentinel = document.getElementById("feed-sentinel");

let isLoading = false;
let hasMore = true;
let nextBeforeId = null;
let hasLoadedAnyPosts = false;

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function linkify(text) {
    return text.replace(
        /(https?:\/\/[^\s<]+)/g,
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>'
    );
}

function formatRelativeTime(dateInput) {
    const date =
        dateInput instanceof Date
            ? dateInput
            : new Date(String(dateInput).replace(" ", "T"));

    if (Number.isNaN(date.getTime())) {
        return "";
    }

    const now = new Date();
    const diffMs = now - date;
    const diffSeconds = Math.max(0, Math.floor(diffMs / 1000));

    if (diffSeconds < 10) return "just now";
    if (diffSeconds < 60) return `${diffSeconds} seconds ago`;

    const diffMinutes = Math.floor(diffSeconds / 60);
    if (diffMinutes === 1) return "1 minute ago";
    if (diffMinutes < 60) return `${diffMinutes} minutes ago`;

    const diffHours = Math.floor(diffMinutes / 60);
    if (diffHours === 1) return "1 hour ago";
    if (diffHours < 24) return `${diffHours} hours ago`;

    const diffDays = Math.floor(diffHours / 24);
    if (diffDays === 1) return "1 day ago";
    if (diffDays < 30) return `${diffDays} days ago`;

    const diffMonths = Math.floor(diffDays / 30);
    if (diffMonths === 1) return "1 month ago";
    if (diffMonths < 12) return `${diffMonths} months ago`;

    const diffYears = Math.floor(diffDays / 365);
    if (diffYears === 1) return "1 year ago";
    return `${diffYears} years ago`;
}

function getApiBaseUrl() {
    const isLocal =
        window.location.hostname === "localhost" ||
        window.location.hostname === "127.0.0.1";

    return isLocal ? "https://flawnson.com" : "";
}

function getMicroPostsUrl() {
    const url = new URL(`${getApiBaseUrl()}/api/micro-posts.php`, window.location.origin);
    url.searchParams.set("limit", String(PAGE_SIZE));

    if (nextBeforeId) {
        url.searchParams.set("before_id", String(nextBeforeId));
    }

    return url.toString();
}

async function fetchJsonWithTimeout(url, timeoutMs) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

    try {
        const res = await fetch(url, {
            signal: controller.signal,
            cache: "no-store"
        });
        const data = await res.json();

        if (!res.ok) {
            throw new Error(data.error || `HTTP ${res.status}`);
        }

        return data;
    } finally {
        clearTimeout(timeoutId);
    }
}

const PLATFORM_LABELS = { x: "X", bluesky: "Bluesky", threads: "Threads", linkedin: "LinkedIn" };

function buildPostElement(post) {
    const article = document.createElement("article");
    article.className = "fwitter-post";

    const body = document.createElement("p");
    body.className = "fwitter-post-body";
    body.innerHTML = linkify(escapeHtml(post.body));

    const meta = document.createElement("small");
    meta.className = "fwitter-post-meta";

    const timeSpan = document.createElement("span");
    timeSpan.textContent = formatRelativeTime(post.created_at);
    meta.appendChild(timeSpan);

    if (Array.isArray(post.syndicated_platforms) && post.syndicated_platforms.length) {
        const platformsSpan = document.createElement("span");
        platformsSpan.className = "fwitter-post-platforms";
        platformsSpan.textContent = post.syndicated_platforms
            .map((p) => PLATFORM_LABELS[p] || p)
            .join(", ");
        meta.appendChild(platformsSpan);
    }

    article.appendChild(body);
    article.appendChild(meta);

    return article;
}

function setStatus(message) {
    if (!statusEl) return;
    statusEl.textContent = message;
}

async function loadNextPage() {
    if (!feedRoot || isLoading || !hasMore) return;

    isLoading = true;
    setStatus(hasLoadedAnyPosts ? "Loading more..." : "Loading...");

    try {
        const data = await fetchJsonWithTimeout(getMicroPostsUrl(), 7000);
        const posts = Array.isArray(data.posts) ? data.posts : [];

        if (!posts.length && !hasLoadedAnyPosts) {
            setStatus("No posts yet.");
            hasMore = false;
            return;
        }

        if (!posts.length) {
            hasMore = false;
            setStatus("You're all caught up.");
            return;
        }

        if (!hasLoadedAnyPosts && statusEl) {
            statusEl.remove();
        }

        for (const post of posts) {
            feedRoot.appendChild(buildPostElement(post));
        }

        hasLoadedAnyPosts = true;
        hasMore = Boolean(data.has_more);
        nextBeforeId = data.next_before_id || null;

        if (!hasMore) {
            setStatus("You're all caught up.");
            if (statusEl && !statusEl.parentNode) {
                feedRoot.appendChild(statusEl);
            }
        }
    } catch (err) {
        console.error(err);
        setStatus("Could not load posts.");
    } finally {
        isLoading = false;
    }
}

if (sentinel) {
    const observer = new IntersectionObserver(
        (entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                loadNextPage();
            }
        },
        {root: null, rootMargin: "800px 0px", threshold: 0}
    );

    observer.observe(sentinel);
}

loadNextPage();
