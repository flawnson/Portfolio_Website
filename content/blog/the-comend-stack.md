---
title: The Comend Stack
slug: the-comend-stack
date: 2026-02-25
excerpt: My thoughts on the core stack we used at Comend.
tags: [comend, startup, web-dev, product]
published: true
---

<aside>
💡 This blog is meant to combine elements of a post-mortem, a design doc, and a product documentation page for future maintainers of the product, or anyone trying to build a similar platform.
</aside>

Prior to building [Comend](https://comend.io/) I had limited experience building “production” apps and sites. Most of my work up until had been in AI/ML, classifying proteins and making molecules. I wanted to become that guy that could build anything. So when we started Comend, I made it a goal to try as many technologies as possible and become that “strong opinions, loosely held” developer. It’d be great if at the end of this I have opinions about how quality products can and should be built, and have a preferred stack that I can always pick up. To that end, we’ve succeeded.

I’ve organized this article by decision layer common for engineering teams. We built three products, used a wide spread of modern web tooling, and came out the other side with opinions about what was worth the complexity and what was not. Naturally, everyone on our small team has had to wear all of these hats at some point! In each section I’ll explain what we used, why we picked it, and how we used it.

🟢 Would use again

🟡 Would use situationally

🔴 Would not use again

{{toc}}

# Frontend

## Tailwind 🟢

I fell in love with Tailwind immediately. I like systems that remove decisions I should not be spending time on, and Tailwind does exactly that. When building quickly across multiple products it helps keep spacing, typography, colors, states, and responsive behavior stay consistent without maintaining a growing graveyard of bespoke CSS. Tailwind’s whole model is basically “scan your templates, generate the utilities you actually used, and ship static CSS,” which is a very nice place to be compared to runtime styling magic. More recently, Tailwind has also doubled down on being faster, especially in v4, which matters when you are in that tight feedback loop of tweak-refresh-tweak-refresh all day. Though to be honest, I’ve felt the biggest lift in speed comes from optimizing React and NextJs logic.

## React 🟢

Like the majority of apps built today, we picked react as our frontend framework of choice. Writing declarative, component based files was intuitive and easy to pick up. I think it trusts the developer to organize components, state, and directory structure well, but I suppose that’s the trade-off for a more flexible, unopinionated framework. Paired with NextJs, there were clear best practices for how to structure a project anyways. The biggest reason why we picked React for all 3 of our products is speed.

- There is always an existing component or tool we can use
- Hiring React developers is very easy
- Choose between several quick-start/bootstrap toolchains

You’re very rarely doing something original when working with React, which means you can almost always find something to bootstrap with from the React ecosystem. Once we started using React it was difficult to justify using something else for any new project. In the future I’d love to give Vue, Angular, and especially Svelte a try.

### Zustand 🟢

Zustand is my preferred state management library for React apps that have clearly shared client state, but do not need the ceremony of a full-blown state architecture. It feels like the sweet spot between “just use local state” and “congratulations, you now have a state bureaucracy.” The API is tiny, the mental model is simple, and it does not force you to wrap half your app in abstractions just to update a couple values and actions. I just like how it looks like state is punctuating my code and not taking over it. Like when you’re listening to Beethoven: Symphony No. 9 and that triangle start playing at the Ode to Joy part.

For our products, Zustand was especially useful in places where state needed to be shared across complex UI flows but did not belong in the URL or backend yet. Multi-step forms, search/filter state, local editing state, drawers, modals, optimistic UI, that kind of thing. It let us keep those interactions clean without overengineering them.

I would not use it for everything. If state can stay local, keep it local. If it should live in the URL, put it in the URL. If it belongs on the server, keep it on the server. But when I do need client-side shared state, Zustand is usually the first thing I reach for.

### React Redux 🔴

More like Resucks amirite jajjajajaja (I’m being sarcastic).

It’s very reliable, but even with Redux Toolkit it usually takes noticeably more setup and indirection than Zustand for the kinds of product work I do. At that point, if the state is simple enough, I’d often rather just use React Context API. Once the shared client state gets even a little busy though, Zustand is usually the better trade-off. I have yet to build something that I think requires a solution as robust as Redux, but it’s a good framework to have in my pocket.

## NextJs 🟢

Being able to take advantage of server-side rendering and static site generation helped keep our apps (especially Librarey, where most of the app is pre-compiled at build time) fast and usable. The developer experience is very friendly, and it’s easy to see why NextJs has become the most popular full-stack framework in the world.

For Comend, we ended up sticking with NextJs, even though the advantages were less impactful than they were for Librarey. Our MVP of the product was slow and janky, but after some optimizations like dynamic imports, prefetching, and incremental static regeneration, we found a noticeable difference in how the app felt when used. When it came time to properly build out the admin panel, Next.js (and Vercel) handled the refactor to a monorepo cleanly, and Turborepo kept build fast. Nothing about NextJs’ preferred method of structuring routes affected our organizational structure.

I liked server actions because they made a lot of boring app plumbing disappear. For the kinds of internal tools and product workflows we were building, the ability to put a mutation close to the component that invokes it, run it on the server, then explicitly revalidate the part of the app that needs fresh data was a much cleaner mental model than standing up an API route for every little form submission. That pattern is now pretty core to how Next wants you to mutate data: use a server function, do the write, then call something like `revalidatePath` or `revalidateTag` so the UI updates.

They are not magic, though. You still have to think clearly about boundaries. Authentication, authorization, validation, cache invalidation, and idempotency do not disappear just because the function sits next to the JSX. In practice, server actions worked best for us when we treated them as thin application-layer mutations: validate input, do one coherent write, return predictable state, revalidate intentionally.

## shadcn/ui 🟢

Shadcn/ui deserves a special mention; 90% of all our components across all our products are either directly from, or based on components from the library. In fact the components we custom built almost always started as a component primitive from shadcn that we extended functionality, styles, and logic on. For example, based on the single select component, we built a custom searchable multi-select component that can handle async search, tooltips, maximum and minimum selections, etc. Many of these types of components became shared across all our products.

The open source community has since extended shadcn with a plethora of additional components, added support for other front-end frameworks, and reskinned/styles the entire catalogue of components. The globals.css kept loading everything from custom styles to animations easy. For getting started quickly, I will always pick shadcn/ui.

# Backend

## GraphQL 🟡

I had always wanted to try GraphQL, and while I misunderstood its benefits when I first used it for Librarey, it didn’t end up being a mistake. Since Librarey was a lot more reads than writes, it ended up being a good choice. Our data model had a lot of relationships (resources, events, users, collections, etc.) and our frontend often wanted different shapes of the same underlying entities depending on the page. We’ve found that the strongly typed schema that lets clients ask for exactly what they need and gives you a more stable way to evolve an API over time without exploding your endpoint count to be valuable. Introspection is also one of those things you stop noticing until you work without it again. The fact that tooling can understand the schema directly is a big part of why GraphQL feels so ergonomic.

That said, GraphQL is very easy to romanticize. It is not automatically cleaner than REST. You can absolutely build a miserable GraphQL API with vague types, leaky resolvers, and N+1 query disasters hiding behind “developer experience.” This is where tools like [Prisma TypeGraphQL](https://prisma.typegraphql.com/) comes in handy. Being able to generate resolvers as quickly as we can create migrations feels like magic. That said, we decided against using it for Comend. Given the general direction of the core platform leaned towards data writes rather than reads intuitively felt like the wrong situation to use GraphQL, and we wanted to minimize dependencies to keep the bundle size small, which is why we went with NextJs server actions for Comend.

## tRPC 🔴

When we built Scimantic I was ready to try a new API framework. Recognizing the importance of using shared types across our application, I found tRPC (this was before server actions were released in Next 14). tRPC was great for getting end-to-end types across the stack without maintaining a separate API schema. For a while it was one of the fastest ways for us to build full-stack TypeScript features.

Eventually, server actions made it feel redundant. Once we could handle mutations directly on the server next to the UI, and revalidate the page or data we cared about, we needed a dedicated RPC layer a lot less. tRPC did not stop being good. Next.js just made the simpler path good enough for most of our use cases.

## Prisma 🟡

Prisma was probably the most developer-friendly ORM I have used seriously. I understand why some people bounce off ORMs in general, but Prisma got a lot right for the kind of TypeScript-heavy product work we were doing. The schema is readable, the generated client is genuinely useful, and the type-safety is not fake. It is one of the few tools in web development that consistently made me feel faster without also making me feel like I was losing control. Prisma Migrate also helped force some discipline because it generates an actual history of SQL migration files instead of treating schema changes like hand-wavy suggestions.

Where I ended up more opinionated was around how to use it responsibly. Prisma is nicest when your schema design is already fairly clean and you are willing to think a little about query shape. If you treat the client like an excuse to stop understanding your database, it will punish you eventually. But as a tool for moving fast with a relational database in a TypeScript codebase, it is excellent. I also appreciate that Prisma has kept evolving the internals instead of freezing in place; even fairly low-level things like the client generator and query engine story have kept changing in ways that make it feel more modern rather than more legacy over time.

Throughout building Comend, I was recommended by fellow devs to consider Drizzle. I’m glad I finally did for my personal project Teatico, and have written about how I feel about both ORMs here. I like Drizzle enough for it to be what I reach for now, but Prisma will always be my pick if I’m speedrunning.

# Data and validation

## Zod 🟢

Zod was one of those libraries that quietly ended up everywhere. It made it easy to define validation once, keep the types close to the actual constraints, and reuse the same schemas across forms, APIs, and server logic. Turning Zod into Typescript was easy on the rare occasion we needed to. In a TypeScript codebase, that is a very nice place to be. More importantly, it helped us stop pretending TypeScript types was enough validation. Types are great until real user input shows up, mostly in forms. Zod closed that gap nicely.

## PostgreSQL 🟢

https://www.youtube.com/watch?v=b2F-DItXtZs&t=85s

ol’reliable. I’m glad I didn’t become a MongoDB one-trick when I started web development. Thinking in terms of SQL databases forced me to be decisive, clean, and structured. In the beginning I’ve had to wrestle with migration files, but that happened less and less as I learned how to keep schema changes atomic and backwards compatible. MongoDB can keep buying billboards in SF, but until I need to build something where I truly have no idea what the schema looks like/where it will go, or have an unmanageable amount of metadata, I’ll probably keep using PostgreSQL.

# **Testing and automation**

## Puppeteer 🟢

We ended up using Puppeteer mostly for scraping, and it was great at that. Sometimes you do not want an official API, a third-party integration, or some elaborate ingestion pipeline. You just want to drive a browser, wait for the page to load, grab what you need, and move on. What I liked about it was how direct it felt. Open the page, click the thing, wait for the selector, extract the data. No ceremony, no pretending scraping is more elegant than it is. It was especially useful for sites with client-side rendering, login flows, or content that only appeared after some interaction. In those cases, simple HTTP scraping falls apart quickly and Puppeteer becomes the practical option.

It is definitely more brittle than working with a real API, but that is the nature of scraping. Selectors change, page structures shift, and sometimes a site decides it suddenly hates automation. Even so, for getting structured data out of the messy real web, Puppeteer was one of the most useful tools we used.

## Playwright 🟢

Around after we launched a major marketplace features on Comend we realized we were spending a lot of time doing tests. Many of those tests were obligatory, repetitive, and time consuming. We devs would test before pushing to preview, and then product would test again before pushing to production. We decided to try Playwright. The big thing it gets right is that it understands the browser is asynchronous and hostile, so instead of forcing you to spray sleeps and brittle selectors everywhere, it leans on auto-waiting and web-first assertions. Not needing to guesstimate an appropriate timeout lets me think about what to test rather than how long to way. BrowserContext isolation is also a huge deal. The fact that each test can run in an incognito-like environment with separate cookies, local storage, and session state means tests fail for more honest reasons. When getting into web development, I didn’t realize how fragmented support for certain features would be across all the browsers there are. Having one framework test them all has helped catch a few edge cases (particularly on Safari).

For us, Playwright was the confidence layer that caught the category of issues unit tests simply do not see: auth flows, multi-step forms, permission boundaries, navigation issues, broken modals, regressions across devices, and all the tiny pieces of “the app technically builds but a user would absolutely get stuck here.” If I had to build a product with a small team and pick only one automated testing tool, it would probably be Playwright. The only problem we found was that it tended to be a little unwieldy for smaller use cases, where tests are still important but for different reasons. Suppose that’s the difference between e2e tests and unit tests. For the latter, we turned to Jest.

## Jest 🟢

Jest was our default unit testing tool because it is still the path of least resistance for JavaScript and TypeScript projects. It is easy to wire up, easy to mock with, and good enough that most frontend and Node developers already know how to be productive in it. That matters more than people admit. A testing stack only helps if the team will actually use it. Jest’s mocking model, module interception, and general ergonomics made it very practical for testing utility code, business logic, and smaller application-layer functions without a lot of ceremony. Setting up Jest after setting up Playwright and getting it running on Github Actions really made the difference between the two testing frameworks clear.

Snapshot tests are useful, but only in the same way a smoke alarm is useful: they are good at telling you something changed, not whether the change matters. So the most value we got from Jest was not giant walls of snapshots. It was targeted tests around data transformations, permission logic, edge cases, and places where regressions would be subtle and embarrassing.

# **Product operations**

## Mixpanel 🟡

Mixpanel was our solution to answer product questions with data instead of vibes. I still think event-based analytics is the right mental model for most software products because it maps directly to what users actually do. Mixpanel’s model is pretty much the same as other analytics platforms; events, users, and properties. But the useful part is what you can build on top of that once you start collecting data: funnels, cohorts, retention views, breakdowns, and increasingly group-level analysis for products where the real unit is not just an individual but an organization. That last part was especially relevant for us because many of the behaviors we cared about were not purely personal; they were tied to teams and patient groups.

We took the time as a team to design an event schema that will make sense forever (barring any meaningful change to the platformn). We stick to using property names consistently, and resist the temptation to track everything just because we can. Good analytics feels boring at the implementation layer but extremely useful at the query layer.

## CustomerIO 🟡

When we started building both Librarey and Comend, I thought that I’d be able to handle all the email features we’d want. I quickly learned that while as a developer I’d feel my needs taken care of by a simple React Email and Sendgrid setup (although we continue to keep that around for account verification), there were lots of other features that our product team wanted that wouldn’t be worth for me to build, especially since email was always meant to be a supportive feature and not a core one to our platforms.

[Customer.io](http://customer.io/) was useful because it let us treat customer communication like product infrastructure instead of a pile of one-off emails. [Customer.io](http://customer.io/) gave us a cleaner system for journeys, segmentation, and triggered messaging, while still making it possible to handle truly transactional communication separately from marketing communication. Once our app got good at product events (and tracking things with Mixpanel), tools like this become a lot more valuable. After all the setup the fun part is deciding what should trigger emails, who should get them, when they should stop, and how to avoid making your product feel like it is nagging people. It was fun doing that min–maxing. The one thing that I dislike is the aggressive pricing. I suppose we fall out of their ICP, because $100 USD per month is steep, especially if they’re only allowing us 2 object types (which we used for Organizations and Research Plans on Comend).

# Infrastructure

## Github Actions 🟢

GitHub Actions was the obvious choice for CI/CD because it lives where the code already lives and removes a lot of operational friction for a small team. The YAML is annoying until it is not, and once you have done it a few times, it becomes a very flexible way to standardize builds, tests, deployments, previews, and housekeeping jobs. The combination I liked most was matrix builds plus reusable workflows. That gets you out of the trap of copy-pasting near-identical pipelines across projects and environments.

It also pushed us toward healthier engineering habits. When every pull request runs the same checks, every deploy follows the same path, and secrets/config are handled consistently, it becomes much harder for “works on my machine” culture to survive. You still need judgment about what belongs in CI versus what is overkill, but for a startup team trying to keep standards high without hiring a platform org, GitHub Actions does a lot.

## Vercel 🟢

I find Vercel to have a great developer experience, but poor performance and reliability. There have been several occasions when builds would stall, refuse to trigger, or functions timing out for unclear reasons. Usually this is an upstream AWS thing. We’ve never had a problem with cron jobs, logs, or cache though. In any case we find these issues to be worth the improved ease of access to key features in the dashboard UI including env variables, domains, analytics, concurrent builds, etc. I wish the big cloud providers made it this easy, although I hear in recent years they’ve built products like AWS Amplify or Azure Static Web App that make it easy to go from git push to deploy. In the future I’d still default to a fully managed cloud provider like Vercel, but I’d think twice if I needed to deploy something simple but also critical enough to require 99.99% uptime. For those kinds of apps/apis it’s probably cheaper to do a VPS anyways.

## Google Cloud Platform 🟡

We chose GCP in part because they had a generous startup credits package that we were eligible for since receiving investment from a few institutional investors. It has otherwise been a smooth process of setting up, maintaining, and scaling back the several products we use on the platform.

### Google CloudSQL 🟡

Cloud SQL was exactly what I wanted from a managed relational database: boring in the best possible way. We did not want to spend our time hand-rolling backup strategy, patching database infrastructure, or babysitting replicas. Cloud SQL handles a lot of that operational surface area for you, including automated backups, encryption, maintenance, and general managed-database chores that are valuable but not differentiating for an early-stage company. There were one or two times that we exported a manual dump of our database as a backup, and those went smoothly.

The main trade-off is the one you almost always make with managed infrastructure: less control, more convenience, higher confidence that nobody on your team has to become an accidental database administrator at 1 AM. That was absolutely the right trade for us. My only complaint is that the minimum machine configuration and storage size still felt a little overkill to us, at least when starting off. Thankfully the credits we had easily covered any costs coming our way.

### Google Cloud Run 🟡

Cloud Run was one of the nicer surprises in the stack. I think it hits a sweet spot for teams who want the convenience of serverless without being forced into the tiny-box mental model of traditional functions. You can ship a normal container, keep the runtime fairly standard, and still get the best parts of managed infra: autoscaling, request handling, and scale-to-zero when nothing is happening. That is a very good deal for APIs, workers, lightweight services, and anything where traffic is spiky enough that you do not want idle infrastructure hanging around just to feel important.

I also like that it preserves optionality. It feels closer to “run this container for me intelligently” than “rewrite your architecture around my platform quirks.” For startups, that is ideal. You keep deployment simple without locking your brain into a framework-specific runtime model too early.

### Google OAuth 🟡

Google’s identity stack now makes a much clearer distinction between authentication and authorization, which is how it should be. Signing a user in and asking for access to their Google data are not the same action, and treating them as separate flows keeps the UX and the security model cleaner. Under the hood, it is still the standard OAuth 2.0 and OpenID Connect story: scoped access, tokens, refresh behavior, and as little privilege as you can get away with.

Most of the real work with OAuth is not getting the button on the page. It is correctly handling scopes, token storage, consent, session linking, and the awkward edge cases when a user revokes access or signs in with the wrong Google account. I found it interesting that Google requires [localhost](http://localhost) to be listed as an allowed domain while Facebook’s developer dashboard automatically whitelists localhost. Like many things in software, the demo is easy and the production version is where you find out whether you were being serious. Most of the problems I had implementing auth on our platforms had to do with our choice of auth framework. I will not be using next-auth ever again. The time spent wrangling issues and struggling to do what I felt should be out of the box behaviour was too much. Hopefully auth.js fixes those issues, but until then I’ve found better-auth to be the best web auth framework right now.