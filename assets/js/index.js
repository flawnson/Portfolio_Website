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

function renderMicroPosts(posts) {
    const root = document.getElementById("micro-posts");
    if (!root) return;

    if (!posts.length) {
        root.innerHTML = "<p>No posts yet.</p>";
        return;
    }

    root.innerHTML = posts.map((post) => {
        const safeBody = linkify(escapeHtml(post.body));
        const createdAt = new Date(post.created_at.replace(" ", "T"));

        return `
            <article class="micro-post" style="margin-bottom: 1.25rem; padding-bottom: 1rem; border-bottom: 1px solid #ddd;">
                <p style="white-space: pre-wrap; margin-bottom: 0.4rem;">${safeBody}</p>
                <small>${createdAt.toLocaleString()}</small>
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
    return `${getApiBaseUrl()}/github-last-commit.php`;
}

async function loadMicroPosts() {
    const root = document.getElementById("micro-posts");
    if (!root) return;

    try {
        const res = await fetch(getMicroPostsUrl(), { cache: "no-store" });

        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }

        const data = await res.json();
        renderMicroPosts(data.posts || []);
    } catch (err) {
        console.error(err);
        root.innerHTML = "<p>Could not load posts.</p>";
    }
}

let lastCommitInterval = null;

async function loadLastCommitTimer() {
    const statusEl = document.getElementById("last-commit-status");
    const timerEl = document.getElementById("last-commit-timer");

    if (!statusEl || !timerEl) return;

    try {
        const res = await fetch(getLastCommitUrl(), { cache: "no-store" });
        const data = await res.json();

        if (!res.ok) {
            throw new Error(data.error || "Failed to fetch last commit.");
        }

        const lastCommitDate = new Date(data.created_at);
        const repoName = data.repo || "unknown repo";

        if (Number.isNaN(lastCommitDate.getTime())) {
            throw new Error("Invalid commit timestamp received.");
        }

        statusEl.innerHTML = `Last push was to <b>${escapeHtml(repoName)}</b> on ${lastCommitDate.toLocaleString()}.`;

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
        statusEl.textContent = "Could not load last commit timer.";
        timerEl.textContent = "";
        console.error(error);
    }
}

document.addEventListener("DOMContentLoaded", () => {
    loadMicroPosts();
    loadLastCommitTimer();
    setInterval(loadMicroPosts, 15000);
});