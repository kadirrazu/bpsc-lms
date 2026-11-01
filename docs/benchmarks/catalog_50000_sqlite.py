import sqlite3,time,statistics,json
from pathlib import Path
conn=sqlite3.connect(':memory:');conn.executescript('''
CREATE TABLE books(id INTEGER PRIMARY KEY,accession_number TEXT,title_bn TEXT,title_en TEXT,call_number TEXT,shelf_number TEXT,publisher_id INTEGER,deleted_at TEXT);
CREATE TABLE authors(id INTEGER PRIMARY KEY,title_bn TEXT,title_en TEXT);
CREATE TABLE categories(id INTEGER PRIMARY KEY,title_bn TEXT,title_en TEXT);
CREATE TABLE publishers(id INTEGER PRIMARY KEY,title_bn TEXT,title_en TEXT);
CREATE TABLE book_authors(book_id INTEGER,author_id INTEGER,PRIMARY KEY(book_id,author_id));
CREATE TABLE book_categories(book_id INTEGER,category_id INTEGER,PRIMARY KEY(book_id,category_id));
CREATE INDEX ba_author ON book_authors(author_id);CREATE INDEX bc_category ON book_categories(category_id);
CREATE INDEX live_accession ON books(deleted_at,accession_number);CREATE INDEX live_call ON books(deleted_at,call_number);CREATE INDEX live_pub ON books(deleted_at,publisher_id,id);
''')
conn.executemany('INSERT INTO authors VALUES(?,?,?)',[(i,f'লেখক {i}',f'Writer{i}') for i in range(1,5001)])
conn.executemany('INSERT INTO categories VALUES(?,?,?)',[(i,f'বিষয় {i}',f'Category{i}') for i in range(1,101)])
conn.executemany('INSERT INTO publishers VALUES(?,?,?)',[(i,f'প্রকাশক {i}',f'Publisher{i}') for i in range(1,501)])
conn.executemany('INSERT INTO books VALUES(?,?,?,?,?,?,?,NULL)',[(i,f'{i:06}',('বিজ্ঞান' if i%1000==0 else 'বাংলা')+f' বই {i}',('Quantum' if i%1000==0 else 'Knowledge')+f' Reading {i}','800.02.MK' if i%50==0 else f'{i%1000}.A',f'S-{i%200}',(i%500)+1) for i in range(1,50001)])
conn.executemany('INSERT INTO book_authors VALUES(?,?)',[(i,(i%5000)+1) for i in range(1,50001)])
conn.executemany('INSERT OR IGNORE INTO book_authors VALUES(?,?)',[(i,((i+100)%5000)+1) for i in range(1,50001)])
conn.executemany('INSERT INTO book_categories VALUES(?,?)',[(i,(i%100)+1) for i in range(1,50001)])
conn.execute('ANALYZE')

def new_clause(term):
 p='%'+term+'%'
 sql="""(books.title_bn LIKE ? ESCAPE '!' OR books.title_en LIKE ? ESCAPE '!' OR books.accession_number LIKE ? ESCAPE '!' OR books.call_number LIKE ? ESCAPE '!' OR books.shelf_number LIKE ? ESCAPE '!'
 OR books.id IN (SELECT book_id FROM book_authors WHERE author_id IN (SELECT id FROM authors WHERE title_bn LIKE ? ESCAPE '!' OR title_en LIKE ? ESCAPE '!'))
 OR books.publisher_id IN (SELECT id FROM publishers WHERE title_bn LIKE ? ESCAPE '!' OR title_en LIKE ? ESCAPE '!')
 OR books.id IN (SELECT book_id FROM book_categories WHERE category_id IN (SELECT id FROM categories WHERE title_bn LIKE ? ESCAPE '!' OR title_en LIKE ? ESCAPE '!')))"""
 return sql,[p]*11


def legacy_clause(term):
 p='%'+term+'%'
 sql='''(books.title_bn LIKE ? OR books.title_en LIKE ? OR books.accession_number LIKE ? OR books.call_number LIKE ? OR books.shelf_number LIKE ?
 OR EXISTS (SELECT 1 FROM authors JOIN book_authors ON authors.id=book_authors.author_id WHERE book_authors.book_id=books.id AND (authors.title_bn LIKE ? OR authors.title_en LIKE ?))
 OR EXISTS (SELECT 1 FROM publishers WHERE publishers.id=books.publisher_id AND (publishers.title_bn LIKE ? OR publishers.title_en LIKE ?))
 OR EXISTS (SELECT 1 FROM categories JOIN book_categories ON categories.id=book_categories.category_id WHERE book_categories.book_id=books.id AND (categories.title_bn LIKE ? OR categories.title_en LIKE ?)))'''
 return sql,[p]*11

def measure(where,args):
 count_sql='SELECT COUNT(*) FROM books WHERE deleted_at IS NULL AND '+where
 page_sql='SELECT id FROM books WHERE deleted_at IS NULL AND '+where+' ORDER BY id DESC LIMIT 20'
 timings=[];total=0;ids=[]
 for _ in range(7):
  start=time.perf_counter();total=conn.execute(count_sql,args).fetchone()[0];ids=[x[0] for x in conn.execute(page_sql,args).fetchall()];timings.append((time.perf_counter()-start)*1000)
 return {'total':total,'page_size':len(ids),'median_ms':round(statistics.median(timings),2),'max_ms':round(max(timings),2),'ids':ids}
results=[]
for label,term in [('rare_title','Quantum'),('author_lookup','Writer412'),('common_bengali','বাংলা'),('two_terms','Quantum বিজ্ঞান')]:
 entries={}
 for name,fn in [('legacy',legacy_clause),('optimized',new_clause)]:
  clauses=[];args=[]
  for token in term.split():
   sql,params=fn(token);clauses.append(sql);args+=params
  entries[name]=measure(' AND '.join(clauses),args)
 assert entries['legacy']['total']==entries['optimized']['total'] and entries['legacy']['ids']==entries['optimized']['ids']
 for value in entries.values():value.pop('ids')
 results.append({'case':label,**entries})
for name,where,params in [('exact_accession','accession_number = ?',['000123']),('exact_call','call_number = ?',['800.02.MK'])]:
 value=measure(where,params);value.pop('ids');value['plan']=[row[3] for row in conn.execute('EXPLAIN QUERY PLAN SELECT id FROM books WHERE deleted_at IS NULL AND '+where+' ORDER BY id DESC LIMIT 20',params)]
 results.append({'case':name,'optimized':value})
report={'engine':'Python sqlite3, in-memory, warm-run SQL only','sqlite_version':sqlite3.sqlite_version,'books':50000,'authors':5000,'publishers':500,'categories':100,'runs_per_case':7,'results':results,'limitation':'Equivalent SQL shape only; excludes PHP/Laravel/MySQL, request rendering, cache hit and network time.'}
Path('benchmark-v050.json').write_text(json.dumps(report,ensure_ascii=False,indent=2));print(json.dumps(report,ensure_ascii=False,indent=2))
