from __future__ import annotations

import html
import json
import re
import shutil
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
CONTENT_DIR = ROOT / "content" / "blog"
TEMPLATES_DIR = ROOT / "templates"
OUTPUT_DIR = ROOT / "blog"
DATA_DIR = ROOT / "data"
SITE_URL = "https://flawnson.com"
AUTHOR_NAME = "Flawnson Tong"
AUTHOR_URL = f"{SITE_URL}/#person"
PUBLISHER_NAME = "Flawnson"
PUBLISHER_LOGO_URL = f"{SITE_URL}/assets/images/Favicon.png"
DEFAULT_BLOG_IMAGE = f"{SITE_URL}/assets/images/profile%202.jpg"

POST_TEMPLATE = (TEMPLATES_DIR / "blog-post.html").read_text(encoding="utf-8")
INDEX_TEMPLATE = (TEMPLATES_DIR / "blog-index.html").read_text(encoding="utf-8")

FRONTMATTER_RE = re.compile(r"^---\s*\r?\n(.*?)\r?\n---\s*\r?\n(.*)$", re.DOTALL)
INLINE_CODE_RE = re.compile(r"`([^`]+)`")
LINK_RE = re.compile(r"\[([^\]]+)\]\(([^)]+)\)")
BOLD_RE = re.compile(r"\*\*(.+?)\*\*")
ITALIC_RE = re.compile(r"(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)")
TOC_HEADING_RE = re.compile(r"<h([1-3])>(.*?)</h\1>")
HTML_TAG_RE = re.compile(r"<[^>]+>")


@dataclass
class Post:
    title: str
    slug: str
    date: str
    excerpt: str
    tags: list[str]
    published: bool
    body_markdown: str
    body_html: str

    @property
    def date_obj(self) -> datetime:
        return datetime.strptime(self.date, "%Y-%m-%d")

    @property
    def date_display(self) -> str:
        return self.date_obj.strftime("%B %d, %Y")

    @property
    def date_schema(self) -> str:
        return self.date_obj.strftime("%Y-%m-%d")


def parse_frontmatter(raw_text: str) -> tuple[dict[str, Any], str]:
    raw_text = raw_text.lstrip("\ufeff").lstrip()

    match = FRONTMATTER_RE.match(raw_text)
    if not match:
        raise ValueError("Missing or invalid frontmatter block.")

    frontmatter_text, body = match.groups()
    data: dict[str, Any] = {}

    for line in frontmatter_text.splitlines():
        if not line.strip() or line.strip().startswith("#"):
            continue
        if ":" not in line:
            raise ValueError(f"Invalid frontmatter line: {line}")
        key, value = line.split(":", 1)
        key = key.strip()
        value = value.strip()

        if value.lower() in {"true", "false"}:
            data[key] = value.lower() == "true"
        elif value.startswith("[") and value.endswith("]"):
            inner = value[1:-1].strip()
            data[key] = [] if not inner else [item.strip().strip('"\'') for item in inner.split(",")]
        else:
            data[key] = value.strip('"\'')
    return data, body.strip() + "\n"


def apply_inline_formatting(text: str) -> str:
    escaped = html.escape(text)
    escaped = LINK_RE.sub(r'<a href="\2">\1</a>', escaped)
    escaped = INLINE_CODE_RE.sub(r"<code>\1</code>", escaped)
    escaped = BOLD_RE.sub(r"<strong>\1</strong>", escaped)
    escaped = ITALIC_RE.sub(r"<em>\1</em>", escaped)
    return escaped


def render_aside(lines: list[str]) -> str:
    inner_lines: list[str] = []

    for line in lines:
        stripped = line.strip()
        if stripped.startswith("<aside") or stripped == "</aside>":
            continue
        inner_lines.append(line)

    inner_markdown = "\n".join(inner_lines).strip()
    inner_html = markdown_to_html(inner_markdown)
    return f"<aside>\n{inner_html}\n</aside>"


