from bs4 import BeautifulSoup

def extract_content(html: str):
    soup = BeautifulSoup(html, "html.parser")

    for tag in soup(["script", "style", "noscript"]):
        tag.decompose()

    title = soup.title.string.strip() if soup.title else None

    description_tag = soup.find("meta", attrs={"name": "description"})
    description = None
    if description_tag and description_tag.get("content"):
        description = description_tag["content"].strip()

    headings = [h.get_text(strip=True) for h in soup.find_all(["h1","h2","h3","h4","h5","h6"])]

    text = soup.get_text(separator=" ", strip=True)
    word_count = len(text.split())

    return {
        "title": title,
        "description": description,
        "headings": headings,
        "text": text,
        "word_count": word_count
    }
