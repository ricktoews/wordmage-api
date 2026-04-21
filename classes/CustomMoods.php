<?php
namespace WordMage;

class CustomMoods
{
    public function getMoodTextById($customMoodId, $userId = 4)
    {
        global $wordmageDb;

        $customMoodId = (int)$customMoodId;
        $userId = (int)$userId;

        if ($customMoodId <= 0) {
            return null;
        }

        try {
            $stmt = $wordmageDb->prepare(
                "
                SELECT mood_text
                FROM custom_moods
                WHERE id = :id
                  AND user_id = :user_id
                LIMIT 1
                "
            );

            $stmt->execute([
                ':id' => $customMoodId,
                ':user_id' => $userId
            ]);

            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row || !isset($row['mood_text'])) {
                return null;
            }

            return $this->normalizeMoodText($row['mood_text']);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getEmbeddingByCustomMoodId($customMoodId, $userId = 4)
    {
        global $wordmageDb;

        $customMoodId = (int)$customMoodId;
        $userId = (int)$userId;

        if ($customMoodId <= 0) {
            return null;
        }

        try {
            $stmt = $wordmageDb->prepare(
                "
                SELECT embedding, embedding_model, embedding_dim
                FROM custom_moods
                WHERE id = :id
                  AND user_id = :user_id
                LIMIT 1
                "
            );

            $stmt->execute([
                ':id' => $customMoodId,
                ':user_id' => $userId
            ]);

            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row || !isset($row['embedding']) || $row['embedding'] === null) {
                return null;
            }

            $embedding = json_decode((string)$row['embedding'], true);
            if (!is_array($embedding)) {
                return null;
            }

            $embeddingDim = isset($row['embedding_dim']) && $row['embedding_dim'] !== null
                ? (int)$row['embedding_dim']
                : count($embedding);

            return [
                'embedding' => $embedding,
                'embedding_model' => $row['embedding_model'],
                'embedding_dim' => $embeddingDim
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getWordsByCustomText($text, $exclude_word_ids = [], $limit = 13) {
        global $wordmageDb;

        $limit = max(1, min((int)$limit, 200));
        $text = trim((string)$text);
        if ($text === '') return [];

        // prepare custom mood text for storage in custom_moods table.
        $norm = $this->normalizeMoodText($text);
        $hashBin = $this->moodHashBin($norm);

        $sql = "
        INSERT INTO custom_moods (user_id, mood_text, mood_hash, last_used_at, use_count)
        VALUES (:user_id, :mood_text, :mood_hash, NOW(), 1)
        ON DUPLICATE KEY UPDATE
          mood_text = VALUES(mood_text),
          last_used_at = NOW(),
          use_count = use_count + 1
        ";

        $stmt = $wordmageDb->prepare($sql);
        $stmt->execute([
          ':user_id' => 4,          // later: real logged-in user id
          ':mood_text' => $norm,
          ':mood_hash' => $hashBin
        ]);


        // Call local python service
        $payload = json_encode([
            "text" => $text,
            "exclude_word_ids" => $exclude_word_ids,
            "limit" => $limit,
            "pool" => 75,
            "hard_cap" => 500,
            "include_scores" => false
        ]);

        // calculate embeddings for embedded text ($payload), and get corresponding word list.
        $ch = curl_init("http://127.0.0.1:8013/search");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);

        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $http !== 200) return [];

        $data = json_decode($resp, true);
        if (!is_array($data) || !isset($data['word_ids']) || !is_array($data['word_ids'])) return [];

        $ids = $data['word_ids'];
        if (count($ids) === 0) return [];

        // Fetch word details
        // Preserve order returned by Python using ORDER BY FIELD
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $orderField = implode(',', array_map('intval', $ids));

        $sql = "
            SELECT id, word, definition, source
            FROM word_pool
            WHERE id IN ($placeholders)
            ORDER BY FIELD(id, $orderField)
        ";

        $stmt = $wordmageDb->prepare($sql);
        foreach ($ids as $i => $id) {
            $stmt->bindValue($i + 1, (int)$id, \PDO::PARAM_INT);
        }

        $wordlist = [];
        if ($stmt->execute()) {
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $wordlist[] = [
                    'id' => (int)$row['id'],
                    'word' => $row['word'],
                    'def' => $row['definition'],
                    'source' => $row['source']
                ];
            }
        }

        return $wordlist;
    }


