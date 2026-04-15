---
title: Building for high-trust users
slug: building-for-high-trust-users
date: 2026-01-13
excerpt: Most of the time we spent at Comend was building trust, not products.
tags: [comend, product, users]
published: true
---

When we started building [Comend](https://comend.io/), we were not building for a user base that was waiting to be impressed.

We were building for rare disease caregivers and patient groups.

In consumer software, trust is often treated like a growth lever. You make onboarding smoother, improve the UI, tighten the copy, add social proof, and hope people start paying attention. In rare disease, trust works differently. These are users who have learned, through experience, to be careful. They have been ignored, overpromised to, studied without meaningful follow-up, pitched by vendors who did not understand their reality, and approached by industry players whose timelines and incentives don’t match their own. When your users have been burned before, trust is not something you capture with a good first impression.

So if you are building software for high-trust users, especially in health-adjacent spaces, the first lesson is simple: you are not just building a product. You are building a relationship model.

We knew this going in, but didn’t fully understand it. Comend is a research platform for genetic conditions, built to help patient groups and researchers find projects, tools, biosamples, disease models, clinical datasets, service providers, and partners in one place. The platform is used across research plans, RFPs, patient biosamples, disease models, and related research infrastructure, and it has been built with support from more than 30 patient groups.

But the surface area of the product is only part of the story. The harder part was understanding who we were serving and what they had learned to distrust.

## Skepticism is competence

Early on, while we were still ideating on our second product, we spoke to Chris Velona. He founded Project Sebastian after his son Sebastian was diagnosed with CLN8 Batten disease, and he has spent years pushing for research in a space where families often have to create momentum themselves. The cruelty of their story is that even when a potential CLN8 program existed, its pharma backer later stepped away, leaving families once again with no approved treatment and no obvious path forward. I know at least a half dozen similar stories.

Rare disease caregivers and patient group leaders are often among the most resourceful users you will ever meet. Many have effectively become self-taught experts in research, fundraising, treatment landscapes, natural history, clinical trial readiness, and scientific coordination. They do this while dealing with the emotional, logistical, and financial strain of a condition that most of the world barely understands.

They’ve also developed the pattern recognition necessary to protect their efforts. So when:

- A company shows up with polished language and vague promises.
- It wants access, data, introductions, participation, or legitimacy.
- It says it wants partnership, attribution, and kickbacks to the community.

They know better.

“Will this create more work for us?”

“Will our data be safe?”

“Will this still matter six months from now?”

“Are you actually listening, or are you just gathering inputs before building what you already planned to build?”

If you miss that emotional and institutional context, you misread the user entirely. What looks like hesitation is often active due diligence, which translates to slow adoption.

### What you can do about it

- Approach user conversations with humility
- Accept that you won’t win over a user after the first conversation
- Be exhaustive about features (not copy) that give users peace of mind. Data exports, account deletions, minimal required fields, in-app assistance, written guides and documentation all go a long way.

## Trust is product work, not branding work

One of the biggest lessons from Comend was that trust cannot live in slides. It has to show up in product decisions.

You can count on one hand how many startups there were in rare including ourselves. LunaDNA and AllStripes both asked patients to trust more than a brand. LunaDNA promised a community-owned genomic data platform where people could share data while retaining ownership and sharing in its value; AllStripes promised to turn patients’ medical records into research-ready evidence for rare disease drug development. When LunaDNA shut down and AllStripes was absorbed into PicnicHealth, the lesson for patients was simple: trust had been marketed, but not made durable enough in the product.

High-trust users are not persuaded by a security page alone.

They notice whether your product behaviour matches your claims.

- Do you ask for data you do not need?
- Do you make users translate their world into your terminology?
- Do you create visibility without control?
- Do you bury key context behind workflow friction?
- Do you treat patient groups like edge cases in a system designed primarily for commercial actors?

Trust is built when the answer to those questions is consistently no.

In practice, that often meant designing for legibility over cleverness. Users needed to understand what the platform contained, what it did not contain, who it was for, and how information was being used. That sounds basic, but many products lose trust by making simple things opaque. In high-trust environments, opacity gets interpreted as risk.

### What you can do about it

- Squeeze the most of what users give you; scarcity leads to resourcefulness. Make the data you manage collect work for you.
- Don’t speak to sell. Let actions to the talking. An animated landing page with cool visuals and clever copy only serve to alienate these users (look at how simple their websites are)
- Testimonials and word of mouth work better in rare than any outbound marketing

## High-trust users punish abstraction leakage

A core lesson from all the accelerators we joined is that focus is one of the most critical attributes of a successful founder. Being able to ready, aim, fire on a target sounds simple but isn’t easy when there are so many juicy distractions and threads to pull. You simply cannot afford to lose track of what’s important, especially when building for such a niche demographic. This is because rare folks are unusually good at detecting abstraction leakage. If your internal model of the world is generic, they will find the gaps immediately.

For example, if your product treats a patient group like a generic non-profit account, you will miss the fact that many groups are simultaneously family-led, scientifically sophisticated, under-resourced, and operating across advocacy, fundraising, registry building, and research strategy. If you treat a research plan like a document, rather than a coordination artifact with political and scientific weight, you will design the wrong workflows around it.

If they notice that you’re building for too many user types, and your ICP is becoming diluted, they will know that you are courting other audiences. Compartmentalizing the product becomes helpful. Just because we’re building a marketplace of CROs doesn’t mean that we’re turning our attention away from patient groups. Just because patient groups join the platform for free doesn’t mean that they aren’t our priority. I learned that this needs to be communicated clearly.

A simple fact is that for the majority of rare folks, running a patient group is NOT their priority. They have a day job and rare child to care fore. If you’re lucky, they’ll have at most 10% of their time in a given week to work on their non-profit. Case in point; we must find the ones who make running a patient group 100% of their time. We must capture their workflows and build exclusively for them.

For high-trust users, bad abstraction is not a minor UX issue. It signals that you do not really understand their world and you won’t be able to earn their patronage.

### What you can do about it

- Keep it simple. We’ve axed entire product features after working on them simply because they didn’t fit.
- Build in a way that matches their existing workflow. There is a time to be creative and a time to hook onto an existing behaviour/habit the user already has to earn their usage and trust.

## The right kind of speed is quiet

Don’t go 100 in a 50 zone. I was taught that startups celebrate speed. Ship fast, break things, and move quickly. That framing does not translate well in rare.

Patient groups and caregivers do not want to feel like they are standing on an active construction site. They want progress without the chaos. They want responsiveness without the volatility.

So one of the more subtle lessons from Comend was that speed still matters, just differently.

The right kind of speed is quiet.

It looks like:

- Getting a fix out quickly without making users re-learn the product,
- Improving data structure without breaking trust,
- Adding capability without broadening permissions unnecessarily,
- Responding to feedback with visible changes,

It almost felt as if for this demographic, co-designing and building the product is part of the product itself. By the time it’s built, it’s already in their hands. We had one shot to make a successful product, and reputation is everything in a tight-knit community like rare.

### What you can do about it

- Speed up what you actually control; do the building as quickly as possible. Turnover for a feature should be days or even hours.
- Speed up what you actually control; do the building as quickly as possible. Turnover for a feature should be days or even hours.

## My takeaways

Before working in this space, it was easy to think of product trust as mostly a function of polish, reliability, and customer support.

Those things matter, but building for rare made the picture sharper.

For some users, trust is fundamentally historical. You inherit a context you did not create. Your product enters a relationship that already has scars in it. So your job is not to demand confidence. Your job is to behave in a way that makes confidence rational over time.

Truthfully, it’s difficult to come into rare as an outsider and build for them. I enjoyed it, found purpose, and made friends that I’m grateful to have. I think it’s unlikely I’ll do it again. I enjoy having a margin for error and to work in a higher trust environment, where iteration and speed is worth the occasional imperfection. I believe mistakes are critical to improvement. Without mistakes you don’t get feedback, and without feedback you’re blindly navigating the possible action space. Odd yet apt how reinforcement learning concept applies to the real world.