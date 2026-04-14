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

POST_TEMPLATE = (TEMPLATES_DIR / "blog-post.html").read_text(encoding="utf-8")
INDEX_TEMPLATE = (TEMPLATES_DIR / "blog-index.html").read_text(encoding="utf-8")

FRONTMATTER_RE = re.compile(r"^---\s*\n(.*?)\n---\s*\n(.*)$", re.DOTALL)
INLINE_CODE_RE = re.compile(r"`([^`]+)`")
LINK_RE = re.compile(r"\[([^\]]+)\]\(([^)]+)\)")
BOLD_RE = re.compile(r"\*\*(.+?)\*\*")
ITALIC_RE = re.compile(r"(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)")


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


def parse_frontmatter(raw_text: str) -> tuple[dict[str, Any], str]:
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
                blocks.append("\n".join(aside_buffer))
                aside_buffer = []
                in_aside_block = False
            continue

        if in_aside_block:
            aside_buffer.append(line)
            if "</aside>" in stripped:
                blocks.append("\n".join(aside_buffer))
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

def load_posts() -> list[Post]:
    posts: list[Post] = []

    for path in sorted(CONTENT_DIR.glob("*.md")):
        raw_text = path.read_text(encoding="utf-8")
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

    tags_html = ""
    if post.tags:
        rendered_tags = "".join(f'<span class="blog-tag">{html.escape(tag)}</span>' for tag in post.tags)
        tags_html = f'<span class="blog-tags">{rendered_tags}</span>'

    html_output = POST_TEMPLATE
    replacements = {
        "{{title}}": html.escape(post.title),
        "{{excerpt}}": html.escape(post.excerpt),
        "{{date_iso}}": post.date,
        "{{date_display}}": post.date_display,
        "{{tags_html}}": tags_html,
        "{{content}}": post.body_html,
        "{{root_path}}": "../",
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
