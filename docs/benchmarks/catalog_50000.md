# 50,000-Book SQL Benchmark

Synthetic, in-memory SQLite 3.53.1 through Python sqlite3; 50,000 books, 5,000 authors, 500 publishers, 100 categories and 100,000 author-book links. Seven warm runs per case. Each run includes matching count and the first 20 book IDs. Results and first-page IDs were checked against the prior query shape.

| Query case | Matches | Previous median (ms) | New median (ms) | New maximum (ms) |
| --- | ---: | ---: | ---: | ---: |
| rare_title | 50 | 107.23 | 17.68 | 18.57 |
| author_lookup | 220 | 80.49 | 14.06 | 15.24 |
| common_bengali | 49,950 | 4.09 | 5.19 | 5.86 |
| two_terms | 50 | 109.16 | 18.11 | 20.67 |
| exact_accession | 1 | — | <0.01 | 0.07 |
| exact_call | 1,000 | — | 0.03 | 0.06 |

The selective title, author and two-term cases improved markedly in this dataset. A very broad Bengali term was slightly slower than the previous short-circuiting query, while remaining around 5 ms in this warm SQL-only sample. Exact identifier queries used covering indexes. This is not a claim that every query became faster.

## Scope and limitations

This is equivalent SQL-shape testing, not an executed Laravel/MySQL or browser end-to-end benchmark. It excludes PHP boot, relation hydration, rendering, cache hits, network latency, disk-cold reads and concurrent load. MySQL plans, hardware, data distribution, storage and collation can change timings. No fixed response-time SLA is promised.

Run `python catalog_50000_sqlite.py` in a scratch directory to reproduce the synthetic comparison; it creates only an in-memory SQLite database and writes a result JSON file. It never touches the application database.

## Measure the actual WAMP/MySQL database

Choose a term present in your collection:

```bat
php artisan library:benchmark-catalog --query="History" --runs=7
php artisan library:benchmark-catalog --accession="000123" --match=exact --runs=7
php artisan library:benchmark-catalog --call="800.02" --match=prefix --runs=7 --json
```

The application command measures count plus the first 20 IDs through the real BookSearch query service. It reports the current book count and database driver, does not create/change catalog data, and bypasses result caching. It also excludes full-page rendering and network time.
