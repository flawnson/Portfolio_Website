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