    public function findWordsForMoodText($exclude_word_ids = [], $limit = 13, $embedding = null): array
    {
        global $wordmageDb;

        if ($embedding === null) {
            return [];
        }

        $limit = max(1, min((int)$limit, 200));

        $excludeIds = [];
        foreach ((array)$exclude_word_ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $excludeIds[] = $id;
            }
        }
        $excludeIds = array_values(array_unique($excludeIds));

        $payloadData = [
            "embedding" => $embedding,
            "exclude_word_ids" => $excludeIds,
            "limit" => $limit,
            "pool" => 75,
            "hard_cap" => 500,
            "include_scores" => false
        ];

        $payload = json_encode($payloadData);
        $ch = curl_init("http://127.0.0.1:8013/search");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);

        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            error_log('findWordsForMoodText cURL error: ' . $err);
            return [];
        }

        curl_close($ch);

        if ($http !== 200) {
            error_log('findWordsForMoodText HTTP error: ' . $http . ' response: ' . $resp);
            return [];
        }

        $data = json_decode($resp, true);

        if (!is_array($data) || !isset($data['word_ids']) || !is_array($data['word_ids'])) {
            return [];
        }

        $ids = array_values(array_filter(array_map('intval', $data['word_ids']), function ($id) {
            return $id > 0;
        }));

        if (count($ids) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $orderField = implode(',', array_map('intval', $ids));

        $sql = "
            SELECT id, word, definition, source
            FROM word_pool
            WHERE id IN ($placeholders)
            ORDER BY FIELD(id, $orderField)
        ";

        $stmt = $wordmageDb->prepare($sql);
        foreach ($ids as $i => $id) {
            $stmt->bindValue($i + 1, $id, \PDO::PARAM_INT);
        }

        $wordlist = [];
        if ($stmt->execute()) {
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $wordlist[] = [
                    'id' => (int)$row['id'],
                    'word' => $row['word'],
                    'def' => $row['definition'],
                    'source' => $row['source']
                ];
            }
        }

        return $wordlist;
    }


    // Checks whether the provided mood text differs from the stored value by comparing SHA-256 hashes,
    // and returns the normalized text and new hash for use in updates if a change is detected.
    public function didMoodChange(int $customMoodId, string $newMoodText): array
    {
        global $wordmageDb;

        // Normalize text (match Python behavior)
        $normalizedText = trim($newMoodText);

        // Compute binary SHA-256 hash (matches BINARY(32))
        $newHash = hash('sha256', $normalizedText, true);

        try {
            $sql = "
                SELECT mood_hash
                FROM custom_moods
                WHERE id = :id
                LIMIT 1
            ";

            $stmt = $wordmageDb->prepare($sql);
            $stmt->execute(['id' => $customMoodId]);

            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                throw new \Exception("Custom mood not found (id={$customMoodId})");
            }

            $oldHash = $row['mood_hash'];

            // Binary-safe comparison
            $changed = ($oldHash !== $newHash);

            return [
                'success' => true,
                'status' => 200,
                'changed' => $changed,
                'mood_text' => $normalizedText,
                'mood_hash' => $newHash
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 500,
                'error' => $e->getMessage()
            ];
        }
    }


    public function updateCustomMoodWithEmbedding(int $customMoodId, string $moodText): array
    {
        global $wordmageDb;

        $normalizedText = trim($moodText);

        if ($normalizedText === '') {
            return [
                'success' => false,
                'status' => 400,
                'error' => 'Mood text cannot be empty.'
            ];
        }

        $newHash = hash('sha256', $normalizedText, true);

        $apiKey = OPENAI_API_KEY;
        $url = "https://api.openai.com/v1/embeddings";
        $model = "text-embedding-3-small";

        $payload = [
            'model' => $model,
            'input' => $normalizedText
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer {$apiKey}"
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $response = curl_exec($ch);

        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);

            return [
                'success' => false,
                'status' => 500,
                'error' => "OpenAI cURL error: {$err}"
            ];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = $decoded['error']['message'] ?? 'Unknown OpenAI API error';

            return [
                'success' => false,
                'status' => $httpCode,
                'error' => "OpenAI embeddings error: {$msg}"
            ];
        }

        if (
            !isset($decoded['data'][0]['embedding']) ||
            !is_array($decoded['data'][0]['embedding'])
        ) {
            return [
                'success' => false,
                'status' => 500,
                'error' => 'Embedding response missing embedding data.'
            ];
        }

        $embedding = $decoded['data'][0]['embedding'];
        $embeddingModel = $decoded['model'] ?? $model;
        $embeddingDim = count($embedding);

        try {
            $sql = "
                UPDATE custom_moods
                SET
                    mood_text = :mood_text,
                    mood_hash = :mood_hash,
                    embedding = :embedding,
                    embedding_model = :embedding_model,
                    embedding_dim = :embedding_dim,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ";

            $stmt = $wordmageDb->prepare($sql);
            $stmt->bindValue(':mood_text', $normalizedText, \PDO::PARAM_STR);
            $stmt->bindValue(':mood_hash', $newHash, \PDO::PARAM_LOB);
            $stmt->bindValue(':embedding', json_encode($embedding), \PDO::PARAM_STR);
            $stmt->bindValue(':embedding_model', $embeddingModel, \PDO::PARAM_STR);
            $stmt->bindValue(':embedding_dim', $embeddingDim, \PDO::PARAM_INT);
            $stmt->bindValue(':id', $customMoodId, \PDO::PARAM_INT);
            $stmt->execute();

            return [
                'success' => true,
                'status' => 200,
                'custom_mood_id' => $customMoodId,
                'embedding' => $embedding,
                'embedding_model' => $embeddingModel,
                'embedding_dim' => $embeddingDim
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 500,
                'error' => $e->getMessage()
            ];
        }
    }


    public function updateCustomMood($userId, $moodId, $moodText)
    {
        global $wordmageDb;

        $userId = (int)$userId;
        $moodId = (int)$moodId;
        $moodText = $this->normalizeMoodText($moodText);

        if ($moodId <= 0) {
            return [
                'success' => false,
                'status' => 400,
                'error' => 'Invalid mood id'
            ];
        }

        if ($moodText === '') {
            return [
                'success' => false,
                'status' => 400,
                'error' => 'mood_text is required'
            ];
        }

        $moodHash = $this->moodHashBin($moodText);

        try {
            // 1. Load current mood row
            $stmt = $wordmageDb->prepare("
                SELECT id, user_id, mood_text, HEX(mood_hash) AS mood_hash_hex, last_used_at, use_count
                FROM custom_moods
                WHERE id = :mood_id
                  AND user_id = :user_id
                LIMIT 1
            ");
            $stmt->execute([
                ':mood_id' => $moodId,
                ':user_id' => $userId
            ]);

            $currentMood = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$currentMood) {
                return [
                    'success' => false,
                    'status' => 404,
                    'error' => 'Custom mood not found'
                ];
            }

            // 2. Check for collision with another row having same hash for this user
            $collisionStmt = $wordmageDb->prepare("
                SELECT id, user_id, mood_text, HEX(mood_hash) AS mood_hash_hex, last_used_at, use_count
                FROM custom_moods
                WHERE user_id = :user_id
                  AND mood_hash = :mood_hash
                  AND id <> :mood_id
                LIMIT 1
            ");
            $collisionStmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
            $collisionStmt->bindValue(':mood_hash', $moodHash, \PDO::PARAM_LOB);
            $collisionStmt->bindValue(':mood_id', $moodId, \PDO::PARAM_INT);
            $collisionStmt->execute();

            $collidingMood = $collisionStmt->fetch(\PDO::FETCH_ASSOC);

            $wordmageDb->beginTransaction();

            if ($collidingMood) {
                // Collision handling:
                // - touch the existing row
                // - delete the current row
                // - return the existing row as the surviving mood

                $touchStmt = $wordmageDb->prepare("
                    UPDATE custom_moods
                    SET mood_text = :mood_text,
                        last_used_at = NOW(),
                        use_count = use_count + 1,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :existing_id
                      AND user_id = :user_id
                ");
                $touchStmt->execute([
                    ':mood_text' => $moodText,
                    ':existing_id' => (int)$collidingMood['id'],
                    ':user_id' => $userId
                ]);

                $deleteStmt = $wordmageDb->prepare("
                    DELETE FROM custom_moods
                    WHERE id = :mood_id
                      AND user_id = :user_id
                ");
                $deleteStmt->execute([
                    ':mood_id' => $moodId,
                    ':user_id' => $userId
                ]);

                $wordmageDb->commit();

                $reloadStmt = $wordmageDb->prepare("
                    SELECT
                        id,
                        user_id,
                        mood_text,
                        HEX(mood_hash) AS mood_hash_hex,
                        last_used_at,
                        use_count,
                        created_at,
                        updated_at
                    FROM custom_moods
                    WHERE id = :mood_id
                      AND user_id = :user_id
                    LIMIT 1
                ");
                $reloadStmt->execute([
                    ':mood_id' => (int)$collidingMood['id'],
                    ':user_id' => $userId
                ]);

                $finalMood = $reloadStmt->fetch(\PDO::FETCH_ASSOC);

                return [
                    'success' => true,
                    'status' => 200,
                    'data' => [
                        'success' => true,
                        'mode' => 'merged',
                        'mood' => [
                            'id' => (int)$finalMood['id'],
                            'user_id' => (int)$finalMood['user_id'],
                            'mood_text' => $finalMood['mood_text'],
                            'mood_hash' => $finalMood['mood_hash_hex'],
                            'last_used_at' => $finalMood['last_used_at'],
                            'use_count' => (int)$finalMood['use_count'],
                            'created_at' => $finalMood['created_at'],
                            'updated_at' => $finalMood['updated_at']
                        ]
                    ]
                ];
            }

            // 3. No collision: update in place
            $updateStmt = $wordmageDb->prepare("
                UPDATE custom_moods
                SET mood_text = :mood_text,
                    mood_hash = :mood_hash,
                    last_used_at = NOW(),
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :mood_id
                  AND user_id = :user_id
            ");
            $updateStmt->bindValue(':mood_text', $moodText, \PDO::PARAM_STR);
            $updateStmt->bindValue(':mood_hash', $moodHash, \PDO::PARAM_LOB);
            $updateStmt->bindValue(':mood_id', $moodId, \PDO::PARAM_INT);
            $updateStmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
            $updateStmt->execute();

            $wordmageDb->commit();

            $reloadStmt = $wordmageDb->prepare("
                SELECT
                    id,
                    user_id,
                    mood_text,
                    HEX(mood_hash) AS mood_hash_hex,
                    last_used_at,
                    use_count,
                    created_at,
                    updated_at
                FROM custom_moods
                WHERE id = :mood_id
                  AND user_id = :user_id
                LIMIT 1
            ");
            $reloadStmt->execute([
                ':mood_id' => $moodId,
                ':user_id' => $userId
            ]);

            $finalMood = $reloadStmt->fetch(\PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'status' => 200,
                'data' => [
                    'success' => true,
                    'mode' => 'updated',
                    'mood' => [
                        'id' => (int)$finalMood['id'],
                        'user_id' => (int)$finalMood['user_id'],
                        'mood_text' => $finalMood['mood_text'],
                        'mood_hash' => $finalMood['mood_hash_hex'],
                        'last_used_at' => $finalMood['last_used_at'],
                        'use_count' => (int)$finalMood['use_count'],
                        'created_at' => $finalMood['created_at'],
                        'updated_at' => $finalMood['updated_at']
                    ]
                ]
            ];

        } catch (\Exception $e) {
            if ($wordmageDb && $wordmageDb->inTransaction()) {
                $wordmageDb->rollBack();
            }

            return [
                'success' => false,
                'status' => 500,
                'error' => 'Could not update custom mood: ' . $e->getMessage()
            ];
        }
    }

    public function deleteCustomMood($userId, $moodId)
    {
        global $wordmageDb;

        $userId = (int)$userId;
        $moodId = (int)$moodId;

        if ($moodId <= 0) {
            return [
                'success' => false,
                'status' => 400,
                'error' => 'Invalid mood id'
            ];
        }

        try {
            $stmt = $wordmageDb->prepare("
                SELECT id, user_id, mood_text
                FROM custom_moods
                WHERE id = :mood_id
                  AND user_id = :user_id
                LIMIT 1
            ");
            $stmt->execute([
                ':mood_id' => $moodId,
                ':user_id' => $userId
            ]);

            $mood = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$mood) {
                return [
                    'success' => false,
                    'status' => 404,
                    'error' => 'Custom mood not found'
                ];
            }

            $deleteStmt = $wordmageDb->prepare("
                DELETE FROM custom_moods
                WHERE id = :mood_id
                  AND user_id = :user_id
            ");
            $deleteStmt->execute([
                ':mood_id' => $moodId,
                ':user_id' => $userId
            ]);

            return [
                'success' => true,
                'status' => 200,
                'data' => [
                    'success' => true,
                    'deleted' => true,
                    'mood_id' => $moodId
                ]
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 500,
                'error' => 'Could not delete custom mood: ' . $e->getMessage()
            ];
        }
    }

    private function normalizeMoodText($text)
    {
        $text = trim((string)$text);
        $text = preg_replace('/\s+/', ' ', $text);
        return $text;
    }

    private function moodHashBin($normText)
    {
        return hash('sha256', (string)$normText, true);
    }
}
