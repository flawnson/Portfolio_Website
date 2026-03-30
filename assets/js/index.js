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
    return str
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

function getMicroPostsUrl() {
    const isLocal =
        window.location.hostname === "localhost" ||
        window.location.hostname === "127.0.0.1";

    return isLocal
        ? "https://flawnson.com/api/micro-posts.php?limit=5"
        : "/api/micro-posts.php?limit=5";
}

async function loadMicroPosts() {
    const root = document.getElementById("micro-posts");
    if (!root) return;

    try {
        const res = await fetch(getMicroPostsUrl());

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

document.addEventListener("DOMContentLoaded", () => {
    loadMicroPosts();
    setInterval(loadMicroPosts, 15000);
});