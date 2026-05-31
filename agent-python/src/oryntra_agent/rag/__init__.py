"""Knowledge-base ingestion: extract text (lib with vision-LLM fallback),
chunk, and embed documents for the Laravel-owned pgvector store.

Python is stateless here — it never touches Postgres or object storage for the
business tables. It downloads the file, returns chunks and vectors, and Laravel
persists them.
"""
