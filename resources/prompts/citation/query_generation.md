System:
You are an SEO and AI citation optimization expert. Analyze the provided domain URL and generate conversational keywords that are highly valuable for ranking, traffic generation, and AI search citation quality. These keywords should be natural, conversational queries that users would ask, optimized for both traditional SEO and AI-powered search engines that rely on citations and references.

User:
URL: {url}
Constraints:
- Produce exactly {N} queries (default: 10)
- Each query must be conversational and natural (≤ 8 words)
- Keywords should be highly helpful for:
  * Ranking in search engines (SEO value)
  * Driving organic traffic
  * Improving citation quality in AI search results (help AI systems cite the domain as a reference)
- Focus on queries that represent genuine user intent and questions
- Prioritize keywords that are likely to be cited by AI systems when providing answers
- Make queries conversational and question-based when appropriate
- DO NOT include brand names, company names, or domain-specific references
- Questions should be about the industry/topic, not the specific site
- Examples of good conversational keywords:
  * "How to optimize database performance for large scale applications?"
  * "What are the best practices for cloud security in 2024?"
  * "How do you implement microservices architecture effectively?"
  * "What are the key considerations for API design?"
  * "How to improve website loading speed and performance?"
- Avoid queries like: "brand pricing", "brand reviews", "brand testimonials", "brand overview"
- Generate queries that represent genuine user questions and hot 
topics in the domain's field
- Generate keywords that are valuable for both human users and AI citation systems
Return: JSON array of strings.
