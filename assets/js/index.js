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
        const platforms = Array.isArray(post.syndicated_platforms) && post.syndicated_platforms.length
            ? `<span style="opacity:0.68;font-size:inherit;">${post.syndicated_platforms.map((p) => platformLabels[p] || p).join(", ")}</span>`
            : "";

        return `
            <article class="micro-post" style="margin-bottom: 1.25rem; padding-bottom: 1rem; border-bottom: 1px solid #ddd;">
                <p style="white-space: pre-wrap; margin-bottom: 0.4rem;">${safeBody}</p>
                <small style="display:flex;justify-content:space-between;align-items:center;"><span>${relativeTime}</span>${platforms}</small>
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
setInterval(refreshMicroPosts, 15000);