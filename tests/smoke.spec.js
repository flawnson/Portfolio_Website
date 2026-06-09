const { test, expect } = require("@playwright/test");

async function mockMicroPostsApi(page, posts = [{ body: "hello world", created_at: "2026-05-15 12:00:00" }]) {
  await page.route("**/api/micro-posts.php**", async (route) => {
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        posts,
        has_more: false,
        next_before_id: null
      })
    });
  });
}

test("home page loads with core navigation links", async ({ page }) => {
  await mockMicroPostsApi(page);
  await page.goto("/");

  await expect(page).toHaveTitle(/Flawnson Tong/i);
  await expect(page.getByRole("navigation").getByRole("link", { name: /Blog/i })).toBeVisible();
  await expect(page.getByRole("navigation").getByRole("link", { name: /Resume/i })).toBeVisible();
  await expect(page.getByRole("heading", { name: /What I'm working on/i })).toBeVisible();
});

test("resume PDF is reachable", async ({ request }) => {
  const response = await request.get("/assets/images/Flawnson_Resume%20v10.1.pdf");
  expect(response.ok()).toBeTruthy();
  expect(response.headers()["content-type"] || "").toContain("application/pdf");
});

test("blog index loads and links to posts", async ({ page }) => {
  await page.goto("/blog/");

  await expect(page.locator("h1.blog-list-title")).toBeVisible();
  const cards = page.locator("a.blog-card");
  await expect(cards.first()).toBeVisible();
  expect(await cards.count()).toBeGreaterThan(0);
});

test("fwitter feed renders posts when API responds", async ({ page }) => {
  await mockMicroPostsApi(page, [
    { body: "First mocked fweet", created_at: "2026-05-15 12:00:00" },
    { body: "Second mocked fweet", created_at: "2026-05-15 12:05:00" }
  ]);

  await page.goto("/fwitter/");

  await expect(page.getByRole("heading", { name: "Fwitter" })).toBeVisible();
  await expect(page.locator(".fwitter-post")).toHaveCount(2);
  await expect(page.locator(".fwitter-post-body").first()).toContainText("First mocked fweet");
});

test("command palette opens, searches, and closes", async ({ page }) => {
  await mockMicroPostsApi(page);
  await page.goto("/");

  // The trigger affordance is injected into the primary nav.
  const trigger = page.locator(".cmdk-trigger");
  await expect(trigger).toBeVisible();
  await trigger.click();

  await expect(page.locator(".cmdk-overlay.cmdk-open")).toBeVisible();

  // A seeded query should surface at least one sectioned result.
  await page.locator(".cmdk-input").fill("comend");
  await expect(page.locator(".cmdk-item").first()).toBeVisible();
  expect(await page.locator(".cmdk-item").count()).toBeGreaterThan(0);

  // Escape closes it.
  await page.keyboard.press("Escape");
  await expect(page.locator(".cmdk-overlay.cmdk-open")).toHaveCount(0);
});

test("command palette degrades gracefully when a source fails", async ({ page }) => {
  await mockMicroPostsApi(page);
  // Kill the handmade-card source; the palette must still work.
  await page.route("**/data/search-index.json", async (route) => {
    await route.fulfill({ status: 500, contentType: "application/json", body: "{}" });
  });

  await page.goto("/");
  await page.locator(".cmdk-trigger").click();
  await expect(page.locator(".cmdk-overlay.cmdk-open")).toBeVisible();

  // Built-in actions are always available even with a dead source.
  await page.locator(".cmdk-input").fill("resume");
  await expect(page.locator(".cmdk-item").first()).toBeVisible();
});

test("fwitter shows fallback status when API fails", async ({ page }) => {
  await page.route("**/api/micro-posts.php**", async (route) => {
    await route.fulfill({
      status: 500,
      contentType: "application/json",
      body: JSON.stringify({ error: "internal error" })
    });
  });

  await page.goto("/fwitter/");
  await expect(page.locator("#feed-status")).toContainText("Could not load posts.");
});
