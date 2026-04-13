from bs4 import BeautifulSoup

from app.core.pipeline_log import log_step


def extract_content(html: str):
    log_step("03_extract_start", html_chars=len(html))
    soup = BeautifulSoup(html, "html.parser")

    for tag in soup(["script", "style", "noscript"]):
        tag.decompose()

    title = None
    if soup.title and soup.title.string:
        title = soup.title.string.strip()

    description_tag = soup.find("meta", attrs={"name": "description"})
    description = None
    if description_tag and description_tag.get("content"):
        description = description_tag["content"].strip()

    headings = [h.get_text(strip=True) for h in soup.find_all(["h1","h2","h3","h4","h5","h6"])]

    text = soup.get_text(separator=" ", strip=True)
    word_count = len(text.split())

    log_step(
        "03_extract_done",
        word_count=word_count,
        headings_count=len(headings),
        has_title=bool(title),
        has_description=bool(description),
        text_chars=len(text),
    )
    return {
        "title": title,
        "description": description,
        "headings": headings,
        "text": text,
        "word_count": word_count,
    }
