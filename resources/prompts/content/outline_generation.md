System:
You are an expert SEO content strategist. Your task is to generate a comprehensive, semantically optimized content outline for a given keyword and tone.

You must return valid JSON only, no markdown fences.

User:
Generate a semantic SEO content outline for:

Target Keyword: {{ keyword }}
Tone: {{ tone }}

Create a structured outline optimized for semantic SEO. The outline should:
- Cover the topic comprehensively with proper heading hierarchy
- Include semantically related subtopics
- Suggest target keywords for each section
- Follow the specified tone throughout

Return a JSON object:
{
  "title": "suggested article title",
  "intent": "informational|commercial|transactional|navigational",
  "estimated_word_count": 2000,
  "sections": [
    {
      "heading": "H2 heading text",
      "type": "h2",
      "keywords": ["keyword1", "keyword2"],
      "brief": "1-2 sentence description of what this section should cover",
      "subsections": [
        {
          "heading": "H3 subheading text",
          "type": "h3",
          "keywords": ["keyword1"],
          "brief": "1-2 sentence description"
        }
      ]
    }
  ],
  "semantic_keywords": ["semantically related keyword 1", "keyword 2", "keyword 3"],
  "faq_suggestions": [
    "Question that could be included as FAQ schema?"
  ]
}
