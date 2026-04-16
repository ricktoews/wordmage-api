import mysql.connector
import numpy as np
import json

MODEL = "text-embedding-3-small"

with open("/etc/wordmage/mysql.json") as f:
    db_config = json.load(f)

conn = mysql.connector.connect(**db_config)
cur = conn.cursor(dictionary=True)

# ---- Fetch embeddings ----
cur.execute("""
    SELECT wp.id AS word_id, we.embedding_json
    FROM word_pool wp
    JOIN word_embeddings we
      ON we.word_id = wp.id
     AND we.model = %s
    ORDER BY wp.id
""", (MODEL,))

rows = cur.fetchall()

if not rows:
    raise RuntimeError("No word embeddings found.")

# ---- Build arrays ----
word_ids = []
word_vecs = []

for r in rows:
    vec = np.array(json.loads(r["embedding_json"]), dtype=np.float32)
    norm = np.linalg.norm(vec)
    if norm == 0:
        continue
    vec = vec / norm  # normalize now so we never do it again

    word_ids.append(r["word_id"])
    word_vecs.append(vec)

word_ids = np.array(word_ids, dtype=np.int32)
word_vecs = np.vstack(word_vecs).astype(np.float32)

print("Shape:", word_vecs.shape)

# ---- Save files ----
np.save("word_ids.npy", word_ids)
np.save("word_vecs.npy", word_vecs)

print("Export complete.")
