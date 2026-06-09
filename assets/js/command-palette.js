/* ==========================================================================
   Command palette (⌘K / Ctrl+K)
   --------------------------------------------------------------------------
   Self-bootstrapping, framework-free, zero backend changes. Loads on every
   page (it injects its own DOM + trigger), so it must be self-contained — the
   helpers below are intentionally duplicated from index.js because this script
   also runs on pages that don't load index.js (blog, fwitter).

   Extensibility lives in SOURCES: one descriptor per searchable feed. Three
   categories — `site` (live/auto), `card` (handmade JSON), `offsite` (third
   party APIs, scaffolded + disabled). Every source loads independently via
   Promise.allSettled, so one failure never breaks the palette.

   Matching lives in createMatcher(): Fuse.js by default, with an automatic
   hand-rolled fallback. Swapping engines is a single-function change.
   ========================================================================== */
(function () {
    "use strict";

    /* ----- self-contained helpers (kept identical to index.js) ----- */

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function getApiBaseUrl() {
        const isLocal =
            window.location.hostname === "localhost" ||
            window.location.hostname === "127.0.0.1";
        return isLocal ? "https://flawnson.com" : "";
    }

    async function fetchJsonWithTimeout(url, timeoutMs) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
        try {
            const res = await fetch(url, { signal: controller.signal, cache: "no-store" });
            const data = await res.json();
            if (!res.ok) {
                throw new Error((data && data.error) || `HTTP ${res.status}`);
            }
            return data;
        } finally {
            clearTimeout(timeoutId);
        }
    }

    /* Port of buildPlatformUrl from index.js — turns a fweet into its public URL. */
    function buildPlatformUrl(post) {
        const ids = post.platform_post_ids;
        const platforms = post.syndicated_platforms;
        if (!ids || !Array.isArray(platforms) || !platforms.length) return null;
        const platform = platforms[0];
        if (platform === "x" && ids.x && ids.x.post_id) {
            return `https://x.com/i/web/status/${ids.x.post_id}`;
        }
        if (platform === "bluesky" && ids.bluesky && ids.bluesky.uri) {
            const parts = ids.bluesky.uri.replace("at://", "").split("/");
            const did = parts[0];
            const rkey = parts[parts.length - 1];
            if (did && rkey) return `https://bsky.app/profile/${did}/post/${rkey}`;
        }
        if (platform === "threads" && ids.threads && ids.threads.url) {
            return ids.threads.url;
        }
        return null;
    }

    /* ----- constants ----- */

    const RESUME_URL = "/assets/images/Flawnson_Resume%20v10.1.pdf";
    const EMAIL = "flawnson.tong@gmail.com";
    const GITHUB_URL = "https://github.com/flawnson/";
    const COMEND_URL = "https://comend.io/";
    const TEATICO_URL = "http://teatico.com/";
    const CACHE_TTL_MS = 5 * 60 * 1000;
    const FUSE_CDN = "https://cdn.jsdelivr.net/npm/fuse.js@7.0.0/dist/fuse.min.js";

    /* Badge label + CSS modifier, and the order sections appear in. Unknown
       types fall back to a neutral badge and sort after the known ones. */
    const TYPE_META = {
        action:   { label: "action",   order: 0 },
        blog:     { label: "blog",      order: 1 },
        fweet:    { label: "fweet",     order: 2 },
        project:  { label: "project",   order: 3 },
        company:  { label: "company",   order: 4 },
        tea:      { label: "tea",       order: 5 },
        paper:    { label: "paper",     order: 6 },
        tool:     { label: "tool",      order: 7 },
        topic:    { label: "topic",     order: 8 },
        "q&a":    { label: "q&a",       order: 9 },
        now:      { label: "now",       order: 10 },
        resume:   { label: "resume",    order: 11 },
        profile:  { label: "profile",   order: 12 },
        github:   { label: "github",    order: 13 },
        music:    { label: "music",     order: 14 },
        link:     { label: "link",      order: 15 },
        timeline: { label: "timeline",  order: 16 }
    };

    function typeOrder(type) {
        return TYPE_META[type] ? TYPE_META[type].order : 99;
    }

    /* ----- record normalization (every source funnels through here) ----- */

    function normalizeRecord(raw, source) {
        if (!raw || !raw.title) return null;
        return {
            id: String(raw.id || `${source.id}-${raw.title}`),
            title: String(raw.title),
            type: raw.type || source.id,
            summary: raw.summary ? String(raw.summary) : "",
            tags: Array.isArray(raw.tags) ? raw.tags : [],
            url: raw.url || "",
            updatedAt: raw.updatedAt || "",
            keywords: Array.isArray(raw.keywords) ? raw.keywords : [],
            action: typeof raw.action === "function" ? raw.action : null,
            _category: source.category
        };
    }

    /* ----- per-source mappers ----- */

    function mapBlog(list) {
        if (!Array.isArray(list)) return [];
        return list.map((p) => ({
            id: `blog-${p.slug}`,
            title: p.title,
            type: "blog",
            summary: p.excerpt || "",
            tags: p.tags || [],
            url: `/blog/${p.slug}/`,
            updatedAt: p.date || "",
            keywords: [p.slug]
        }));
    }

    function mapFweets(posts) {
        if (!Array.isArray(posts)) return [];
        return posts.map((post) => {
            const body = String(post.body || "");
            const title = body.length > 70 ? body.slice(0, 70) + "…" : body;
            return {
                id: `fweet-${post.id}`,
                title: title || "Fweet",
                type: "fweet",
                summary: body,
                tags: Array.isArray(post.syndicated_platforms) ? post.syndicated_platforms : [],
                url: buildPlatformUrl(post) || "/fwitter/",
                updatedAt: post.created_at || ""
            };
        });
    }

    /* ----- built-in command actions ----- */

    function openUrl(url) {
        if (url) window.open(url, "_blank", "noopener,noreferrer");
    }

    function recordsOfType(type) {
        return (state.records || []).filter((r) => r.type === type);
    }

    function newestOfType(type) {
        const list = recordsOfType(type).slice().sort((a, b) =>
            String(b.updatedAt).localeCompare(String(a.updatedAt)));
        return list[0] || null;
    }

    function pickRandom(list) {
        if (!list.length) return null;
        return list[Math.floor(Math.random() * list.length)];
    }

    const ACTIONS = [
        {
            id: "action-copy-resume", title: "Copy résumé link", type: "action",
            summary: "Copy the résumé URL to your clipboard.", keywords: ["cv", "clipboard"],
            action: () => {
                const full = window.location.origin + RESUME_URL;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(full).then(
                        () => toast("Résumé link copied"),
                        () => toast("Could not copy")
                    );
                } else {
                    toast("Clipboard unavailable");
                }
            }
        },
        {
            id: "action-open-resume", title: "Open / download résumé", type: "action",
            summary: "Open the résumé PDF in a new tab.", keywords: ["cv", "download", "pdf"],
            action: () => openUrl(RESUME_URL)
        },
        {
            id: "action-email", title: "Email Flawnson", type: "action",
            summary: EMAIL, keywords: ["contact", "mail"],
            action: () => { window.location.href = `mailto:${EMAIL}`; }
        },
        {
            id: "action-github", title: "Open GitHub", type: "action",
            summary: "github.com/flawnson", keywords: ["code", "repos"],
            action: () => openUrl(GITHUB_URL)
        },
        {
            id: "action-comend", title: "Open Comend", type: "action",
            summary: "Rare disease care platform.", keywords: ["rare disease"],
            action: () => openUrl(COMEND_URL)
        },
        {
            id: "action-teatico", title: "Open Teatico", type: "action",
            summary: "Tea product encyclopedia.", keywords: ["tea"],
            action: () => openUrl(TEATICO_URL)
        },
        {
            id: "action-latest-fweet", title: "Open latest fweet", type: "action",
            summary: "Jump to the most recent micro-post.", keywords: ["fwitter"],
            action: () => {
                const f = newestOfType("fweet");
                openUrl(f && f.url ? f.url : "/fwitter/");
            }
        },
        {
            id: "action-random-fweet", title: "Random fweet", type: "action",
            summary: "Open a random micro-post.", keywords: ["fwitter", "surprise"],
            action: () => {
                const f = pickRandom(recordsOfType("fweet"));
                openUrl(f && f.url ? f.url : "/fwitter/");
            }
        },
        {
            id: "action-random-blog", title: "Random blog post", type: "action",
            summary: "Open a random post.", keywords: ["writing", "surprise"],
            action: () => {
                const b = pickRandom(recordsOfType("blog"));
                if (b && b.url) window.location.href = b.url;
                else window.location.href = "/blog/";
            }
        },
        {
            id: "action-posts-ai", title: "View all posts about AI", type: "action",
            summary: "Filter the palette to AI.", keywords: ["llm", "agents"],
            action: () => setQuery("ai")
        },
        {
            id: "action-posts-rare-disease", title: "View all posts about rare disease", type: "action",
            summary: "Filter the palette to rare disease.", keywords: ["comend", "health"],
            action: () => setQuery("rare disease")
        },
        {
            id: "action-dark-mode", title: "Toggle dark mode", type: "action",
            summary: "Switch between light and dark.", keywords: ["theme", "night"],
            action: () => {
                if (typeof window.darkMode === "function") window.darkMode();
                else document.body.classList.toggle("dark-mode");
            }
        }
    ];

    /* ----- source registry (the extensibility surface) ----- */

    function offsiteStub(id) {
        // Scaffold: registered but disabled. To light up, implement the fetch +
        // normalize to the record schema and flip `enabled` to true.
        return {
            id, category: "offsite", enabled: false,
            load: async () => [] // TODO: fetch + normalize
        };
    }

    const SOURCES = [
        // Built-in actions — always available, no network.
        { id: "actions", category: "action", enabled: true, load: async () => ACTIONS },

        // Natural site items.
        {
            id: "blog", category: "site", enabled: true,
            load: async () => mapBlog(await fetchJsonWithTimeout("/data/blog.json", 6000))
        },
        {
            id: "fweets", category: "site", enabled: true,
            load: async () => {
                const data = await fetchJsonWithTimeout(getApiBaseUrl() + "/api/micro-posts.php?limit=50", 6000);
                return mapFweets(data && data.posts);
            }
        },

        // Handmade cards.
        {
            id: "cards", category: "card", enabled: true,
            load: async () => {
                const list = await fetchJsonWithTimeout("/data/search-index.json", 6000);
                return Array.isArray(list) ? list : [];
            }
        },

        // Third-party offsite data — scaffolded, disabled until APIs are wired.
        // Teatico is first-party (Flawnson's own platform) and the canonical
        // first source to enable: its load() will page the Teatico API and map
        // tea products into records (type: "tea").
        offsiteStub("teatico"),
        offsiteStub("github"),
        offsiteStub("medium"),
        offsiteStub("youtube"),
        offsiteStub("spotify"),
        offsiteStub("linkedin")
    ];

    /* ----- record loading: graceful, cached ----- */

    const state = {
        records: [],
        loaded: false,
        loadingPromise: null,
        cacheAt: 0,
        anySourceFailed: false,
        query: "",
        results: [],   // flat, ordered list of visible records (for keyboard nav)
        active: 0
    };

    async function loadAllRecords(force) {
        if (!force && state.loaded && Date.now() - state.cacheAt < CACHE_TTL_MS) {
            return state.records;
        }
        if (state.loadingPromise) return state.loadingPromise;

        const enabled = SOURCES.filter((s) => s.enabled);
        state.loadingPromise = Promise.allSettled(enabled.map((s) => s.load()))
            .then((settled) => {
                const records = [];
                let anyFail = false;
                settled.forEach((res, i) => {
                    if (res.status === "fulfilled" && Array.isArray(res.value)) {
                        res.value.forEach((raw) => {
                            const n = normalizeRecord(raw, enabled[i]);
                            if (n) records.push(n);
                        });
                    } else {
                        anyFail = true;
                        console.warn("[cmdk] source failed:", enabled[i].id,
                            res.reason || "non-array result");
                    }
                });
                state.records = records;
                state.loaded = true;
                state.cacheAt = Date.now();
                state.anySourceFailed = anyFail;
                state.matcher = createMatcher(records);
                return records;
            })
            .finally(() => { state.loadingPromise = null; });

        return state.loadingPromise;
    }

    /* ----- matcher (swappable) ----- */

    function createMatcher(records) {
        if (window.Fuse) {
            const fuse = new window.Fuse(records, {
                keys: [
                    { name: "title", weight: 3 },
                    { name: "tags", weight: 2 },
                    { name: "keywords", weight: 2 },
                    { name: "summary", weight: 1 }
                ],
                threshold: 0.4,
                ignoreLocation: true,
                includeScore: true,
                minMatchCharLength: 2
            });
            return { search: (q) => fuse.search(q).map((r) => r.item) };
        }
        return fallbackMatcher(records);
    }

    /* Zero-dependency ranked substring matcher — the easy swap target and the
       automatic fallback when Fuse is unavailable. */
    function fallbackMatcher(records) {
        const indexed = records.map((r) => ({
            r,
            hay: [r.title, r.tags.join(" "), (r.keywords || []).join(" "), r.summary]
                .join(" ").toLowerCase()
        }));
        return {
            search: (q) => {
                const terms = q.toLowerCase().split(/\s+/).filter(Boolean);
                if (!terms.length) return [];
                const scored = [];
                indexed.forEach(({ r, hay }) => {
                    let score = 0;
                    let all = true;
                    terms.forEach((t) => {
                        const title = r.title.toLowerCase();
                        if (title.startsWith(t)) score += 6;
                        else if (title.includes(t)) score += 4;
                        else if ((r.tags || []).some((tag) => String(tag).toLowerCase().includes(t))) score += 3;
                        else if (hay.includes(t)) score += 1;
                        else all = false;
                    });
                    if (all) scored.push({ r, score });
                });
                return scored.sort((a, b) => b.score - a.score).map((s) => s.r);
            }
        };
    }

    /* ----- DOM ----- */

    let els = null;

    function buildDom() {
        const overlay = document.createElement("div");
        overlay.className = "cmdk-overlay";
        overlay.setAttribute("role", "dialog");
        overlay.setAttribute("aria-modal", "true");
        overlay.setAttribute("aria-label", "Site search and commands");
        overlay.innerHTML = `
            <div class="cmdk-panel" role="document">
                <div class="cmdk-search">
                    <span class="cmdk-search-icon" aria-hidden="true">🔍</span>
                    <input class="cmdk-input" type="text" autocomplete="off" spellcheck="false"
                           placeholder="Search posts, projects, people, commands…"
                           aria-label="Search" />
                    <span class="cmdk-esc">esc</span>
                </div>
                <div class="cmdk-results" role="listbox"></div>
                <div class="cmdk-footer">
                    <span><span class="cmdk-kbd">↑</span><span class="cmdk-kbd">↓</span> navigate
                          <span class="cmdk-kbd">↵</span> open
                          <span class="cmdk-kbd">esc</span> close</span>
                    <span>⌘K</span>
                </div>
            </div>`;
        document.body.appendChild(overlay);

        els = {
            overlay,
            panel: overlay.querySelector(".cmdk-panel"),
            input: overlay.querySelector(".cmdk-input"),
            results: overlay.querySelector(".cmdk-results")
        };

        // Close on backdrop click (but not when clicking inside the panel).
        overlay.addEventListener("mousedown", (e) => {
            if (e.target === overlay) close();
        });
        els.input.addEventListener("input", () => {
            state.query = els.input.value;
            renderResults();
        });

        return els;
    }

    /* ----- rendering ----- */

    function computeResults() {
        const q = state.query.trim();
        if (!q) {
            // Empty state: all actions + most recent site items, recency-first.
            const actions = recordsOfType("action");
            const recent = state.records
                .filter((r) => r.type === "blog" || r.type === "fweet")
                .slice()
                .sort((a, b) => String(b.updatedAt).localeCompare(String(a.updatedAt)))
                .slice(0, 8);
            return actions.concat(recent);
        }
        const matcher = state.matcher || createMatcher(state.records);
        return matcher.search(q).slice(0, 50);
    }

    function groupBySection(records) {
        const groups = {};
        records.forEach((r) => {
            (groups[r.type] = groups[r.type] || []).push(r);
        });
        return Object.keys(groups)
            .sort((a, b) => typeOrder(a) - typeOrder(b))
            .map((type) => ({ type, items: groups[type] }));
    }

    function renderResults() {
        const records = computeResults();
        state.results = records;
        state.active = 0;

        if (!records.length) {
            els.results.innerHTML = `<div class="cmdk-empty">No results${
                state.anySourceFailed ? " — some sources are unavailable." : "."}</div>`;
            return;
        }

        const notice = state.anySourceFailed
            ? `<div class="cmdk-notice">Some sources are unavailable; showing what loaded.</div>`
            : "";

        const sections = groupBySection(records);
        let flatIndex = 0;
        const html = sections.map((section) => {
            const meta = TYPE_META[section.type] || { label: section.type };
            const items = section.items.map((r) => {
                const idx = flatIndex++;
                const badgeCls = TYPE_META[r.type] ? `cmdk-badge--${cssType(r.type)}` : "";
                return `
                    <div class="cmdk-item" role="option" data-index="${idx}" data-id="${escapeHtml(r.id)}">
                        <div class="cmdk-item-body">
                            <div class="cmdk-item-title">${escapeHtml(r.title)}</div>
                            ${r.summary ? `<div class="cmdk-item-summary">${escapeHtml(r.summary)}</div>` : ""}
                        </div>
                        <span class="cmdk-badge ${badgeCls}">${escapeHtml(meta.label)}</span>
                    </div>`;
            }).join("");
            return `<div class="cmdk-section">
                        <div class="cmdk-section-title">${escapeHtml(meta.label)}</div>
                        ${items}
                    </div>`;
        }).join("");

        els.results.innerHTML = notice + html;
        wireItemEvents();
        highlightActive();
    }

    function cssType(type) {
        return type.replace(/[^a-z0-9]/gi, "");
    }

    function wireItemEvents() {
        els.results.querySelectorAll(".cmdk-item").forEach((el) => {
            el.addEventListener("mousemove", () => {
                state.active = Number(el.dataset.index);
                highlightActive();
            });
            el.addEventListener("click", () => {
                activate(state.results[Number(el.dataset.index)]);
            });
        });
    }

    function highlightActive() {
        const items = els.results.querySelectorAll(".cmdk-item");
        items.forEach((el) => {
            const on = Number(el.dataset.index) === state.active;
            el.classList.toggle("cmdk-active", on);
            if (on) el.scrollIntoView({ block: "nearest" });
        });
    }

    function move(delta) {
        if (!state.results.length) return;
        state.active = (state.active + delta + state.results.length) % state.results.length;
        highlightActive();
    }

    /* ----- activation (navigate or run a command) ----- */

    function activate(record) {
        if (!record) return;
        if (record.action) {
            // Actions that re-filter the palette keep it open (setQuery handles
            // that); everything else closes after running.
            const before = state.query;
            record.action();
            if (state.query === before) close();
            return;
        }
        if (!record.url) { close(); return; }
        if (/^https?:\/\//i.test(record.url)) {
            openUrl(record.url);
            close();
        } else {
            window.location.href = record.url;
        }
    }

    function setQuery(q) {
        if (!els) return;
        els.input.value = q;
        state.query = q;
        els.input.focus();
        renderResults();
    }

    /* ----- open / close ----- */

    let lastFocused = null;

    function open() {
        if (!els) buildDom();
        if (els.overlay.classList.contains("cmdk-open")) return;
        lastFocused = document.activeElement;
        els.overlay.classList.add("cmdk-open");
        els.input.value = "";
        state.query = "";
        document.body.style.overflow = "hidden";

        // Render immediately (actions are always present), then refresh once
        // async sources resolve.
        if (!state.loaded && !state.records.length) {
            state.records = ACTIONS.map((a) => normalizeRecord(a, { id: "actions", category: "action" }));
        }
        renderResults();
        els.input.focus();

        loadAllRecords().then(() => {
            if (els.overlay.classList.contains("cmdk-open")) renderResults();
        }).catch((err) => console.warn("[cmdk] load error:", err));
    }

    function close() {
        if (!els) return;
        els.overlay.classList.remove("cmdk-open");
        document.body.style.overflow = "";
        if (lastFocused && lastFocused.focus) lastFocused.focus();
    }

    function toggle() {
        if (els && els.overlay.classList.contains("cmdk-open")) close();
        else open();
    }

    /* ----- toast (lightweight, for clipboard feedback) ----- */

    function toast(msg) {
        let t = document.getElementById("cmdk-toast");
        if (!t) {
            t = document.createElement("div");
            t.id = "cmdk-toast";
            t.style.cssText =
                "position:fixed;left:50%;bottom:24px;transform:translateX(-50%);" +
                "background:#111;color:#fff;padding:8px 14px;border-radius:999px;" +
                "font-family:Karla,Roboto,sans-serif;font-size:1.3rem;z-index:10000;" +
                "opacity:0;transition:opacity .2s ease;";
            document.body.appendChild(t);
        }
        t.textContent = msg;
        requestAnimationFrame(() => { t.style.opacity = "1"; });
        clearTimeout(toast._timer);
        toast._timer = setTimeout(() => { t.style.opacity = "0"; }, 1600);
    }

    /* ----- keyboard ----- */

    document.addEventListener("keydown", (e) => {
        const isToggle = (e.metaKey || e.ctrlKey) && (e.key === "k" || e.key === "K");
        if (isToggle) {
            e.preventDefault();
            toggle();
            return;
        }
        if (!els || !els.overlay.classList.contains("cmdk-open")) return;
        switch (e.key) {
            case "Escape": e.preventDefault(); close(); break;
            case "ArrowDown": e.preventDefault(); move(1); break;
            case "ArrowUp": e.preventDefault(); move(-1); break;
            case "Enter": e.preventDefault(); activate(state.results[state.active]); break;
        }
    });

    /* ----- trigger affordance ----- */

    function injectTrigger() {
        const navList = document.querySelector("nav ul");
        const trigger = document.createElement("button");
        trigger.type = "button";
        trigger.className = "cmdk-trigger";
        trigger.setAttribute("aria-label", "Open search (Command or Control + K)");
        trigger.innerHTML = `🔍 Search <span class="cmdk-kbd">⌘K</span>`;
        trigger.addEventListener("click", open);

        if (navList) {
            const li = document.createElement("li");
            li.appendChild(trigger);
            navList.insertBefore(li, navList.firstChild);
        } else {
            trigger.classList.add("cmdk-trigger--floating");
            document.body.appendChild(trigger);
        }
    }

    /* ----- Fuse loader (graceful: fallback matcher used if it fails) ----- */

    function loadFuse() {
        if (window.Fuse) return;
        const s = document.createElement("script");
        s.src = FUSE_CDN;
        s.defer = true;
        s.addEventListener("load", () => {
            // Rebuild the matcher now that Fuse is available.
            if (state.records.length) state.matcher = createMatcher(state.records);
        });
        s.addEventListener("error", () => console.warn("[cmdk] Fuse failed to load; using fallback matcher."));
        document.head.appendChild(s);
    }

    /* ----- boot ----- */

    function boot() {
        injectTrigger();
        loadFuse();
        // Expose for debugging / programmatic open.
        window.commandPalette = { open, close, toggle, reload: () => loadAllRecords(true) };
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot);
    } else {
        boot();
    }
})();