def markdown_to_html(markdown: str) -> str:
    lines = markdown.splitlines()
    blocks: list[str] = []
    paragraph_buffer: list[str] = []
    list_buffer: list[str] = []
    ordered_list_buffer: list[str] = []
    in_code_block = False
    code_buffer: list[str] = []
    in_aside_block = False
    aside_buffer: list[str] = []

    def flush_paragraph() -> None:
        nonlocal paragraph_buffer
        if paragraph_buffer:
            text = " ".join(line.strip() for line in paragraph_buffer)
            blocks.append(f"<p>{apply_inline_formatting(text)}</p>")
            paragraph_buffer = []

    def flush_list() -> None:
        nonlocal list_buffer
        if list_buffer:
            items = "".join(f"<li>{apply_inline_formatting(item)}</li>" for item in list_buffer)
            blocks.append(f"<ul>{items}</ul>")
            list_buffer = []

    def flush_ordered_list() -> None:
        nonlocal ordered_list_buffer
        if ordered_list_buffer:
            items = "".join(f"<li>{apply_inline_formatting(item)}</li>" for item in ordered_list_buffer)
            blocks.append(f"<ol>{items}</ol>")
            ordered_list_buffer = []

    for line in lines:
        stripped = line.rstrip()

        if stripped.startswith("```"):
            flush_paragraph()
            flush_list()
            flush_ordered_list()
            if in_code_block:
                code_html = html.escape("\n".join(code_buffer))
                blocks.append(f"<pre><code>{code_html}</code></pre>")
                code_buffer = []
                in_code_block = False
            else:
                in_code_block = True
            continue

        if in_code_block:
            code_buffer.append(line)
            continue

        if stripped.startswith("<aside"):
            flush_paragraph()
            flush_list()
            flush_ordered_list()
            in_aside_block = True
            aside_buffer = [line]
            if "</aside>" in stripped:
                blocks.append(render_aside(aside_buffer))
                aside_buffer = []
                in_aside_block = False
            continue

        if in_aside_block:
            aside_buffer.append(line)
            if "</aside>" in stripped:
                blocks.append(render_aside(aside_buffer))
                aside_buffer = []
                in_aside_block = False
            continue

        if not stripped:
            flush_paragraph()
            flush_list()
            flush_ordered_list()
            continue

        if stripped.startswith("#### "):
            flush_paragraph(); flush_list(); flush_ordered_list()
            blocks.append(f"<h4>{apply_inline_formatting(stripped[5:])}</h4>")
            continue
        if stripped.startswith("### "):
            flush_paragraph(); flush_list(); flush_ordered_list()
            blocks.append(f"<h3>{apply_inline_formatting(stripped[4:])}</h3>")
            continue
        if stripped.startswith("## "):
            flush_paragraph(); flush_list(); flush_ordered_list()
            blocks.append(f"<h2>{apply_inline_formatting(stripped[3:])}</h2>")
            continue
        if stripped.startswith("# "):
            flush_paragraph(); flush_list(); flush_ordered_list()
            blocks.append(f"<h1>{apply_inline_formatting(stripped[2:])}</h1>")
            continue

        if stripped.startswith("> "):
            flush_paragraph(); flush_list(); flush_ordered_list()
            blocks.append(f"<blockquote><p>{apply_inline_formatting(stripped[2:])}</p></blockquote>")
            continue

        unordered_match = re.match(r"^[-*]\s+(.*)$", stripped)
        if unordered_match:
            flush_paragraph(); flush_ordered_list()
            list_buffer.append(unordered_match.group(1))
            continue

        ordered_match = re.match(r"^\d+\.\s+(.*)$", stripped)
        if ordered_match:
            flush_paragraph(); flush_list()
            ordered_list_buffer.append(ordered_match.group(1))
            continue

        paragraph_buffer.append(stripped)

    flush_paragraph()
    flush_list()
    flush_ordered_list()

    return "\n".join(blocks)


def slugify_heading(text: str) -> str:
    slug = html.unescape(text).lower()
    slug = re.sub(r"[^a-z0-9\s-]", "", slug)
    slug = re.sub(r"\s+", "-", slug).strip("-")
    slug = re.sub(r"-{2,}", "-", slug)
    return slug or "section"


def inject_heading_ids_and_collect(content_html: str) -> tuple[str, list[tuple[int, str, str]]]:
    headings: list[tuple[int, str, str]] = []
    slug_counts: dict[str, int] = {}

    def replacer(match: re.Match[str]) -> str:
        level = int(match.group(1))
        heading_html = match.group(2)
        heading_text = html.unescape(HTML_TAG_RE.sub("", heading_html)).strip()

        base_slug = slugify_heading(heading_text)
        slug_counts[base_slug] = slug_counts.get(base_slug, 0) + 1
        heading_id = base_slug if slug_counts[base_slug] == 1 else f"{base_slug}-{slug_counts[base_slug]}"

        headings.append((level, heading_text, heading_id))
        return f'<h{level} id="{heading_id}">{heading_html}</h{level}>'

    updated_html = TOC_HEADING_RE.sub(replacer, content_html)
    return updated_html, headings


def render_toc(headings: list[tuple[int, str, str]]) -> str:
    if not headings:
        return ""

    items = []
    for level, title, heading_id in headings:
        items.append(
            f'<li class="blog-toc-item blog-toc-level-{level}"><a href="#{html.escape(heading_id)}">{html.escape(title)}</a></li>'
        )

    items_html = "\n".join(items)
    return (
        '<nav class="blog-toc" aria-label="Table of contents">\n'
        f'  <ol class="blog-toc-list">\n{items_html}\n  </ol>\n'
        "</nav>"
    )

def load_posts() -> list[Post]:
    posts: list[Post] = []

    for path in sorted(CONTENT_DIR.glob("*.md")):
        raw_text = path.read_text(encoding="utf-8-sig")
        frontmatter, body = parse_frontmatter(raw_text)

        required = ["title", "slug", "date", "excerpt", "published"]
        for field in required:
            if field not in frontmatter:
                raise ValueError(f"{path.name} is missing required field: {field}")

        post = Post(
            title=frontmatter["title"],
            slug=frontmatter["slug"],
            date=frontmatter["date"],
            excerpt=frontmatter["excerpt"],
            tags=frontmatter.get("tags", []),
            published=bool(frontmatter["published"]),
            body_markdown=body,
            body_html=markdown_to_html(body),
        )

        if post.published:
            posts.append(post)

    posts.sort(key=lambda p: p.date_obj, reverse=True)
    return posts


