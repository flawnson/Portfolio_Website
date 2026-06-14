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

function getGithubContributionsUrl() {
    return `${getApiBaseUrl()}/api/github-contributions.php`;
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

// Day an exercise belongs to, in the viewer's local timezone. FITBIT exercise
// points carry only a UTC instant (no civil/local time), so the server's m.date
// is in UTC — an evening workout in a negative UTC offset (e.g. logged at
// 9pm = 01:00Z next day) lands on the wrong day. Re-bucket from the instant so
// it matches the local today/yesterday axis below; fall back to m.date.
function exerciseDateKey(m) {
    if (m && m.start) {
        const t = new Date(m.start);
        if (!Number.isNaN(t.getTime())) return localDateKey(t);
    }
    return (m && m.date) || null;
}

// Minutes -> "h:mm" (e.g. 469 -> "7:49").
function formatHoursMinutes(minutes) {
    const total = Math.max(0, Math.round(Number(minutes) || 0));
    return `${Math.floor(total / 60)}:${pad2(total % 60)}`;
}

// Tiny dotted-line sparkline (no axes) for a series of {date, value} points,
// with the value printed below each dot.
function buildSparkline(points) {
    if (!points.length) return "";

    const W = 240, H = 64, padX = 14, padTop = 12, padBottom = 20;
    const chartH = H - padTop - padBottom;
    const n = points.length;

    const values = points.map((p) => p.value);
    let vMin = Math.min(...values);
    let vMax = Math.max(...values);
    if (vMin === vMax) { vMin -= 1; vMax += 1; } // flat series -> centered line

    const xAt = (i) => (n === 1 ? W / 2 : padX + (i * (W - 2 * padX)) / (n - 1));
    const yAt = (v) => padTop + (1 - (v - vMin) / (vMax - vMin)) * chartH;

    const line = points.map((p, i) => `${xAt(i).toFixed(1)},${yAt(p.value).toFixed(1)}`).join(" ");
    const dots = points.map((p, i) => `<circle cx="${xAt(i).toFixed(1)}" cy="${yAt(p.value).toFixed(1)}" r="2.6" />`).join("");
    const nums = points.map((p, i) => `<text x="${xAt(i).toFixed(1)}" y="${H - 6}" text-anchor="middle" class="spark-num">${escapeHtml(String(p.value))}</text>`).join("");

    return `<svg class="spark-svg" viewBox="0 0 ${W} ${H}" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Resting heart rate over the past week">
        <polyline points="${line}" fill="none" class="spark-line" />
        ${dots}${nums}
    </svg>`;
}

// Tiny bar chart (no axes) for {date, value} points, value labelled below each
// bar via fmt(). Bars scale from 0 to the week's max.
function buildBars(points, fmt) {
    if (!points.length) return "";

    const W = 240, H = 64, padX = 8, padTop = 10, padBottom = 20;
    const chartH = H - padTop - padBottom;
    const n = points.length;
    const vMax = Math.max(...points.map((p) => p.value)) || 1;

    const slot = (W - 2 * padX) / n;
    const barW = Math.min(slot * 0.62, 18);

    const bars = points.map((p, i) => {
        const cx = padX + slot * i + slot / 2;
        const h = Math.max(2, (p.value / vMax) * chartH);
        const y = padTop + (chartH - h);
        return `<rect x="${(cx - barW / 2).toFixed(1)}" y="${y.toFixed(1)}" width="${barW.toFixed(1)}" height="${h.toFixed(1)}" rx="1.5" class="bar-rect" />`;
    }).join("");

    const nums = points.map((p, i) => {
        const cx = padX + slot * i + slot / 2;
        return `<text x="${cx.toFixed(1)}" y="${H - 6}" text-anchor="middle" class="spark-num">${escapeHtml(fmt(p.value))}</text>`;
    }).join("");

    return `<svg class="spark-svg" viewBox="0 0 ${W} ${H}" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Time slept over the past week">${bars}${nums}</svg>`;
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
    const rhrByDate = {};
    const sleepByDate = {};

    for (const m of metrics) {
        if (!m) continue;
        if (m.dataType === "steps" && m.date) {
            const v = Number(m.value);
            if (Number.isFinite(v)) stepsByDate[m.date] = v;
        } else if (m.dataType === "exercise") {
            const key = exerciseDateKey(m);
            if (key) exerciseDates.add(key);
        } else if (m.dataType === "daily-resting-heart-rate" && m.date) {
            const v = Number(m.value);
            if (Number.isFinite(v)) rhrByDate[m.date] = v;
        } else if (m.dataType === "sleep" && m.date) {
            const v = Number(m.value);
            if (Number.isFinite(v) && v > 0) {
                // Sum sessions on the same day (main sleep + naps).
                sleepByDate[m.date] = (sleepByDate[m.date] || 0) + v;
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
        circles.push(
            workedOut
                ? `<span class="health-day-check" role="img" title="${key}: worked out" aria-label="${key}: worked out">✅</span>`
                : `<span class="health-day-rest" role="img" title="${key}: rest" aria-label="${key}: rest">❌</span>`
        );
    }

    const sleepPoints = Object.keys(sleepByDate)
        .sort()
        .slice(-7)
        .map((date) => ({ date, value: sleepByDate[date] }));
    const sleepAvg = sleepPoints.length
        ? Math.round(sleepPoints.reduce((s, p) => s + p.value, 0) / sleepPoints.length)
        : null;
    const sleepHtml = sleepPoints.length
        ? `<div class="health-sleep-section">
               <div class="health-label health-head">
                   <span><span class="health-sleep-icon" aria-hidden="true">💤</span>Time slept</span>
                   <span class="health-avg">${formatHoursMinutes(sleepAvg)} avg</span>
               </div>
               ${buildBars(sleepPoints, formatHoursMinutes)}
           </div>`
        : `<div class="health-sleep-section">
               <div class="health-label"><span class="health-sleep-icon" aria-hidden="true">💤</span>No recent sleep data</div>
           </div>`;

    // Resting heart rate sparkline — last 7 days with data, oldest -> newest.
    const rhrPoints = Object.keys(rhrByDate)
        .sort()
        .slice(-7)
        .map((date) => ({ date, value: rhrByDate[date] }));
    const rhrHtml = rhrPoints.length
        ? `<div class="health-rhr">
               <div class="health-label health-head">
                   <span><span class="health-sleep-icon" aria-hidden="true">❤️</span>Resting heart rate</span>
                   <span class="health-avg">${Math.round(rhrPoints.reduce((s, p) => s + p.value, 0) / rhrPoints.length)} avg</span>
               </div>
               ${buildSparkline(rhrPoints)}
           </div>`
        : "";

    root.innerHTML = `
        <div class="health-workouts">
            <div class="health-label"><span class="health-sleep-icon" aria-hidden="true">🏋️</span>Workouts the past 7 days</div>
            <div class="health-week" role="group" aria-label="Workouts over the past 7 days (excluding today)">${circles.join("")}</div>
        </div>
        ${sleepHtml}
        ${rhrHtml}`;
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
        root.innerHTML = "<p class=\"health-loading\">Could not load health data right now.</p>";
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

const microPostsRequest = fetchJsonWithTimeout(getMicroPostsUrl(), 5000);
const githubContributionsRequest = fetchJsonWithTimeout(getGithubContributionsUrl(), 15000);

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

function contributionLevel(count) {
    if (count <= 0) return 0;
    if (count <= 3) return 1;
    if (count <= 6) return 2;
    if (count <= 9) return 3;
    return 4;
}

const MONTH_LABELS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun",
    "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

function renderContributionGraph(data) {
    const root = document.getElementById("github-panel");
    if (!root) return;

    const days = Array.isArray(data.days) ? data.days : [];
    if (!days.length) {
        root.innerHTML = "<p class=\"contrib-loading\">No contributions to show.</p>";
        return;
    }

    // Pad the front so the first column starts on a Sunday (GitHub-style grid:
    // 7 rows Sun→Sat, one column per week).
    const firstDow = new Date(`${days[0].date}T00:00:00Z`).getUTCDay();
    const cells = [];
    for (let i = 0; i < firstDow; i++) {
        cells.push(null); // empty leading cell
    }
    days.forEach((d) => cells.push(d));

    // Only render as many trailing weeks as fit the column width, so the grid
    // fills the left column without overflowing into a horizontal scroll.
    const GAP = 3;          // matches .contrib-grid gap
    const CELL = 11;        // target square size in px
    const avail = root.clientWidth || 240;
    const maxWeeks = Math.max(1, Math.floor((avail + GAP) / (CELL + GAP)));
    const totalWeeks = Math.ceil(cells.length / 7);
    const weekCount = Math.min(totalWeeks, maxWeeks);
    // Drop whole leading weeks (slice on a multiple of 7) so every remaining
    // column keeps its Sun→Sat row alignment.
    const trimmed = cells.slice((totalWeeks - weekCount) * 7);
    cells.length = 0;
    trimmed.forEach((c) => cells.push(c));

    // Month labels: mark a column when its first (top) day starts a new month.
    let lastMonth = -1;
    const monthCols = [];
    for (let w = 0; w < weekCount; w++) {
        const cell = cells[w * 7];
        if (cell && cell.date) {
            const m = new Date(`${cell.date}T00:00:00Z`).getUTCMonth();
            if (m !== lastMonth) {
                monthCols.push({ col: w, label: MONTH_LABELS[m] });
                lastMonth = m;
            }
        }
    }

    const squares = cells.map((cell) => {
        if (!cell) {
            return "<span class=\"contrib-cell contrib-empty\"></span>";
        }
        const level = contributionLevel(cell.count);
        const noun = cell.count === 1 ? "contribution" : "contributions";
        const title = escapeHtml(`${cell.count} ${noun} on ${cell.date}`);
        return `<span class="contrib-cell lvl-${level}" title="${title}"></span>`;
    }).join("");

    const monthRow = monthCols.map(({ col, label }) =>
        `<span class="contrib-month" style="grid-column: ${col + 1};">${label}</span>`
    ).join("");

    const total = Number(data.totalContributions || 0).toLocaleString();
    const meta = data.meta || {};
    let note = "personal + work";
    if (meta.stale) {
        note += " · showing saved data";
    } else if (meta.partial) {
        note += " · one account unavailable";
    }

    root.innerHTML = `
        <div class="contrib-head">
            <span class="contrib-total">${total} contributions</span>
            <span class="contrib-note">${escapeHtml(note)}</span>
        </div>
        <div class="contrib-scroll">
            <div class="contrib-months" style="grid-template-columns: repeat(${weekCount}, 1fr);">${monthRow}</div>
            <div class="contrib-grid" style="grid-template-columns: repeat(${weekCount}, 1fr);">${squares}</div>
            <div class="contrib-legend">
                <span>Less</span>
                <span class="contrib-cell lvl-0"></span>
                <span class="contrib-cell lvl-1"></span>
                <span class="contrib-cell lvl-2"></span>
                <span class="contrib-cell lvl-3"></span>
                <span class="contrib-cell lvl-4"></span>
                <span>More</span>
            </div>
        </div>
    `;
}

async function loadGithubContributions(request) {
    const root = document.getElementById("github-panel");
    if (!root) return;

    try {
        const data = request
            ? await request
            : await fetchJsonWithTimeout(getGithubContributionsUrl(), 15000);
        if (!data || data.ok === false) {
            throw new Error((data && data.error) || "contributions_unavailable");
        }
        renderContributionGraph(data);

        // Re-fit the grid to the column when the viewport changes.
        let resizeTimer;
        window.addEventListener("resize", () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => renderContributionGraph(data), 150);
        });
    } catch (err) {
        console.error(err);
        root.innerHTML = "<p class=\"contrib-loading\">Could not load contributions right now.</p>";
    }
}

loadMicroPosts();
loadGithubContributions(githubContributionsRequest);
loadHealthMetrics();
setInterval(refreshMicroPosts, 15000);
setInterval(loadHealthMetrics, 600000); // refresh health every 10 minutes
setInterval(loadGithubContributions, 1800000); // refresh contributions every 30 minutes

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