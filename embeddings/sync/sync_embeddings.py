import os
import json
import time
import hashlib
import argparse
from typing import List, Dict, Iterable

import mysql.connector
from openai import OpenAI

MODEL = "text-embedding-3-small"
DEFAULT_BATCH_SIZE = 100


with open(os.path.expanduser("/etc/wordmage/.openai_key")) as f:
    os.environ["OPENAI_API_KEY"] = f.read().strip()

client = OpenAI()

with open("/etc/wordmage/mysql.json") as f:
    db_config = json.load(f)


def sha256_hex(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def sha256_bytes(text: str) -> bytes:
    return hashlib.sha256(text.encode("utf-8")).digest()


def chunked(seq: List[Dict], size: int) -> Iterable[List[Dict]]:
    for i in range(0, len(seq), size):
        yield seq[i:i + size]


def get_embeddings(texts: List[str]) -> List[List[float]]:
    if not texts:
        return []

    response = client.embeddings.create(
        model=MODEL,
        input=texts
    )
    return [item.embedding for item in response.data]


def canonical_word_text(row: Dict) -> str:
    word = (row.get("word") or "").strip()
    definition = (row.get("definition") or "").strip()
    return f"{word}: {definition}"


def canonical_mood_text(row: Dict) -> str:
    label = (row.get("label") or "").strip()
    slug = (row.get("slug") or "").strip()
    description = (row.get("description") or "").strip()
    return f"{label}\n{slug}\n{description}"


class EmbeddingSync:
    def __init__(self, conn, batch_size: int):
        self.conn = conn
        self.cur = conn.cursor(dictionary=True)
        self.batch_size = batch_size

    def close(self):
        self.cur.close()

    def sync_words(self) -> int:
        print("Checking word embeddings...")

        sql = """
            SELECT
                wp.id,
                wp.word,
                wp.definition,
                we.input_hash
            FROM word_pool wp
            LEFT JOIN word_embeddings we
              ON we.word_id = wp.id
             AND we.model = %s
            ORDER BY wp.id
        """
        self.cur.execute(sql, (MODEL,))
        rows = self.cur.fetchall()

        to_embed = []
        for row in rows:
            text = canonical_word_text(row)
            new_hash = sha256_hex(text)
            old_hash = row.get("input_hash")

            if old_hash != new_hash:
                to_embed.append({
                    "id": row["id"],
                    "text": text,
                    "input_hash": new_hash
                })

        print(f"Words needing embedding: {len(to_embed)}")

        updated = 0

        for batch in chunked(to_embed, self.batch_size):
            texts = [item["text"] for item in batch]
            vectors = get_embeddings(texts)
            dims = len(vectors[0]) if vectors else 0

            upsert_sql = """
                INSERT INTO word_embeddings
                    (word_id, model, dims, input_hash, embedding_json)
                VALUES
                    (%s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    dims = VALUES(dims),
                    input_hash = VALUES(input_hash),
                    embedding_json = VALUES(embedding_json)
            """

            data = []
            for item, vec in zip(batch, vectors):
                data.append((
                    item["id"],
                    MODEL,
                    dims,
                    item["input_hash"],
                    json.dumps(vec)
                ))

            self.cur.executemany(upsert_sql, data)
            self.conn.commit()
            updated += len(batch)
            print(f"Updated word embeddings: {updated}")

            time.sleep(0.2)

        delete_sql = """
            DELETE we
            FROM word_embeddings we
            LEFT JOIN word_pool wp
              ON wp.id = we.word_id
            WHERE we.model = %s
              AND wp.id IS NULL
        """
        self.cur.execute(delete_sql, (MODEL,))
        deleted = self.cur.rowcount
        self.conn.commit()

        if deleted:
            print(f"Deleted orphaned word embeddings: {deleted}")

        return updated

    def sync_moods(self) -> int:
        print("Checking preset mood embeddings...")

        sql = """
            SELECT
                m.id,
                m.label,
                m.slug,
                m.description,
                me.input_hash
            FROM moods m
            LEFT JOIN mood_embeddings me
              ON me.mood_id = m.id
             AND me.model = %s
            ORDER BY m.id
        """
        self.cur.execute(sql, (MODEL,))
        rows = self.cur.fetchall()

        to_embed = []
        for row in rows:
            text = canonical_mood_text(row)
            new_hash = sha256_hex(text)
            old_hash = row.get("input_hash")

            if old_hash != new_hash:
                to_embed.append({
                    "id": row["id"],
                    "text": text,
                    "input_hash": new_hash
                })

        print(f"Preset moods needing embedding: {len(to_embed)}")

        updated = 0

        for batch in chunked(to_embed, self.batch_size):
            texts = [item["text"] for item in batch]
            vectors = get_embeddings(texts)
            dims = len(vectors[0]) if vectors else 0

            upsert_sql = """
                INSERT INTO mood_embeddings
                    (mood_id, model, dims, input_hash, embedding_json)
                VALUES
                    (%s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    dims = VALUES(dims),
                    input_hash = VALUES(input_hash),
                    embedding_json = VALUES(embedding_json)
            """

            data = []
            for item, vec in zip(batch, vectors):
                data.append((
                    item["id"],
                    MODEL,
                    dims,
                    item["input_hash"],
                    json.dumps(vec)
                ))

            self.cur.executemany(upsert_sql, data)
            self.conn.commit()
            updated += len(batch)
            print(f"Updated preset mood embeddings: {updated}")

            time.sleep(0.2)

        delete_sql = """
            DELETE me
            FROM mood_embeddings me
            LEFT JOIN moods m
              ON m.id = me.mood_id
            WHERE me.model = %s
              AND m.id IS NULL
        """
        self.cur.execute(delete_sql, (MODEL,))
        deleted = self.cur.rowcount
        self.conn.commit()

        if deleted:
            print(f"Deleted orphaned mood embeddings: {deleted}")

        return updated

    def sync_custom_moods(self) -> int:
        print("Checking custom mood embeddings...")

        sql = """
            SELECT
                id,
                mood_text,
                mood_hash,
                embedding,
                embedding_model,
                embedding_dim
            FROM custom_moods
            ORDER BY id
        """
        self.cur.execute(sql)
        rows = self.cur.fetchall()

        to_embed = []
        for row in rows:
            mood_text = (row.get("mood_text") or "").strip()
            new_hash = sha256_bytes(mood_text)

            old_hash = row.get("mood_hash")
            old_model = row.get("embedding_model")
            old_embedding = row.get("embedding")
            old_dim = row.get("embedding_dim")

            needs_update = (
                old_hash != new_hash
                or old_model != MODEL
                or old_embedding is None
                or old_dim is None
            )

            if needs_update:
                to_embed.append({
                    "id": row["id"],
                    "text": mood_text,
                    "mood_hash": new_hash
                })

        print(f"Custom moods needing embedding: {len(to_embed)}")

        updated = 0

        for batch in chunked(to_embed, self.batch_size):
            texts = [item["text"] for item in batch]
            vectors = get_embeddings(texts)
            dims = len(vectors[0]) if vectors else 0

            update_sql = """
                UPDATE custom_moods
                SET
                    mood_hash = %s,
                    embedding = %s,
                    embedding_model = %s,
                    embedding_dim = %s,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = %s
            """

            data = []
            for item, vec in zip(batch, vectors):
                data.append((
                    item["mood_hash"],
                    json.dumps(vec),
                    MODEL,
                    dims,
                    item["id"]
                ))

            self.cur.executemany(update_sql, data)
            self.conn.commit()
            updated += len(batch)
            print(f"Updated custom mood embeddings: {updated}")

            time.sleep(0.2)

        return updated


def parse_args():
    parser = argparse.ArgumentParser(description="Sync WordMage embeddings.")

    parser.add_argument("--words", action="store_true", help="Sync word_pool -> word_embeddings")
    parser.add_argument("--moods", action="store_true", help="Sync moods -> mood_embeddings")
    parser.add_argument("--custom", action="store_true", help="Sync custom_moods embeddings")
    parser.add_argument("--all", action="store_true", help="Sync all embeddings")
    parser.add_argument(
        "--batch-size",
        type=int,
        default=DEFAULT_BATCH_SIZE,
        help=f"Embedding batch size (default: {DEFAULT_BATCH_SIZE})"
    )

    args = parser.parse_args()

    if not (args.words or args.moods or args.custom or args.all):
        parser.error("Specify at least one of --words, --moods, --custom, or --all")

    return args


def main():
    args = parse_args()

    do_words = args.all or args.words
    do_moods = args.all or args.moods
    do_custom = args.all or args.custom

    conn = mysql.connector.connect(**db_config)
    syncer = EmbeddingSync(conn, args.batch_size)

    words_changed = 0
    moods_changed = 0
    custom_changed = 0

    try:
        if do_words:
            words_changed = syncer.sync_words()

        if do_moods:
            moods_changed = syncer.sync_moods()

        if do_custom:
            custom_changed = syncer.sync_custom_moods()

    finally:
        syncer.close()
        conn.close()

    print()
    print("Done.")
    print(f"Word embeddings updated: {words_changed}")
    print(f"Preset mood embeddings updated: {moods_changed}")
    print(f"Custom mood embeddings updated: {custom_changed}")

    if words_changed or moods_changed:
        print("Rerun precompute_mood_top_words.py")


if __name__ == "__main__":
    main()
