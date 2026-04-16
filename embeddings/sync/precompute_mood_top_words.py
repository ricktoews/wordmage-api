import json
import numpy as np
import mysql.connector

MODEL = "text-embedding-3-small"
TOP_K = 200
WORD_BATCH = 300  # how many word embeddings to parse at a time

with open("/etc/wordmage/mysql.json", "r", encoding="utf-8") as f:
    db_config = json.load(f)

conn = mysql.connector.connect(**db_config)
cur = conn.cursor(dictionary=True)

# ---- Load moods (23) and normalize ----
cur.execute(
    """
    SELECT m.id AS mood_id, m.slug, m.label, me.embedding_json
    FROM moods m
    JOIN mood_embeddings me
      ON me.mood_id = m.id AND me.model = %s
    ORDER BY m.id
    """,
    (MODEL,),
)
mood_rows = cur.fetchall()
if not mood_rows:
    raise RuntimeError(f"No mood embeddings found for model '{MODEL}'")

moods = []
for m in mood_rows:
    v = np.array(json.loads(m["embedding_json"]), dtype=np.float32)
    n = np.linalg.norm(v)
    if n == 0:
        continue
    moods.append({
        "mood_id": m["mood_id"],
        "slug": m["slug"],
        "label": m["label"],
        "vec": v / n
    })

print(f"Loaded {len(moods)} mood vectors.")

# For each mood, maintain a list of (score, word_id) candidates
# We'll keep it as a Python list and trim to TOP_K periodically.
tops = {m["mood_id"]: [] for m in moods}

# ---- Clear existing precompute rows for this model ----
cur.execute("DELETE FROM mood_top_words WHERE model = %s", (MODEL,))
conn.commit()
print("Cleared existing mood_top_words rows for this model.")

# ---- Stream words in batches ----
offset = 0
total_processed = 0

while True:
    cur.execute(
        f"""
        SELECT wp.id AS word_id, we.embedding_json
        FROM word_pool wp
        JOIN word_embeddings we
          ON we.word_id = wp.id AND we.model = %s
        ORDER BY wp.id
        LIMIT {WORD_BATCH} OFFSET {offset}
        """,
        (MODEL,),
    )
    rows = cur.fetchall()
    if not rows:
        break

    # parse this batch into arrays
    word_ids = np.array([r["word_id"] for r in rows], dtype=np.int32)
    word_vecs = np.array([json.loads(r["embedding_json"]) for r in rows], dtype=np.float32)

    # normalize batch vectors
    norms = np.linalg.norm(word_vecs, axis=1, keepdims=True)
    norms[norms == 0] = 1.0
    word_vecs = word_vecs / norms

    # score against each mood and append candidates
    for m in moods:
        scores = word_vecs @ m["vec"]  # (batch,)
        # grab top TOP_K within this batch to reduce work
        k = min(TOP_K, scores.shape[0])
        idx = np.argpartition(scores, -k)[-k:]
        for j in idx:
            tops[m["mood_id"]].append((float(scores[j]), int(word_ids[j])))

        # trim to TOP_K globally occasionally
        if len(tops[m["mood_id"]]) > TOP_K * 5:
            tops[m["mood_id"]] = sorted(tops[m["mood_id"]], reverse=True)[:TOP_K]

    total_processed += len(rows)
    offset += WORD_BATCH
    print(f"Processed {total_processed} words...")

# final trim
for mood_id in tops:
    tops[mood_id] = sorted(tops[mood_id], reverse=True)[:TOP_K]

print("Scoring complete. Writing results to DB...")

insert_sql = """
INSERT INTO mood_top_words (mood_id, model, rank_num, word_id, score)
VALUES (%s, %s, %s, %s, %s)
"""

for m in moods:
    mood_id = m["mood_id"]
    for rank_num, (score, word_id) in enumerate(tops[mood_id], start=1):
        cur.execute(insert_sql, (mood_id, MODEL, rank_num, word_id, score))
    conn.commit()
    print(f"Wrote top {len(tops[mood_id])} for mood {m['label']} ({m['slug']})")

cur.close()
conn.close()
print("Done.")

