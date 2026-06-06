$(document).ready(function() {
//Preloader
    setTimeout(
        function()
        {
            preloaderFadeOutTime = 500;
            function hidePreloader() {
                var preloader = $('#preloader');
                preloader.fadeOut(preloaderFadeOutTime);
            }
            hidePreloader();
        }, 1000);
});

// To add dark mode toggle to website
function darkMode() {
    var element = document.body;
    var icons = $(".icon");
    element.classList.toggle("dark-mode");
    icons.toggleClass("dark-mode");
};

// To add dark mode toggle to website
function currentYear() {
    return new Date().getFullYear()
};

// To add last edited time to footer of website
window.addEventListener("load",function setCurrentYear() {
    var copyright = "© " + currentYear() + " Copyright:"
    document.getElementById("copyright").textContent = copyright
},false);

// To add last edited time to footer of website
window.addEventListener("load",function lastMod() {
    var lastMod = "Last Edited: " + document.lastModified;
    document.getElementById("modTime").innerHTML = lastMod;
},false);

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

function buildPlatformUrl(post) {
    const ids = post.platform_post_ids;
    const platforms = post.syndicated_platforms;

    if (!ids || !Array.isArray(platforms) || !platforms.length) return null;

    const platform = platforms[0];

    if (platform === "x" && ids.x?.post_id) {
        return `https://x.com/i/web/status/${ids.x.post_id}`;
    }

    if (platform === "bluesky" && ids.bluesky?.uri) {
        const withoutScheme = ids.bluesky.uri.replace("at://", "");
        const parts = withoutScheme.split("/");
        const did = parts[0];
        const rkey = parts[parts.length - 1];
        if (did && rkey) return `https://bsky.app/profile/${did}/post/${rkey}`;
    }

    if (platform === "threads" && ids.threads?.url) {
        return ids.threads.url;
    }

    return null;
}

function renderMicroPosts(posts) {
    const root = document.getElementById("micro-posts");
    if (!root) return;

    if (!posts.length) {
        root.innerHTML = "<p>No posts yet.</p>";
        return;
    }

    const platformLabels = { x: "X", bluesky: "Bluesky", threads: "Threads" };

    root.innerHTML = posts.map((post) => {
        const safeBody = linkify(escapeHtml(post.body));
        const createdAt = new Date(post.created_at.replace(" ", "T"));
        const relativeTime = formatRelativeTime(createdAt);
        const photoLabel = post.has_image
            ? `<span style="opacity:0.68;font-size:inherit;">Photo</span>`
            : "";
        const platforms = Array.isArray(post.syndicated_platforms) && post.syndicated_platforms.length
            ? `<span style="opacity:0.68;font-size:inherit;">${post.syndicated_platforms.map((p) => platformLabels[p] || p).join(", ")}</span>`
            : "";
        const url = buildPlatformUrl(post);
        const cursorStyle = url ? "cursor:pointer;" : "";
        const dataAttr = url ? ` data-platform-url="${escapeHtml(url)}"` : "";

        return `
            <article class="micro-post" style="margin-bottom: 1.25rem; padding-bottom: 1rem; border-bottom: 1px solid #ddd; ${cursorStyle}"${dataAttr}>
                <p style="white-space: pre-wrap; margin-bottom: 0.4rem;">${safeBody}</p>
                <small style="display:flex;justify-content:space-between;align-items:center;"><span>${relativeTime}</span><span style="display:flex;gap:6px;">${photoLabel}${platforms}</span></small>
            </article>
        `;
    }).join("");
}

function getApiBaseUrl() {
    const isLocal =
        window.location.hostname === "localhost" ||
        window.location.hostname === "127.0.0.1";

    return isLocal ? "https://flawnson.com" : "";
}

function getMicroPostsUrl() {
    return `${getApiBaseUrl()}/api/micro-posts.php?limit=5`;
}

function getLastCommitUrl() {
    return `${getApiBaseUrl()}/api/github-last-commit.php`;
}

function getHealthMetricsUrl() {
    return `${getApiBaseUrl()}/api/health-metrics.php`;
}

