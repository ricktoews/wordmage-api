#!/usr/bin/env python3

import os
import sys
import json
import argparse
from pathlib import Path

import numpy as np
import mysql.connector

MODEL = "text-embedding-3-small"
DEFAULT_TOP_N = 25
DEFAULT_MOOD_SLUG = "archaic"

HERE = Path(__file__).resolve()
TOOLS_DIR = HERE.parent
EMBEDDINGS_DIR = TOOLS_DIR.parent

WORD_IDS_PATH = EMBEDDINGS_DIR / "word_ids.npy"
WORD_VECS_PATH = EMBEDDINGS_DIR / "word_vecs.npy"


def load_db_config():
    with open("/etc/wordmage/mysql.json") as f:
        return json.load(f)


def get_connection():
    db_config = load_db_config()
    return mysql.connector.connect(**db_config)


def parse_args():
    parser = argparse.ArgumentParser(
        description="Diagnostic tool: inspect top word matches for a preset mood."
    )
    parser.add_argument(
        "--slug",
        default=DEFAULT_MOOD_SLUG,
        help=f"Mood slug to inspect (default: {DEFAULT_MOOD_SLUG})"
    )
    parser.add_argument(
        "--top",
        type=int,
        default=DEFAULT_TOP_N,
        help=f"Number of matches to print (default: {DEFAULT_TOP_N})"
    )
    parser.add_argument(
        "--model",
        default=MODEL,
        help=f"Embedding model to use (default: {MODEL})"
    )
    return parser.parse_args()


def normalize_vector(vec):
    arr = np.array(vec, dtype=np.float32)
    norm = np.linalg.norm(arr)
    if norm == 0:
        return arr
    return arr / norm


def fetch_mood(cur, slug, model):
    sql = """
        SELECT
            m.id,
            m.label,
            m.slug,
            me.embedding_json
        FROM moods m
        JOIN mood_embeddings me
          ON me.mood_id = m.id
         AND me.model = %s
        WHERE m.slug = %s
        LIMIT 1
    """
    cur.execute(sql, (model, slug))
    return cur.fetchone()


def fetch_word_metadata(cur, word_ids):
    if not word_ids:
        return {}

    placeholders = ", ".join(["%s"] * len(word_ids))
    sql = f"""
        SELECT id, word, definition
        FROM word_pool
        WHERE id IN ({placeholders})
    """
    cur.execute(sql, tuple(int(x) for x in word_ids))
    rows = cur.fetchall()

    return {
        int(row["id"]): {
            "word": row["word"],
            "definition": row.get("definition") or ""
        }
        for row in rows
    }


def main():
    args = parse_args()

    if not WORD_IDS_PATH.exists():
        print(f"Missing file: {WORD_IDS_PATH}")
        sys.exit(1)

    if not WORD_VECS_PATH.exists():
        print(f"Missing file: {WORD_VECS_PATH}")
        sys.exit(1)

    print("Opening database connection...")
    conn = get_connection()
    cur = conn.cursor(dictionary=True)

    try:
        print(f"Loading mood embedding for slug '{args.slug}'...")
        mood_row = fetch_mood(cur, args.slug, args.model)

        if not mood_row:
            print(f"No mood embedding found for slug '{args.slug}' and model '{args.model}'.")
            sys.exit(1)

        mood_vec = json.loads(mood_row["embedding_json"])
        mood_vec = normalize_vector(mood_vec)

        print(f"Loading word IDs from {WORD_IDS_PATH} ...")
        word_ids = np.load(WORD_IDS_PATH)

        print(f"Loading word vectors from {WORD_VECS_PATH} ...")
        word_vecs = np.load(WORD_VECS_PATH)

        if word_ids.shape[0] != word_vecs.shape[0]:
            print(
                f"Mismatch: word_ids has {word_ids.shape[0]} rows but "
                f"word_vecs has {word_vecs.shape[0]} rows."
            )
            sys.exit(1)

        if word_vecs.ndim != 2:
            print(f"word_vecs.npy has unexpected shape: {word_vecs.shape}")
            sys.exit(1)

        if word_vecs.shape[1] != mood_vec.shape[0]:
            print(
                f"Dimension mismatch: word vectors have dim {word_vecs.shape[1]}, "
                f"but mood vector has dim {mood_vec.shape[0]}."
            )
            sys.exit(1)

        print(f"Computing cosine similarities across {word_vecs.shape[0]} words...")
        scores = word_vecs @ mood_vec

        top_n = min(args.top, scores.shape[0])
        top_indices = np.argsort(scores)[-top_n:][::-1]

        top_word_ids = [int(word_ids[i]) for i in top_indices]
        print("Loading metadata for top matches...")
        metadata = fetch_word_metadata(cur, top_word_ids)

        print()
        print(f"Top {top_n} matches for mood '{mood_row['label']}' ({mood_row['slug']}):")
        print()

        for i in top_indices:
            word_id = int(word_ids[i])
            score = float(scores[i])
            meta = metadata.get(word_id, {})
            word = meta.get("word", f"[word_id={word_id}]")
            definition = meta.get("definition", "")
            print(f"{word:25} {score:0.4f}  —  {definition}")

    finally:
        cur.close()
        conn.close()


if __name__ == "__main__":
    main()
