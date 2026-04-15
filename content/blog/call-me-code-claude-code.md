---
title: AI coding agents suck at security
slug: ai-coding-agents-suck-at-security
date: 2026-04-2
excerpt: Most developers extol AI agents. But they are notoriously bad at security.
tags: [ai, agents, engineering, security, development]
published: true
---

<aside>
💡 I LOVE these tools. Codex and Claude Code have likely given me months of my life back at this point and I do not intend to stop using them. I, like millions of developers around the world, use it every day, and I do believe that you are “behind” (at least in the productive kind of way) if you don’t use these tools (for better or for worse). This blog post is about my opinions about privacy, data security, and a little bit of ethics.
</aside>

A lot of the current conversation around AI security has the tone of a revelation. Suddenly people are talking about prompt injection, tool abuse, data exfiltration, uncontrolled side effects, and the weird reality that once you give a model filesystem access, shell access, browser access, or messaging access, mistakes stop being abstract. They become operational.

That conversation is good. It is also late.

Security only feels urgent now because projects like OpenClaw make the danger legible to more people. OpenClaw is the kind of system that makes the stakes obvious: an agent with real tools, real permissions, real integrations, and real opportunities to do real damage. Recent security work around OpenClaw has been blunt about this. One March 2026 paper found that OpenClaw’s native defenses were weak against adversarial instructions and that adding a human-in-the-loop layer dramatically improved outcomes. Another described the broader problem more plainly: once an agent has local file access, shell execution, and third-party skills, model mistakes become system-level threats.

But developers have been ringing alarm bells well before that, even around much simpler agentic tools like Codex and Claude Code. The issue is not complicated. If a coding agent can read more than you think it can read, or if it can route around the protections you assumed existed, then the model is not operating inside a clean project boundary. It is operating in a soft boundary built out of convention, documentation, and wishful thinking.

This is not security. It’s worth unpacking this.

# My name is Joe

Whenever I’m asked for my name at Starbucks, Shake Shack, or any other name-asking establishment, I always give my dad’s name Joe (short for Joseph). My name is Flawnson. Before I was born, my dad made it up by taking the words:

Flaws + non and swapping the s and the n.

