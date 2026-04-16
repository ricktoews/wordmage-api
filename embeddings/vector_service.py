from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import numpy as np
import os
import time

from openai import OpenAI

MODEL = "text-embedding-3-small"
HERE = os.path.dirname(os.path.abspath(__file__))

# Load once (already normalized)
WORD_IDS = np.load(os.path.join(HERE, "word_ids.npy"))
WORD_VECS = np.load(os.path.join(HERE, "word_vecs.npy"))  # shape: (N, 1536), float32

# OpenAI key
with open(os.path.expanduser("/etc/wordmage/.openai_key"), "r", encoding="utf-8") as f:
    os.environ["OPENAI_API_KEY"] = f.read().strip()

client = OpenAI()

app = FastAPI()


class SearchReq(BaseModel):
    text: str
    exclude_word_ids: list[int] = []
    limit: int = 13
    pool: int = 75
    hard_cap: int = 500
    include_scores: bool = False


@app.get("/health")
def health():
    return {"ok": True, "n_words": int(WORD_VECS.shape[0]), "dim": int(WORD_VECS.shape[1])}


@app.post("/search")
def search(req: SearchReq):
    text = (req.text or "").strip()
    if not text:
        raise HTTPException(status_code=400, detail="text is required")

    limit = max(1, min(int(req.limit), 200))
    hard_cap = max(limit, min(int(req.hard_cap), int(WORD_VECS.shape[0])))
    pool = max(limit, min(int(req.pool), hard_cap))

    t0 = time.time()

    # Embed query text
    resp = client.embeddings.create(model=MODEL, input=text)
    q = np.array(resp.data[0].embedding, dtype=np.float32)
    qn = np.linalg.norm(q)
    if qn == 0:
        raise HTTPException(status_code=500, detail="query embedding norm was zero")
    q = q / qn  # normalize

    # Cosine similarity via dot product (WORD_VECS already normalized)
    scores = WORD_VECS @ q  # shape (N,)

    # Get top hard_cap indices (unsorted)
    idx = np.argpartition(scores, -hard_cap)[-hard_cap:]
    # Sort those by score desc
    idx = idx[np.argsort(scores[idx])[::-1]]

    # Remove excluded IDs
    if req.exclude_word_ids:
        exclude_set = set(req.exclude_word_ids)
        exclude_set = set(req.exclude_word_ids)
        mask = np.array([wid not in exclude_set for wid in WORD_IDS[idx]])
        idx = idx[mask]

    # Adjust pool
    pool = min(pool, len(idx))

    # Randomize within top `pool` like your RAND() within rank_num <= 75
    top_pool = idx[:pool]

    if pool > limit:
        chosen = np.random.choice(top_pool, size=limit, replace=False)
        # keep chosen sorted by score desc (optional; comment out if you want pure random order)
        chosen = chosen[np.argsort(scores[chosen])[::-1]]
    else:
        chosen = top_pool[:limit]

    word_ids = WORD_IDS[chosen].tolist()

    out = {"word_ids": word_ids, "model": MODEL, "ms": int((time.time() - t0) * 1000)}
    if req.include_scores:
        out["scores"] = [float(scores[i]) for i in chosen]
    return out