def build_post(post: Post) -> None:
    output_dir = OUTPUT_DIR / post.slug
    output_dir.mkdir(parents=True, exist_ok=True)
    post_url = f"{SITE_URL}/blog/{post.slug}/"

    tags_html = ""
    if post.tags:
        rendered_tags = "".join(f'<span class="blog-tag">{html.escape(tag)}</span>' for tag in post.tags)
        tags_html = f'<span class="blog-tags">{rendered_tags}</span>'

    content_html, headings = inject_heading_ids_and_collect(post.body_html)
    toc_html = render_toc(headings)
    content_html = content_html.replace("<p>{{toc}}</p>", toc_html)
    content_html = content_html.replace("{{toc}}", toc_html)

    structured_data = {
        "@context": "https://schema.org",
        "@graph": [
            {
                "@type": "BlogPosting",
                "@id": f"{post_url}#article",
                "headline": post.title,
                "description": post.excerpt,
                "image": [DEFAULT_BLOG_IMAGE],
                "author": {
                    "@type": "Person",
                    "name": AUTHOR_NAME,
                    "url": AUTHOR_URL,
                },
                "publisher": {
                    "@type": "Organization",
                    "name": PUBLISHER_NAME,
                    "url": SITE_URL,
                    "logo": {
                        "@type": "ImageObject",
                        "url": PUBLISHER_LOGO_URL,
                    },
                },
                "datePublished": post.date_schema,
                "dateModified": post.date_schema,
                "mainEntityOfPage": post_url,
                "keywords": post.tags,
            },
            {
                "@type": "BreadcrumbList",
                "itemListElement": [
                    {
                        "@type": "ListItem",
                        "position": 1,
                        "name": "Home",
                        "item": f"{SITE_URL}/",
                    },
                    {
                        "@type": "ListItem",
                        "position": 2,
                        "name": "Blog",
                        "item": f"{SITE_URL}/blog/",
                    },
                    {
                        "@type": "ListItem",
                        "position": 3,
                        "name": post.title,
                        "item": post_url,
                    },
                ],
            },
            {
                "@type": "Organization",
                "@id": f"{SITE_URL}/#organization",
                "name": PUBLISHER_NAME,
                "url": f"{SITE_URL}/",
                "logo": {
                    "@type": "ImageObject",
                    "url": PUBLISHER_LOGO_URL,
                },
            },
        ],
    }

    html_output = POST_TEMPLATE
    replacements = {
        "{{title}}": html.escape(post.title),
        "{{excerpt}}": html.escape(post.excerpt),
        "{{date_iso}}": post.date,
        "{{date_display}}": post.date_display,
        "{{tags_html}}": tags_html,
        "{{content}}": content_html,
        "{{root_path}}": "../",
        "{{canonical_url}}": post_url,
        "{{structured_data_json}}": json.dumps(structured_data, ensure_ascii=False),
    }

    for key, value in replacements.items():
        html_output = html_output.replace(key, value)

    (output_dir / "index.html").write_text(html_output, encoding="utf-8")


def build_index(posts: list[Post]) -> None:
    cards = []
    for post in posts:
        tags = ""
        if post.tags:
            tags = " · " + ", ".join(html.escape(tag) for tag in post.tags)

        cards.append(
            f'''<a class="blog-card" href="./{html.escape(post.slug)}/">\n'''
            f'''  <h2 class="blog-card-title">{html.escape(post.title)}</h2>\n'''
            f'''  <div class="blog-card-meta">{post.date_display}{tags}</div>\n'''
            f'''  <p class="blog-card-excerpt">{html.escape(post.excerpt)}</p>\n'''
            f'''</a>'''
        )

    html_output = INDEX_TEMPLATE.replace("{{posts}}", "\n".join(cards))
    html_output = html_output.replace("{{post_count}}", str(len(posts)))
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    (OUTPUT_DIR / "index.html").write_text(html_output, encoding="utf-8")


def write_metadata(posts: list[Post]) -> None:
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    payload = [
        {
            "title": post.title,
            "slug": post.slug,
            "date": post.date,
            "excerpt": post.excerpt,
            "tags": post.tags,
        }
        for post in posts
    ]
    (DATA_DIR / "blog.json").write_text(json.dumps(payload, indent=2), encoding="utf-8")


def clean_output() -> None:
    if OUTPUT_DIR.exists():
        for item in OUTPUT_DIR.iterdir():
            if item.is_dir():
                shutil.rmtree(item)
            else:
                item.unlink()
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)


def main() -> None:
    posts = load_posts()
    clean_output()
    for post in posts:
        build_post(post)
    build_index(posts)
    write_metadata(posts)
    print(f"Built {len(posts)} post(s) into {OUTPUT_DIR}")


if __name__ == "__main__":
    main()
