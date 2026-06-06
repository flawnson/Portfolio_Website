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

function pad2(n) {
    return String(n).padStart(2, "0");
}

// Local YYYY-MM-DD key, matching the server's civil (local) dates.
function localDateKey(d) {
    return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
}

// Minutes -> "h:mm" (e.g. 469 -> "7:49").
function formatHoursMinutes(minutes) {
    const total = Math.max(0, Math.round(Number(minutes) || 0));
    return `${Math.floor(total / 60)}:${pad2(total % 60)}`;
}

// Renders the past 7 days (excluding today) as worked-out (step goal hit OR a
// workout logged) vs rest circles, plus the most recent night's time asleep.
function renderHealthPanel(metrics, meta) {
    const root = document.getElementById("health-panel");
    if (!root) return;

    metrics = Array.isArray(metrics) ? metrics : [];
    const stepGoalRaw = meta && Number(meta.step_goal);
    const stepGoal = Number.isFinite(stepGoalRaw) && stepGoalRaw > 0 ? stepGoalRaw : 10000;

    const stepsByDate = {};
    const exerciseDates = new Set();
    let sleepMinutes = null;
    let sleepEndTs = -1;

    for (const m of metrics) {
        if (!m) continue;
        if (m.dataType === "steps" && m.date) {
            const v = Number(m.value);
            if (Number.isFinite(v)) stepsByDate[m.date] = v;
        } else if (m.dataType === "exercise" && m.date) {
            exerciseDates.add(m.date);
        } else if (m.dataType === "sleep") {
            const v = Number(m.value);
            const ts = new Date(m.end || m.start || 0).getTime();
            if (Number.isFinite(v) && v > 0 && Number.isFinite(ts) && ts >= sleepEndTs) {
                sleepEndTs = ts;
                sleepMinutes = v;
            }
        }
    }

    // Past 7 days, oldest -> yesterday (today excluded).
    const today = new Date();
    const circles = [];
    for (let i = 7; i >= 1; i--) {
        const d = new Date(today);
        d.setDate(today.getDate() - i);
        const key = localDateKey(d);
        const workedOut =
            (stepsByDate[key] != null && stepsByDate[key] >= stepGoal) ||
            exerciseDates.has(key);
        const iconClass = workedOut
            ? "fas fa-check-circle health-day-on"
            : "far fa-circle health-day-off";
        circles.push(
            `<i class="${iconClass}" title="${key}: ${workedOut ? "worked out" : "rest"}" aria-label="${key}: ${workedOut ? "worked out" : "rest"}"></i>`
        );
    }

    const sleepHtml = Number.isFinite(sleepMinutes)
        ? `<div class="health-sleep"><i class="fas fa-bed" aria-hidden="true"></i> <strong>${formatHoursMinutes(sleepMinutes)}</strong> <span class="health-sleep-label">slept</span></div>`
        : `<div class="health-sleep"><i class="fas fa-bed" aria-hidden="true"></i> <span class="health-sleep-label">no recent sleep data</span></div>`;

    root.innerHTML = `
        <div class="health-week" role="group" aria-label="Workouts over the past 7 days (excluding today)">${circles.join("")}</div>
        ${sleepHtml}`;
}

async function loadHealthMetrics() {
    const root = document.getElementById("health-panel");
    if (!root) return;

    try {
        // Longer timeout: a cold cache-miss does several sequential Google calls.
        // Subsequent loads hit the 30-min server cache and return in ~0.5s.
        const data = await fetchJsonWithTimeout(getHealthMetricsUrl(), 15000);
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