My relationship to privacy is probably more personal than it is for most people. Flawnson is not exactly John or Michael. As far as I know, I am the only person on earth with this name. I can’t prove it, but I own the [flawnson.com](http://flawnson.com) domain for what it’s worth. That means I don’t really get to disappear into the crowd. If someone searches for me, they are not sorting through a thousand near-matches and plausible deniability. They are finding me, my work, my accounts, my writing, my history, my mistakes, my associations. There is very little ambient anonymity when your identity is that specific. My name is a UUID.

That changed how I think about security. I try not to take advantage of pseudonymity or anonymity when given the opportunity (my Reddit handle is just my name). I find it more helpful to force me to think twice before I post something online. I think to myself: “Would I still post this if everyone knew exactly who I am?”

Privacy is not some abstract ideological preference to me. It is practical. It is defensive. It is the difference between being legible on my own terms and being legible by default. When you are highly identifiable, small leaks do not stay small for long. I actually set up an OnlyFans account, partly because I would prefer to claim the handle now (who wants to see some thirst traps? Maybe part of my retirement plan idk) but also because it would be too easy for some deranged person to make an account with my name and do something crazy with it.

This is why in recent years, I’ve become much more paranoid about data privacy and security. A stray credential, an exposed path, a linked account, a reused handle, a carelessly surfaced document, a tool with too much filesystem access — all of it compounds faster when there is effectively no one else to be mistaken for. That is part of why I care so much about deterministic boundaries, least privilege, and not giving software broad access just because it is convenient. For some people, privacy failures are annoying. For me, they are much harder to diffuse.

# The Problem Is Not Theoretical

Whenever I need an API key, the first thing I do is search for it on GitHub. It baffles me how many people leave secrets and environment variables sitting in public repos, old commits, test files, throwaway scripts, misconfigured examples, or half-finished side projects they forgot anyone could see. It is one of those problems that sounds too sloppy to be common until you look for it and realize it is everywhere. That is part of what makes broad agent access so uncomfortable to me. We already know developers are bad at keeping sensitive material contained under normal conditions. Now we are layering autonomous tools on top of messy workspaces, ambient credentials, and years of bad hygiene.

One of the clearer examples is the ongoing argument around Codex and filesystem scope.

There is a [closed GitHub issue from October 2025](https://github.com/openai/codex/issues/5237) where a user points out that read-only mode still effectively allows reads outside the current project tree by default, and argues that the safer default should be to limit reads to the current directory and below. The rebuttal from a collaborator was telling: restricting reads that tightly would break things, and one example given was that trying to use `cat` would fail because Codex would not be able to read `/bin/cat`. The problem with that response is that it confuses “the system can execute a binary” with “the agent should have broad permission to read unrelated files across the filesystem.” Those are not the same thing. Developers noticed that immediately.

That issue matters because it exposes the underlying mindset. There is still too much slippage between what users think “scoped to the project” means and what the tool may actually be able to inspect.

[Another issue, still open](https://github.com/openai/codex/issues/2847), asks for what should honestly be table stakes: a deterministic way to exclude sensitive files and paths from agent access and model context, at both the repo and user level. The request is straightforward: support something like a repo-local ignore file and a global ignore file so teams can say, in a real enforceable way, never read or send `.env`, `.env.*`, `.pem`, `id_*`, `.aws/`, `.ssh/`, and similar secrets.

I want to be clear; security has been a problem long before OpenClaw. OpenClaw knows this and uses it to its advantage. In fact I’d argue that their “warnings” are having the complete opposite intended effect on most people and actually signalling to them this shiny new tool is so powerful and will be so effective, you should actually be scared. Like a digital Heart Attack Grill.

That discussion gets even more revealing once you look at the edge cases people are actually worried about.

# The Exact Cases People Keep Running Into

Go to any coding agent’s repo and navigate to the issues or discussions page. You’ll [easily ](https://github.com/openai/codex/issues/4410)[find ](https://github.com/openai/codex/issues/5237)[developers ](https://github.com/openai/codex/issues/205)[complaining ](https://github.com/openai/codex/issues/2847)about the model accessing (or failing to) the wrong thing. The complaints are not vague. They’re repetitive and concrete.

People want a way to stop agents from reading secrets by default. They do not want `.env` files, private keys, SSH config, cloud credentials, or auth material to be one bad tool call away from model context.

Some users point out that even if gitignored files are not surfaced in one search flow, the agent may still be able to read them through direct file reads, directory listing, absolute paths, or shell commands like `cat`, `grep`, and `rg`. Others note that this is not just about secrets. It is also about deterministic boundaries. Teams want to know exactly what can be searched, listed, read, or sent to the model, and they want those rules to be shared across the repo, not buried in a human-readable instruction file that can be ignored or worked around.

That’s the real issue. Not that one path is visible here or one command works there, but that the behavior is inconsistent across surfaces. If search respects one rule, but direct reads do not, that’s not a consistent security model.

People want repo-local and user-level controls, because both matter. A company may want a deterministic repo policy, while an individual developer may want their own machine-wide defaults.

People want consistency across all access paths: search, direct reads, file listings, context snapshots, and shell commands. The issue is not solved if one of those respects exclusions while the others do not. Commenters in the Codex issue explicitly call out that current behavior is fragmented, with some flows respecting gitignore and others still reading or listing gitignored files directly.

People want hard controls, not etiquette. Telling the model “do not read secrets” in `AGENTS.md` is useful as guidance, but it is not the same thing as preventing the agent runtime from reading those paths at all. The users asking for this are not confused. They are asking for deterministic permissions, not vibes.

And maybe most importantly, people want the product’s stated boundaries to match reality. If the tool looks project-scoped in the UI but can still inspect things outside the repo under some conditions, that discrepancy is not cosmetic. It is the entire problem.

# The Proposed Solutions Are Not Exotic

This is not some unsolved research problem.

The proposed solutions are obvious, and developers have been proposing roughly the same ones for a while.

The first is a proper blacklist or denylist feature. Something like `.codexignore`, `.agentignore`, or an equivalent repo-level and global mechanism that says these files and directories are off limits. Not “please avoid them.” Not “prefer not to use them.” Off limits.

The second is a whitelist or allowlist model, which is even better. Instead of trying to enumerate every dangerous file, define the exact roots and patterns the agent is allowed to read. Everything else is invisible. This is cleaner, stronger, and much more realistic for real security work.

The third is sandbox-level enforcement. Some commenters are already pointing in this direction explicitly. If the runtime itself removes sensitive files from the agent’s execution context, then shell tools, direct reads, and indexing flows all inherit the same hard boundary. That is the difference between an instruction and an enforcement layer.

The fourth is consistency. Whatever the policy is, it has to be shared across every access path:

- search
- direct reads
- directory listing
- shell commands
- snapshots and indexing
- symlink resolution
- any context-building pipeline that can surface file contents to the model

Anything less just creates bypasses.

I want to point out that maintainers and discussion participants at OpenAI have been helpful and supportive, others have either hid behind [mOdEL oNLy gOoD iF iT cAn AcCEsS eVeRYtHinG](https://github.com/openai/codex/issues/5237#issuecomment-3603453289) mentality, or just been completely [wrong about how permissions work](https://github.com/openai/codex/issues/4410#issuecomment-3373191548). Performance and security is not a tradeoff. Do not allow yourself to believe that it is. You can have both.

# What happens when their cover is blown

Last month, [Claude Code’s codebase was leaked](https://www.cnbc.com/2026/03/31/anthropic-leak-claude-code-internal-source.html), and the demystification began. Anthropic confirmed that a packaging mistake (which I’m guessing was AI induced) in the public npm release exposed Claude Code’s source through a shipped source map, and multiple writeups converged on the same headline details: roughly half a million lines of TypeScript, a readable product roadmap, and an “[Undercover Mode](https://arstechnica.com/ai/2026/04/heres-what-that-claude-code-source-leak-reveals-about-anthropics-plans/)” that explicitly told Claude Code not to mention “Claude Code,” AI involvement, or co-author attribution in commit messages and PR text for public repositories. That is the part that bothers me the most. It is one thing for a company to avoid leaking internal codenames into public repos. It is another to ship instructions that amount to “do not blow your cover”. These are agents indeed. It’s a 0 accountability hack.

The other thing the leak showed is that these systems are much less magical than the marketing aura around them suggests. What spilled out was not evidence of some alien machine intelligence hiding behind the curtain. It used a lot of common modern programming patterns:

- prompts
- gating logic
- feature flags
- policy layers
- utility code
- tool wrappers
- regex (for profanity, to detect user frustration)
- client attestation
- common packages like Axios (rumoured to be the potential security flaw)
- and a lot of glue

Public analyses of the leaked code called out things like fake or decoy tool definitions for anti-distillation, compile-time feature gates, and a long list of hidden beta headers and unreleased modes. Even the code’s tone reportedly read like modern LLM-assisted code in places, with heavy commentary and verbose explanatory structure. That last part is an impression, not something I can prove from a benchmark, but it is hard to miss once you’ve seen and written enough vibe-coded codebases.

Loose boundaries already create the conditions for real damage: data leakage, silent exfiltration, regulatory exposure, and operational compromise. Samsung temporarily banned generative AI tools after employees pasted sensitive source code and internal meeting notes into ChatGPT. That was not a branding problem. That was proprietary data leaving the company through a workflow that felt harmless in the moment. Reuters reported similar warnings elsewhere as companies realized employees were feeding confidential material into chatbots because it was convenient.

<aside>
🤖 The clearest example so far is [EchoLeak](https://arxiv.org/abs/2509.10540), the 2025 Microsoft 365 Copilot vulnerability described as the first real-world zero-click prompt injection exploit against a production AI system. In effect, a remote attacker could use malicious content to make Copilot leak confidential data from a user’s context without the user doing anything. That is the kind of failure people should actually be worried about.
</aside>

Frankly it doesn’t matter what happens when these tools themselves have leaks. it’s only good for us users; turning a black box into a dark gray box. Apart from maybe being a speed bump on their road to an IPO later this year, the damage control department has already taken care of most of the bad PR from the leak. But what happens when these tools blow OUR cover? The most obvious risk is secret leakage. API keys, auth secrets, private certificates, SSH keys, cloud credentials, production configs, customer data. Everyone understands that part. But it can get worse.

# This has happened before

We've learned almost nothing from the rise of social media in the 2000s. People like to rewrite that era as if everyone is naïve, as if nobody saw the risks, as if the public sleepwalked into surveillance because the dangers were invisible. That's not what happened. Plenty of people understood, at least in broad terms, that these platforms were invasive, manipulative, addictive, identity-warping, and built on asymmetrical incentives. We joked about selling our souls for convenience long before that language became academic. We knew these companies wanted our data. We knew they were flattening private life into engagement loops. We knew the product wasn't really free. We just trusted it anyway, or more accurately, we accepted it anyway, because the utility was immediate and the consequences felt abstract, delayed, and socially distributed.

That's the part people keep missing. Adoption doesn't require ignorance. It just requires a situation where the upside is concrete and the downside is easy to defer. Social media let people publish themselves, find communities, stay in touch, build audiences, and participate in a new public square with almost no friction. The trade felt worth it, especially because everyone else was making the same trade at the same time. Once enough people are doing it, the question stops being "is this safe?" and becomes "can I really afford not to be here?”.

We are watching the same psychology play out again with agentic AI. Developers are not blind to the risks. They already understand that filesystem access, shell access, browser access, and third-party integrations create real security exposure. They know these tools can overreach, leak context, mis-handle secrets, and operate outside the neat conceptual boundaries the product implies. But the utility is so immediate that many people are moving forward anyway. Like I said in the opening; if you’re not using these tools, you’re stuck in yesterday. Agentic coding tools means faster coding, less repetition, less friction, more leverage. That is how dangerous infrastructure gets normalized: not because nobody saw the warning signs, but because the warning signs lost to convenience, speed, and status.

We are still early enough that there has not been a single universally recognized “Cambridge Analytica” moment yet, but the ingredients are already here. If AI systems keep getting broad access to files, messages, documents, and internal tools without hard enforcement around what they can actually see, the damage will not look like bad PR. It will look like source code leaks, customer-data exposure, compliance failures, and attackers using the AI layer itself as the easiest path into sensitive systems.

# What some people are doing about it

There are some real pioneers in the AI security space working on what they can.

## Containment

The most common move is containment. Instead of letting Claude Code or Codex run directly on the host, people are wrapping them in Docker, dev containers, or even microVM-style environments so the agent only sees a mounted project directory and not the rest of the machine. Take [this project](https://www.accidentalrebel.com/running-ai-agents-in-a-box.html) for example; run the agent in a box, mount only the repo, keep the environment disposable, and treat the host as something that should be protected from the tool, not trusted by it. That approach is increasingly mainstream. Docker has been explicitly positioning “sandboxed” agent workflows around the idea that developers want to run coding agents unattended while still having hard boundaries and easy reset paths, and Anthropic’s own guidance for higher-risk tool use recommends a dedicated VM or container with minimal privileges rather than giving the model direct access to a real workstation.

## **Disabling Ambient Network Access**

The second thing people are doing is cutting down ambient access. That means turning off network access by default, only re-enabling it when the agent actually needs docs or package downloads, and in some cases using egress controls or allowlists so the agent can only talk to a small set of approved domains. That is not paranoia anymore, it is becoming normal operational hygiene. OpenAI’s Codex docs say network is off by default in local sandboxed operation, and Anthropic’s computer-use guidance explicitly recommends limiting internet access to an allowlist of domains and avoiding access to sensitive data in the first place. In the wild, people are taking that further with their own firewall rules, lockdown toggles, and network-deny container setups because they do not want an agent that can browse, install, fetch, and exfiltrate by default. Your example’s outbound lockdown is a perfect illustration of that mindset.

## **Filesystem Scope & Permissions**

The third strategy is narrowing filesystem scope and permissions as much as the tool allows. With Codex, teams are leaning on sandbox mode, approval policies, workspace scoping, and team-level defaults; with Claude Code, they are increasingly relying on permission controls and deny-style rules around risky operations. OpenAI’s enterprise/admin guidance is clearly moving in that direction, with centralized defaults for approvals and sandboxing, while Anthropic’s release notes show ongoing work around deny rules and permission behavior, which tells you this has become a real product surface rather than an edge concern. But because built-in controls are still incomplete, many developers are adding their own outer layer: separate repos or worktrees for agent tasks, isolated shells, separate cloud dev boxes, and stripped-down environments that do not carry their normal SSH agent, browser session, cloud credentials, or full home directory along for the ride. [Matt Rickard’s workflow writeup](https://blog.matt-rickard.com/p/using-claude-code-from-anywhere) is a good example of this broader pattern: worktrees for task separation, Docker for sandboxing, and remote instances for cleaner isolation.

The important part is that none of this is theoretical anymore. People are already behaving as if coding agents are semi-trusted operators that need containment, least privilege, segmented environments, revocable credentials, and explicit blast-radius control. They are not waiting for vendors to fully solve it. They are building wrappers, running agents in containers, moving sensitive work to separate machines, stripping auth material out of the environment, and treating network access as something that should be granted temporarily rather than assumed. That is where the market already is. The tools are still catching up to the attitudes of the people using them.

# What Needs To Happen

I am far from perfect. I use the same handful of passwords across all my accounts. I still over-provision access with my API keys (and don’t rotate them). But I’m trying to do better. But like recycling, it’s wrong to push the responsibility to the user; institutions need to do better too. The industry needs to stop treating security for coding agents as a future premium feature and start treating it as a prerequisite.

If developers cannot say with confidence what an agent can and cannot access, then the agent is not going to get adopted in serious environments. Security-conscious teams do not reject these tools because they hate automation. They reject them because “probably scoped” is not a control surface.

And once that trust is gone, it spreads outward.

A company does not just conclude “maybe don’t use Codex on this repo.” It concludes:

- maybe these tools are not ready for sensitive work
- maybe agent boundaries are mostly theater
- maybe the vendor does not understand how enterprise security actually works
- maybe we should use a competitor with deterministic deny rules instead

That is not hypothetical. You can already see that frustration in these issue threads. People are not asking for moonshots. They are asking for the kind of filesystem policy that any security-minded team would expect before letting an autonomous tool operate near secrets.

And there is another risk that gets less attention: boundary confusion trains developers into bad habits. If the defaults are fuzzy, people start relying on convention, naming, or documentation instead of actual enforcement. They move secrets around manually. They hope `.gitignore` is enough. They trust that a project root in the UI means a project boundary in practice. That is exactly how subtle leaks happen. Ideally, we’d have:

- deterministic path policies.
- actual allowlists and denylists.
- sandbox-enforced boundaries.
- consistent behavior across every read surface.
- no more pretending that a polite instruction file is equivalent to access control.
- clear language that explains the risks

If a tool is project-scoped, it should be project-scoped in a way that survives shell commands, absolute paths, symlinks, indexing, and direct reads. If sensitive files are excluded, they should be excluded everywhere. If the model is not allowed to see something, the runtime should make that impossible, not merely discouraged. This feels like the minimum bar for software that’s supposed to operate with agency.

I’m not confident any of this will actually happen. The industry will likely see it as a feature and not a bug. Determinism is overrated; the very nature of AI is probabilistic. In fact why bother fixing a problem when you can milk it for money? I’m bullish on “AI Insurance” to cover erroneous inference, output liability, data leaks, breaches of trade secrets, etc. I think we’ll be seeing a lot more of these in the coming years.

As long as this remains unsolved, the people building and buying these tools are taking security more seriously than some of the tools themselves. Who knows, there might be something to build here…