function formatHealthLabel(dataType) {
    return String(dataType)
        .replace(/[_\-.]+/g, " ")
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

// Minimal renderer: groups normalized points by dataType and shows the latest
// value per type. Intentionally simple — the backend is the flexible part.
function renderHealthPanel(metrics, meta) {
    const root = document.getElementById("health-panel");
    if (!root) return;

    if (!Array.isArray(metrics) || !metrics.length) {
        root.innerHTML = "<p>No health data synced yet.</p>";
        return;
    }

    const latestByType = new Map();
    for (const m of metrics) {
        if (!m || m.value == null) continue;
        const key = m.dataType;
        const ts = new Date(m.end || m.start || 0).getTime();
        const prev = latestByType.get(key);
        if (!prev || ts >= prev.ts) {
            latestByType.set(key, { ...m, ts });
        }
    }

    if (!latestByType.size) {
        root.innerHTML = "<p>No health data synced yet.</p>";
        return;
    }

    const rows = [...latestByType.values()].map((m) => {
        const label = escapeHtml(formatHealthLabel(m.dataType));
        const value = escapeHtml(String(m.value));
        const unit = m.unit ? ` ${escapeHtml(String(m.unit))}` : "";
        const when = m.end || m.start ? formatRelativeTime(m.end || m.start) : "";
        return `
            <li style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;padding:0.35rem 0;border-bottom:1px solid #eee;">
                <span>${label}</span>
                <span><strong>${value}</strong>${unit}${when ? ` <small style="opacity:0.6;">${escapeHtml(when)}</small>` : ""}</span>
            </li>`;
    }).join("");

    let footnote = "";
    if (meta && meta.as_of) {
        const asOf = formatRelativeTime(meta.as_of);
        const stale = meta.stale
            ? " · reconnecting…"
            : "";
        if (asOf) {
            footnote = `<small style="opacity:0.6;display:block;margin-top:0.4rem;">as of ${escapeHtml(asOf)}${stale}</small>`;
        }
    }

    root.innerHTML = `<ul style="list-style:none;padding-left:0;margin:0;">${rows}</ul>${footnote}`;
}

async function loadHealthMetrics() {
    const root = document.getElementById("health-panel");
    if (!root) return;

    try {
        const data = await fetchJsonWithTimeout(getHealthMetricsUrl(), 6000);
        renderHealthPanel(data.metrics || [], data.meta || {});
    } catch (err) {
        console.error(err);
        root.innerHTML = "<p>Could not load health data right now.</p>";
    }
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

let lastCommitInterval = null;

const microPostsRequest = fetchJsonWithTimeout(getMicroPostsUrl(), 5000);
const lastCommitRequest = fetchJsonWithTimeout(getLastCommitUrl(), 5000);

async function loadMicroPosts() {
    const root = document.getElementById("micro-posts");
    if (!root) return;

    try {
        const data = await microPostsRequest;
        renderMicroPosts(data.posts || []);
    } catch (err) {
        console.error(err);
        root.innerHTML = "<p>Could not load posts.</p>";
    }
}

async function refreshMicroPosts() {
    const root = document.getElementById("micro-posts");
    if (!root) return;

    try {
        const data = await fetchJsonWithTimeout(getMicroPostsUrl(), 5000);
        renderMicroPosts(data.posts || []);
    } catch (err) {
        console.error(err);
        root.innerHTML = "<p>Could not load posts.</p>";
    }
}

async function loadLastCommitTimer() {
    const timerEl = document.getElementById("last-commit-timer");

    if (!timerEl) return;

    timerEl.textContent = "Loading...";

    try {
        const data = await lastCommitRequest;
        const lastCommitDate = new Date(data.created_at);

        if (Number.isNaN(lastCommitDate.getTime())) {
            throw new Error("Invalid commit timestamp received.");
        }

        function render() {
            const now = new Date();
            const diffMs = now - lastCommitDate;

            const totalSeconds = Math.max(0, Math.floor(diffMs / 1000));
            const days = Math.floor(totalSeconds / 86400);
            const hours = Math.floor((totalSeconds % 86400) / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const seconds = totalSeconds % 60;

            const parts = [];
            if (days > 0) parts.push(`${days}d`);
            parts.push(`${hours}h`);
            parts.push(`${minutes}m`);
            parts.push(`${seconds}s`);

            timerEl.textContent = parts.join(" ");
        }

        render();

        if (lastCommitInterval) {
            clearInterval(lastCommitInterval);
        }

        lastCommitInterval = setInterval(render, 1000);
    } catch (error) {
        timerEl.textContent = "Could not load";
        console.error(error);
    }
}

loadMicroPosts();
loadLastCommitTimer();
loadHealthMetrics();
setInterval(refreshMicroPosts, 15000);
setInterval(loadHealthMetrics, 600000); // refresh health every 10 minutes

const microPostsRoot = document.getElementById("micro-posts");
if (microPostsRoot) {
    microPostsRoot.addEventListener("click", (e) => {
        if (e.target.closest("a")) return;
        const article = e.target.closest("[data-platform-url]");
        if (!article) return;
        const url = article.dataset.platformUrl;
        if (url) window.open(url, "_blank", "noopener,noreferrer");
    });
}