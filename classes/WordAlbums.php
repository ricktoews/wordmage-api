<?php

namespace WordMage;

class WordAlbums
{
    public function createAlbum($userId, $title, $moodText, $wordIds)
    {
        global $wordmageDb;

        $customMoods = new CustomMoods();

        $userId = (int)$userId;
        $title = trim((string)$title);
        $moodText = $moodText !== null ? trim((string)$moodText) : null;
        $customMoodId = null;
        $embedding = null;

        if ($title === '') {
            return [
                'success' => false,
                'status' => 400,
                'error' => 'title is required'
            ];
        }

        // Normalize and dedupe while preserving order
        $cleanWordIds = [];
        $seen = [];

        foreach ($wordIds as $wordId) {
            $wordId = (int)$wordId;
            if ($wordId > 0 && !isset($seen[$wordId])) {
                $cleanWordIds[] = $wordId;
                $seen[$wordId] = true;
            }
        }

        try {
            $wordmageDb->beginTransaction();

            if ($moodText !== null && $moodText !== '') {
                $normalizedMoodText = preg_replace('/\s+/', ' ', $moodText);
                $moodHash = hash('sha256', (string)$normalizedMoodText, true);

                $sqlMood = "
                    INSERT INTO custom_moods (user_id, mood_text, mood_hash, last_used_at, use_count)
                    VALUES (:user_id, :mood_text, :mood_hash, NOW(), 1)
                    ON DUPLICATE KEY UPDATE
                      id = LAST_INSERT_ID(id),
                      mood_text = VALUES(mood_text),
                      last_used_at = NOW(),
                      use_count = use_count + 1
                ";

                $stmtMood = $wordmageDb->prepare($sqlMood);
                $stmtMood->execute([
                    ':user_id' => $userId,
                    ':mood_text' => $normalizedMoodText,
                    ':mood_hash' => $moodHash
                ]);

                $customMoodId = (int)$wordmageDb->lastInsertId();
                if ($customMoodId <= 0) {
                    throw new \Exception('Could not resolve custom mood id for album creation.');
                }

                $embedResult = $customMoods->updateCustomMoodWithEmbedding($customMoodId, $normalizedMoodText);
                if (!(isset($embedResult['success']) && $embedResult['success'])) {
                    $wordmageDb->rollBack();
                    return $embedResult;
                }

                $embedding = isset($embedResult['embedding']) ? $embedResult['embedding'] : null;

                if (count($cleanWordIds) === 0 && $embedding !== null) {
                    $generatedWords = $customMoods->findWordsForMoodText([], 100, $embedding);
                    $cleanWordIds = array_values(array_map(function ($word) {
                        return (int)$word['id'];
                    }, $generatedWords));
                }

                $moodText = $normalizedMoodText;
            }

            $sqlAlbum = "
                INSERT INTO word_albums (user_id, title, custom_mood_id, source_type)
                VALUES (:user_id, :title, :custom_mood_id, :source_type)
            ";
            $stmtAlbum = $wordmageDb->prepare($sqlAlbum);
            $stmtAlbum->execute([
                ':user_id' => $userId,
                ':title' => $title,
                ':custom_mood_id' => $customMoodId,
                ':source_type' => 'mood'
            ]);

            $albumId = (int)$wordmageDb->lastInsertId();

            $sqlItem = "
                INSERT INTO word_album_items (album_id, word_id, position)
                VALUES (:album_id, :word_id, :position)
            ";
            $stmtItem = $wordmageDb->prepare($sqlItem);

            $position = 1;
            foreach ($cleanWordIds as $wordId) {
                $stmtItem->execute([
                    ':album_id' => $albumId,
                    ':word_id' => $wordId,
                    ':position' => $position
                ]);
                $position++;
            }

            $wordmageDb->commit();

	    $coverPath = null;

            try {
                $coverPath = $this->generateAlbumCard($albumId, $title, $moodText, $cleanWordIds);
                $this->updateAlbumCoverPath($albumId, $coverPath);
            } catch (\Exception $e) {
                // Optional: log this somewhere
                // error_log('Album card generation failed: ' . $e->getMessage());
            }

            return [
                'success' => true,
                'status' => 200,
                'data' => [
                    'success' => true,
                    'album_id' => $albumId,
                    'title' => $title,
                    'mood_text' => $moodText,
                    'word_count' => count($cleanWordIds),
		    'cover_image_path' => $coverPath,
                ]
            ];
        } catch (\Exception $e) {
            if ($wordmageDb->inTransaction()) {
                $wordmageDb->rollBack();
            }

            return [
                'success' => false,
                'status' => 500,
                'error' => 'Could not save album: ' . $e->getMessage()
            ];
        }
    }



    public function getAlbumsByUser($userId, $limit = 50)
    {
        global $wordmageDb;

        $userId = (int)$userId;
        $limit = max(1, min((int)$limit, 200));

        try {
/*
            $sql = "
                SELECT
                    wa.id,
                    wa.title,
                    wa.cover_image_path,
                    wa.mood_text,
                    wa.created_at,
                    wa.updated_at,
                    COUNT(wai.word_id) AS word_count
                FROM word_albums wa
                LEFT JOIN word_album_items wai ON wai.album_id = wa.id
                WHERE wa.user_id = :user_id
                GROUP BY wa.id, wa.title, wa.mood_text, wa.created_at, wa.updated_at
                ORDER BY wa.updated_at DESC, wa.id DESC
                LIMIT {$limit}
            ";
*/
            $sql = "
                SELECT
                    wa.id,
                    wa.title,
                    wa.cover_image_path,
                    cm.mood_text,
                    wa.created_at,
                    wa.updated_at,
                    COUNT(wai.word_id) AS word_count
                FROM word_albums wa
                LEFT JOIN custom_moods cm ON cm.id = wa.custom_mood_id
                LEFT JOIN word_album_items wai ON wai.album_id = wa.id
                WHERE wa.user_id = :user_id
                GROUP BY
                    wa.id,
                    wa.title,
                    wa.cover_image_path,
                    cm.mood_text,
                    wa.created_at,
                    wa.updated_at
                ORDER BY wa.updated_at DESC, wa.id DESC
                LIMIT {$limit}
            ";

            $stmt = $wordmageDb->prepare($sql);
            $stmt->execute([':user_id' => $userId]);

            $albums = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $albums[] = [
                    'id' => (int)$row['id'],
                    'title' => $row['title'],
                    'cover_image_path' => $row['cover_image_path'],
                    'mood_text' => $row['mood_text'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                    'word_count' => (int)$row['word_count']
                ];
            }

            return [
                'success' => true,
                'status' => 200,
                'data' => $albums
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 500,
                'error' => 'Could not load albums: ' . $e->getMessage()
            ];
        }
    }


    public function getAlbumById($userId, $albumId)
    {
        global $wordmageDb;

        $userId = (int)$userId;
        $albumId = (int)$albumId;

        if ($albumId <= 0) {
            return [
                'success' => false,
                'status' => 400,
                'error' => 'Invalid album id'
            ];
        }
    
        try {
            // 1. Load album metadata
            $sqlAlbum = "
                SELECT
                    wa.id,
                    wa.title,
                    wa.custom_mood_id,
                    cm.mood_text,
                    wa.created_at,
                    wa.updated_at,
                    COUNT(wai.word_id) AS word_count
                FROM word_albums wa
                LEFT JOIN custom_moods cm ON cm.id = wa.custom_mood_id
                LEFT JOIN word_album_items wai ON wai.album_id = wa.id
                WHERE wa.user_id = :user_id
                  AND wa.id = :album_id
                                GROUP BY wa.id, wa.title, wa.custom_mood_id, cm.mood_text, wa.created_at, wa.updated_at
                LIMIT 1
            ";

            $stmtAlbum = $wordmageDb->prepare($sqlAlbum);
            $stmtAlbum->execute([
                ':user_id' => $userId,
                ':album_id' => $albumId
            ]);
    
            $album = $stmtAlbum->fetch(\PDO::FETCH_ASSOC);

            if (!$album) {
                return [
                    'success' => false,
                    'status' => 404,
                    'error' => 'Album not found'
                ];
            }

            // 2. Load album words in saved order
            $sqlWords = "
                SELECT
                    wp.id,
                    wp.word,
                    wp.definition,
                    wp.source,
                    wai.position,
                    wai.is_locked
                FROM word_album_items wai
                INNER JOIN word_pool wp ON wp.id = wai.word_id
                WHERE wai.album_id = :album_id
                ORDER BY wai.position ASC
            ";

            $stmtWords = $wordmageDb->prepare($sqlWords);
            $stmtWords->execute([
                ':album_id' => $albumId
            ]);

            $words = [];
            while ($row = $stmtWords->fetch(\PDO::FETCH_ASSOC)) {
                $words[] = [
                    'id' => (int)$row['id'],
                    'word' => $row['word'],
                    'def' => $row['definition'],
                    'source' => $row['source'],
                    'position' => (int)$row['position'],
                    'is_locked' => (int)$row['is_locked'],
                ];
            }

            return [
                'success' => true,
                'status' => 200,
                'data' => [
                    'id' => (int)$album['id'],
                    'title' => $album['title'],
                    'custom_mood_id' => isset($album['custom_mood_id']) ? (int)$album['custom_mood_id'] : null,
                    'mood_text' => $album['mood_text'],
                    'created_at' => $album['created_at'],
                    'updated_at' => $album['updated_at'],
                    'word_count' => (int)$album['word_count'],
                    'words' => $words
                ]
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 500,
                'error' => 'Could not load album: ' . $e->getMessage()
            ];
        }
    }


    public function getPermanentAlbumsByUser($userid) {
        global $wordmageDb;

        try {
            $sql = "
                SELECT
                    wa.id AS album_id,
                    wa.title
                FROM word_albums wa
                WHERE wa.user_id = :userid
                  AND wa.title IN ('Favorites', 'Learn')
            ";

            $stmt = $wordmageDb->prepare($sql);
            $stmt->execute(['userid' => $userid]);

	    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $data = [];
            foreach ($rows as $row) {
                $data[$row['title']] = (int)$row['album_id'];
            }

            return $data;

        } catch (Exception $e) {
            return [
                'success' => false,
                'status' => 500,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getFeaturedFavoriteByUser($userid) {
        global $wordmageDb;

        try {
            $sql = "
                SELECT
                    wp.id AS word_id,
                    wp.word,
                    wp.definition,
                    wp.source
                FROM word_albums wa
                JOIN word_album_items wai
                    ON wai.album_id = wa.id
                JOIN word_pool wp
                    ON wp.id = wai.word_id
                WHERE wa.user_id = :userid
                  AND wa.title = 'Favorites'
                ORDER BY RAND()
                LIMIT 1
            ";

            $stmt = $wordmageDb->prepare($sql);
            $stmt->execute(['userid' => $userid]);

            $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'status' => 200,
                'data' => $data[0]
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'status' => 500,
                'error' => $e->getMessage()
            ];
        }
    }


    public function getFavoritesByUser($userid) {
        global $wordmageDb;

        try {
            $sql = "
                SELECT
                    wp.id AS word_id,
                    wp.word,
                    wp.definition,
                    wp.source
                FROM word_albums wa
                JOIN word_album_items wai
                    ON wai.album_id = wa.id
                JOIN word_pool wp
                    ON wp.id = wai.word_id
                WHERE wa.user_id = :userid
                  AND wa.title = 'Favorites'
                ORDER BY wp.word ASC
            ";

            $stmt = $wordmageDb->prepare($sql);
            $stmt->execute(['userid' => $userid]);

            $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'status' => 200,
                'data' => $data
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'status' => 500,
                'error' => $e->getMessage()
            ];
        }
    }


    public function getLearnByUser($userid) {
        global $wordmageDb;

        try {
            $sql = "
                SELECT
                    wp.id AS word_id,
                    wp.word,
                    wp.definition,
                    wp.source
                FROM word_albums wa
                JOIN word_album_items wai
                    ON wai.album_id = wa.id
                JOIN word_pool wp
                    ON wp.id = wai.word_id
                WHERE wa.user_id = :userid
                  AND wa.title = 'Learn'
                ORDER BY wp.word ASC
            ";

            $stmt = $wordmageDb->prepare($sql);
            $stmt->execute(['userid' => $userid]);

            $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'status' => 200,
                'data' => $data
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'status' => 500,
                'error' => $e->getMessage()
            ];
        }
    }


    public function updateAlbum($userId, $albumId, $title, $moodText, $words)
    {
        global $wordmageDb;

        $customMoods = new CustomMoods();

        $userId = (int)$userId;
        $albumId = (int)$albumId;
        $title = trim((string)$title);
        $moodText = trim((string)$moodText);
        $customMoodId = -1;

        if ($albumId <= 0) {
            return [
                'success' => false,
                'status' => 400,
                'error' => 'Invalid album ID.'
            ];
        }

        if ($title === '') {
            return [
                'success' => false,
                'status' => 400,
                'error' => 'Album title is required.'
            ];
        }

        if (!is_array($words)) {
            return [
                'success' => false,
                'status' => 400,
                'error' => 'Words must be an array.'
            ];
        }

        // Normalize and validate incoming word items
        $normalizedWords = [];

        foreach ($words as $index => $word) {
            if (!is_array($word) || !isset($word['id'])) {
                return [
                    'success' => false,
                    'status' => 400,
                    'error' => 'Each word must be an object containing at least an id.'
                ];
            }

            $wordId = (int)$word['id'];
            if ($wordId <= 0) {
                return [
                    'success' => false,
                    'status' => 400,
                    'error' => 'Invalid word ID in words array.'
                ];
            }

            $isLocked = !empty($word['is_locked']) ? 1 : 0;

            $position = isset($word['position']) && is_numeric($word['position'])
                ? (int)$word['position']
                : $index;

            $normalizedWords[] = [
                'id' => $wordId,
                'is_locked' => $isLocked,
                'position' => $position
            ];
        }

        usort($normalizedWords, function ($a, $b) {
            return $a['position'] <=> $b['position'];
        });

        try {
            $wordmageDb->beginTransaction();

            // Verify album belongs to user
            $checkSql = "
                SELECT id, custom_mood_id
                FROM word_albums
                WHERE id = :album_id
                  AND user_id = :user_id
                LIMIT 1
            ";
            $checkStmt = $wordmageDb->prepare($checkSql);
            $checkStmt->bindValue(':album_id', $albumId, \PDO::PARAM_INT);
            $checkStmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
            $checkStmt->execute();

            $album = $checkStmt->fetch(\PDO::FETCH_ASSOC);

            if (!$album) {
                $wordmageDb->rollBack();
                return [
                    'success' => false,
                    'status' => 404,
                    'error' => 'Album not found.'
                ];
            }

            $customMoodId = isset($album['custom_mood_id']) ? (int)$album['custom_mood_id'] : 0;

            // Update album title
            $updateAlbumSql = "
                UPDATE word_albums
                SET title = :title,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :album_id
                  AND user_id = :user_id
                LIMIT 1
            ";
            $updateAlbumStmt = $wordmageDb->prepare($updateAlbumSql);
            $updateAlbumStmt->bindValue(':title', $title, \PDO::PARAM_STR);
            $updateAlbumStmt->bindValue(':album_id', $albumId, \PDO::PARAM_INT);
            $updateAlbumStmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
            $updateAlbumStmt->execute();

            $finalWords = $normalizedWords;

            // Regenerate if mood text changed
            if ($customMoodId > 0) {
                $moodChange = $customMoods->didMoodChange($customMoodId, $moodText);

                if (!(isset($moodChange['success']) && $moodChange['success'])) {
                    $wordmageDb->rollBack();
                    return $moodChange;
                }

                if (!empty($moodChange['changed'])) {
                    $embedResult = $customMoods->updateCustomMoodWithEmbedding(
                        $customMoodId,
                        $moodChange['mood_text']
                    );

                    if (!(isset($embedResult['success']) && $embedResult['success'])) {
                        $wordmageDb->rollBack();
                        return $embedResult;
                    }

                    $lockedWords = array_values(array_filter($normalizedWords, function ($word) {
                        return !empty($word['is_locked']);
                    }));

                    $lockedIds = array_values(array_map(function ($word) {
                        return (int)$word['id'];
                    }, $lockedWords));

                    $targetCount = count($normalizedWords);
                    $remainingSlots = max(100, $targetCount - count($lockedWords));

                    $embedding = isset($embedResult['embedding']) ? $embedResult['embedding'] : null;

                    $freshWords = $customMoods->findWordsForMoodText(
                        $lockedIds,
                        $remainingSlots,
                        $embedding
                    );

                    $finalWords = [];
                    $position = 1;

                    foreach ($lockedWords as $word) {
                        $finalWords[] = [
                            'id' => (int)$word['id'],
                            'is_locked' => 1,
                            'position' => $position++
                        ];
                    }

                    foreach ($freshWords as $word) {
                        $finalWords[] = [
                            'id' => (int)$word['id'],
                            'is_locked' => 0,
                            'position' => $position++
                        ];
                    }
                }
            }

            // Replace album items completely
            $deleteSql = "
                DELETE FROM word_album_items
                WHERE album_id = :album_id
            ";
            $deleteStmt = $wordmageDb->prepare($deleteSql);
            $deleteStmt->bindValue(':album_id', $albumId, \PDO::PARAM_INT);
            $deleteStmt->execute();

            if (!empty($finalWords)) {
                $insertSql = "
                    INSERT INTO word_album_items (album_id, word_id, position, is_locked)
                    VALUES (:album_id, :word_id, :position, :is_locked)
                ";
                $insertStmt = $wordmageDb->prepare($insertSql);

                foreach ($finalWords as $word) {
                    $insertStmt->bindValue(':album_id', $albumId, \PDO::PARAM_INT);
                    $insertStmt->bindValue(':word_id', $word['id'], \PDO::PARAM_INT);
                    $insertStmt->bindValue(':position', $word['position'], \PDO::PARAM_INT);
                    $insertStmt->bindValue(':is_locked', $word['is_locked'], \PDO::PARAM_INT);
                    $insertStmt->execute();
                }
            }

            $wordmageDb->commit();

            return [
                'success' => true,
                'status' => 200,
                'data' => [
                    'id' => $albumId,
                    'title' => $title,
                    'mood_text' => $moodText,
                    'words' => $finalWords
                ]
            ];
        } catch (\Exception $e) {
            if ($wordmageDb->inTransaction()) {
                $wordmageDb->rollBack();
            }

            return [
                'success' => false,
                'status' => 500,
                'error' => 'Failed to update album: ' . $e->getMessage()
            ];
        }
    }


    public function updateAlbumTitle($userId, $albumId, $title)
    {
        global $wordmageDb;

        $userId = (int)$userId;
        $albumId = (int)$albumId;
        $title = trim((string)$title);

        if ($albumId <= 0) {
            return [
                'success' => false,
                'status' => 400,
                'error' => 'Invalid album id'
            ];
        }

        if ($title === '') {
            return [
                'success' => false,
                'status' => 400,
                'error' => 'title is required'
            ];
        }

        try {
            $sql = "
                UPDATE word_albums
                SET title = :title,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :album_id
                  AND user_id = :user_id
            ";

            $stmt = $wordmageDb->prepare($sql);
            $stmt->execute([
                ':title' => $title,
                ':album_id' => $albumId,
                ':user_id' => $userId
            ]);

            if ($stmt->rowCount() === 0) {
                // Could be "not found" or "same title as before".
                // Check whether album exists for this user.
                $checkStmt = $wordmageDb->prepare("
                    SELECT id, title, mood_text, cover_image_path, created_at, updated_at
                    FROM word_albums
                    WHERE id = :album_id
                      AND user_id = :user_id
                    LIMIT 1
                ");
                $checkStmt->execute([
                    ':album_id' => $albumId,
                    ':user_id' => $userId
                ]);

                $album = $checkStmt->fetch(\PDO::FETCH_ASSOC);

                if (!$album) {
                    return [
                        'success' => false,
                        'status' => 404,
                        'error' => 'Album not found'
                    ];
                }

                return [
                    'success' => true,
                    'status' => 200,
                    'data' => [
                        'success' => true,
                        'album_id' => (int)$album['id'],
                        'title' => $album['title'],
                        'mood_text' => $album['mood_text'],
                        'cover_image_path' => $album['cover_image_path'],
                        'created_at' => $album['created_at'],
                        'updated_at' => $album['updated_at']
                    ]
                ];
            }

            $stmt2 = $wordmageDb->prepare("
                SELECT id, title, mood_text, cover_image_path, created_at, updated_at
                FROM word_albums
                WHERE id = :album_id
                  AND user_id = :user_id
                LIMIT 1
            ");
            $stmt2->execute([
                ':album_id' => $albumId,
                ':user_id' => $userId
            ]);

            $album = $stmt2->fetch(\PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'status' => 200,
                'data' => [
                    'success' => true,
                    'album_id' => (int)$album['id'],
                    'title' => $album['title'],
                    'mood_text' => $album['mood_text'],
                    'cover_image_path' => $album['cover_image_path'],
                    'created_at' => $album['created_at'],
                    'updated_at' => $album['updated_at']
                ]
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 500,
                'error' => 'Could not update album title: ' . $e->getMessage()
            ];
        }
    }

public function deleteAlbum($userId, $albumId)
{
    global $wordmageDb;

    $userId = (int)$userId;
    $albumId = (int)$albumId;

    if ($albumId <= 0) {
        return [
            'success' => false,
            'status' => 400,
            'error' => 'Invalid album id'
        ];
    }

    try {
        // Get cover path first so we can remove the file after deleting the DB row
        $stmt = $wordmageDb->prepare("
            SELECT id, cover_image_path
            FROM word_albums
            WHERE id = :album_id
              AND user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute([
            ':album_id' => $albumId,
            ':user_id' => $userId
        ]);

        $album = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$album) {
            return [
                'success' => false,
                'status' => 404,
                'error' => 'Album not found'
            ];
        }

        $coverImagePath = $album['cover_image_path'];

        $wordmageDb->beginTransaction();

        $deleteStmt = $wordmageDb->prepare("
            DELETE FROM word_albums
            WHERE id = :album_id
              AND user_id = :user_id
        ");
        $deleteStmt->execute([
            ':album_id' => $albumId,
            ':user_id' => $userId
        ]);

        $wordmageDb->commit();

        // Delete cover file after DB commit
        if (!empty($coverImagePath)) {
            $fullPath = $this->getCoverFilesystemPath($coverImagePath);
            if ($fullPath && file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }

        return [
            'success' => true,
            'status' => 200,
            'data' => [
                'success' => true,
                'album_id' => $albumId,
                'deleted' => true
            ]
        ];

    } catch (\Exception $e) {
        if ($wordmageDb->inTransaction()) {
            $wordmageDb->rollBack();
        }

        return [
            'success' => false,
            'status' => 500,
            'error' => 'Could not delete album: ' . $e->getMessage()
        ];
    }
}

private function getCoverFilesystemPath($coverImagePath)
{
    $coverImagePath = trim((string)$coverImagePath);

    if ($coverImagePath === '') {
        return null;
    }

    // Expected format: /album-covers/album-123.svg
    $relative = ltrim($coverImagePath, '/');

    return __DIR__ . '/../' . $relative;
}

/******************************************************************************
                       CODE GENERATED TO DRAW ALBUM CARDS
 ******************************************************************************/

private function generateAlbumCard($albumId, $title, $moodText, array $wordIds)
{
    global $wordmageDb;

    $albumId = (int)$albumId;
    if ($albumId <= 0 || count($wordIds) === 0) {
        throw new \Exception('Invalid album data for cover generation');
    }

    // Fetch a few words/definitions to feature on the card
    $entries = $this->getAlbumCardEntries($wordIds, 4);

    if (count($entries) === 0) {
        throw new \Exception('No words found for album card');
    }

    $palette = $this->getAlbumPalette($moodText);
    $emblemSvg = $this->getAlbumEmblemSvg($moodText, $palette);

    $svg = $this->buildAlbumCardSvg($entries, $emblemSvg, $palette);

    // Adjust this to match your actual public web root
    $relativePath = '/album-covers/album-' . $albumId . '.svg';
    $publicDir = __DIR__ . '/../album-covers';
    $fullPath = $publicDir . '/album-' . $albumId . '.svg';

    if (!is_dir($publicDir)) {
        if (!mkdir($publicDir, 0775, true) && !is_dir($publicDir)) {
            throw new \Exception('Could not create album cover directory');
        }
    }

    if (file_put_contents($fullPath, $svg) === false) {
        throw new \Exception('Could not write album cover file');
    }

    return $relativePath;
}

private function getAlbumCardEntries(array $wordIds, $maxEntries = 4)
{
    global $wordmageDb;

    $maxEntries = max(1, min((int)$maxEntries, 6));

    $cleanWordIds = [];
    foreach ($wordIds as $wordId) {
        $wordId = (int)$wordId;
        if ($wordId > 0) {
            $cleanWordIds[] = $wordId;
        }
    }

    if (count($cleanWordIds) === 0) {
        return [];
    }

    $cleanWordIds = array_values(array_unique($cleanWordIds));
    $selectedIds = array_slice($cleanWordIds, 0, $maxEntries);

    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $orderField = implode(',', array_map('intval', $selectedIds));

    $sql = "
        SELECT id, word, definition
        FROM word_pool
        WHERE id IN ($placeholders)
        ORDER BY FIELD(id, $orderField)
    ";

    $stmt = $wordmageDb->prepare($sql);
    foreach ($selectedIds as $i => $id) {
        $stmt->bindValue($i + 1, $id, \PDO::PARAM_INT);
    }

    $stmt->execute();

    $entries = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $entries[] = [
            'word' => $row['word'],
            'definition' => $this->shortenDefinition($row['definition'], 90)
        ];
    }

    return $entries;
}

private function shortenDefinition($text, $maxLen = 90)
{
    $text = trim((string)$text);
    $text = preg_replace('/\s+/', ' ', $text);

    if (\mb_strlen($text) <= $maxLen) {
        return $text;
    }

    $short = \mb_substr($text, 0, $maxLen);
    $lastSpace = \mb_strrpos($short, ' ');

    if ($lastSpace !== false) {
        $short = \mb_substr($short, 0, $lastSpace);
    }

    return rtrim($short, " ,;:.") . '…';
}

private function getAlbumPalette($moodText)
{
    $text = \mb_strtolower((string)$moodText);

    // Default parchment / olive / charcoal
    $palette = [
        'bg' => '#f3ead8',
        'band' => '#7a7450',
        'border' => '#d8ccb6',
        'word' => '#2e2925',
        'def' => '#433b35',
        'accent' => '#5f5878'
    ];

    if (strpos($text, 'sea') !== false || strpos($text, 'ocean') !== false || strpos($text, 'water') !== false) {
        return [
            'bg' => '#eef3f2',
            'band' => '#5d7b7d',
            'border' => '#c8d8d7',
            'word' => '#243234',
            'def' => '#34484b',
            'accent' => '#6a8da3'
        ];
    }

    if (strpos($text, 'rose') !== false || strpos($text, 'romance') !== false || strpos($text, 'garden') !== false) {
        return [
            'bg' => '#f6ece8',
            'band' => '#8a6a63',
            'border' => '#dbc6be',
            'word' => '#352726',
            'def' => '#4b3a39',
            'accent' => '#a06d7f'
        ];
    }

    if (
        strpos($text, 'ghost') !== false ||
        strpos($text, 'haunted') !== false ||
        strpos($text, 'eldritch') !== false ||
        strpos($text, 'gothic') !== false ||
        strpos($text, 'poe') !== false
    ) {
        return [
            'bg' => '#f2eadb',
            'band' => '#6e6847',
            'border' => '#d4c7ad',
            'word' => '#2b2725',
            'def' => '#403936',
            'accent' => '#6c6285'
        ];
    }

    return $palette;
}

private function getAlbumEmblemSvg($moodText, array $palette)
{
    $text = \mb_strtolower((string)$moodText);
    $accent = $palette['accent'];
    $word = $palette['word'];

    // A few simple inline SVG emblems. You can add more later.
    if (
        strpos($text, 'ghost') !== false ||
        strpos($text, 'haunted') !== false ||
        strpos($text, 'eldritch') !== false ||
        strpos($text, 'gothic') !== false ||
        strpos($text, 'poe') !== false
    ) {
        return '
            <g transform="translate(90,115)">
                <ellipse cx="0" cy="0" rx="58" ry="74" fill="#e9dfcf" stroke="' . $this->esc($accent) . '" stroke-width="4"/>
                <circle cx="-12" cy="-14" r="28" fill="' . $this->esc($accent) . '" opacity="0.18"/>
                <path d="M-28,34 L0,-28 L28,34 Z" fill="' . $this->esc($word) . '" opacity="0.88"/>
                <rect x="-10" y="-8" width="20" height="42" fill="' . $this->esc($word) . '" opacity="0.88"/>
                <circle cx="-20" cy="44" r="5" fill="' . $this->esc($accent) . '" opacity="0.7"/>
                <circle cx="20" cy="44" r="5" fill="' . $this->esc($accent) . '" opacity="0.7"/>
            </g>
        ';
    }

    if (strpos($text, 'sea') !== false || strpos($text, 'ocean') !== false || strpos($text, 'water') !== false) {
        return '
            <g transform="translate(90,115)">
                <ellipse cx="0" cy="0" rx="58" ry="74" fill="#e9efef" stroke="' . $this->esc($accent) . '" stroke-width="4"/>
                <path d="M-38,18 C-18,-6 8,-6 26,14 C12,10 -4,18 -18,32 C-22,26 -30,20 -38,18 Z"
                      fill="' . $this->esc($word) . '" opacity="0.85"/>
                <path d="M-30,40 C-14,26 10,26 30,40" fill="none" stroke="' . $this->esc($accent) . '" stroke-width="5" stroke-linecap="round"/>
            </g>
        ';
    }

    return '
        <g transform="translate(90,115)">
            <ellipse cx="0" cy="0" rx="58" ry="74" fill="#eee6d8" stroke="' . $this->esc($accent) . '" stroke-width="4"/>
            <path d="M0,-34 L9,-6 L38,-6 L14,11 L23,38 L0,20 L-23,38 L-14,11 L-38,-6 L-9,-6 Z"
                  fill="' . $this->esc($word) . '" opacity="0.88"/>
        </g>
    ';
}

private function buildAlbumCardSvg(array $entries, $emblemSvg, array $palette)
{
    $size = 1024;
    $bandWidth = 190;
    $paddingLeft = 260;
    $paddingTop = 120;

    $bg = $this->esc($palette['bg']);
    $band = $this->esc($palette['band']);
    $border = $this->esc($palette['border']);
    $wordColor = $this->esc($palette['word']);
    $defColor = $this->esc($palette['def']);

    $svg = [];
    $svg[] = '<?xml version="1.0" encoding="UTF-8"?>';
    $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '">';
    $svg[] = '<rect x="8" y="8" width="1008" height="1008" rx="28" fill="' . $bg . '" stroke="' . $border . '" stroke-width="4"/>';
    $svg[] = '<rect x="8" y="8" width="' . $bandWidth . '" height="1008" rx="28" fill="' . $band . '"/>';
    $svg[] = '<rect x="180" y="8" width="10" height="1008" fill="rgba(255,255,255,0.08)"/>';
    $svg[] = $emblemSvg;

    $y = $paddingTop;

    foreach ($entries as $entry) {
        $word = $this->esc($entry['word']);
        $definition = $entry['definition'];

        $svg[] = '<text x="' . $paddingLeft . '" y="' . $y . '" fill="' . $wordColor . '" font-family="Georgia, Times New Roman, serif" font-size="46" font-weight="700">'
            . $word . '.</text>';

        $y += 58;

        $lines = $this->wrapTextLines($definition, 32);
        $maxDefLines = 3;
        $lines = array_slice($lines, 0, $maxDefLines);
        $lastIndex = count($lines) - 1;

        foreach ($lines as $i => $line) {
            $lineText = $this->esc($line);

            if ($i === $lastIndex && count($this->wrapTextLines($definition, 32)) > $maxDefLines) {
                $lineText = rtrim($lineText, " ,;:.") . '…';
            }

            $svg[] = '<text x="' . $paddingLeft . '" y="' . $y . '" fill="' . $defColor . '" font-family="Georgia, Times New Roman, serif" font-size="28" font-weight="400">'
                . $lineText . '</text>';
            $y += 40;
        }

        $y += 38;
    }

    $svg[] = '</svg>';

    return implode("\n", $svg);
}

private function wrapTextLines($text, $maxChars = 32)
{
    $text = trim((string)$text);
    if ($text === '') {
        return [];
    }

    $words = preg_split('/\s+/', $text);
    $lines = [];
    $line = '';

    foreach ($words as $word) {
        $test = ($line === '') ? $word : $line . ' ' . $word;
        if (\mb_strlen($test) <= $maxChars) {
            $line = $test;
        } else {
            if ($line !== '') {
                $lines[] = $line;
            }
            $line = $word;
        }
    }

    if ($line !== '') {
        $lines[] = $line;
    }

    return $lines;
}

private function esc($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

private function updateAlbumCoverPath($albumId, $coverPath)
{
    global $wordmageDb;

    $stmt = $wordmageDb->prepare("
        UPDATE word_albums
        SET cover_image_path = :cover_image_path
        WHERE id = :id
    ");

    $stmt->execute([
        ':cover_image_path' => $coverPath,
        ':id' => (int)$albumId
    ]);
}


public function addWordToAlbumFront($albumId, $wordId)
{
    global $wordmageDb;

    try {
        $wordmageDb->beginTransaction();

        $sql = "
            SELECT position
            FROM word_album_items
            WHERE album_id = :album_id
              AND word_id = :word_id
            LIMIT 1
        ";
        $stmt = $wordmageDb->prepare($sql);
        $stmt->execute([
            ':album_id' => $albumId,
            ':word_id' => $wordId
        ]);

        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {
            $oldPosition = (int)$existing['position'];

            if ($oldPosition > 1) {
                $sql = "
                    UPDATE word_album_items
                    SET position = position + 1
                    WHERE album_id = :album_id
                      AND position < :old_position
                    ORDER BY position DESC
                ";
                $stmt = $wordmageDb->prepare($sql);
                $stmt->execute([
                    ':album_id' => $albumId,
                    ':old_position' => $oldPosition
                ]);

                $sql = "
                    UPDATE word_album_items
                    SET position = 1
                    WHERE album_id = :album_id
                      AND word_id = :word_id
                ";
                $stmt = $wordmageDb->prepare($sql);
                $stmt->execute([
                    ':album_id' => $albumId,
                    ':word_id' => $wordId
                ]);
            }

            $wordmageDb->commit();

            return [
                'message' => $oldPosition === 1
                    ? 'Word is already at the front of the album'
                    : 'Word moved to front'
            ];
        }

        $sql = "
            UPDATE word_album_items
            SET position = position + 1
            WHERE album_id = :album_id
            ORDER BY position DESC
        ";
        $stmt = $wordmageDb->prepare($sql);
        $stmt->execute([
            ':album_id' => $albumId
        ]);

        $sql = "
            INSERT INTO word_album_items (album_id, word_id, position)
            VALUES (:album_id, :word_id, 1)
        ";
        $stmt = $wordmageDb->prepare($sql);
        $stmt->execute([
            ':album_id' => $albumId,
            ':word_id' => $wordId
        ]);

        $wordmageDb->commit();

        return ['message' => 'Word added to front'];

    } catch (\Throwable $e) {
        if ($wordmageDb->inTransaction()) {
            $wordmageDb->rollBack();
        }

        throw $e;
    }
}


public function removeWordFromAlbum($albumId, $wordId)
{
    global $wordmageDb;

    try {
        $wordmageDb->beginTransaction();

        $sql = "
            SELECT position
            FROM word_album_items
            WHERE album_id = :album_id
              AND word_id = :word_id
            LIMIT 1
        ";

        $stmt = $wordmageDb->prepare($sql);
        $stmt->execute([
            ':album_id' => $albumId,
            ':word_id' => $wordId
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $wordmageDb->rollBack();
            return ['message' => 'Word not found in album'];
        }

        $position = (int)$row['position'];

        $sql = "
            DELETE FROM word_album_items
            WHERE album_id = :album_id
              AND word_id = :word_id
        ";

        $stmt = $wordmageDb->prepare($sql);
        $stmt->execute([
            ':album_id' => $albumId,
            ':word_id' => $wordId
        ]);

        $sql = "
            UPDATE word_album_items
            SET position = position - 1
            WHERE album_id = :album_id
              AND position > :position
            ORDER BY position ASC
        ";

        $stmt = $wordmageDb->prepare($sql);
        $stmt->execute([
            ':album_id' => $albumId,
            ':position' => $position
        ]);

        $wordmageDb->commit();

        return ['message' => 'Word removed from album'];

    } catch (\Throwable $e) {
        if ($wordmageDb->inTransaction()) {
            $wordmageDb->rollBack();
        }

        throw $e;
    }
}


    public function setAlbumWordLockStatus($userId, $albumId, $wordId, $isLocked)
    {
        global $wordmageDb;

        $albumId = (int)$albumId;
        $wordId = (int)$wordId;
        $isLocked = $isLocked ? 1 : 0;

        if ($albumId <= 0 || $wordId <= 0) {
            return [
                'success' => false,
                'message' => 'Invalid album ID or word ID.'
            ];
        }

        // First verify that the album belongs to the user.
        $albumSql = "
            SELECT id
            FROM word_albums
            WHERE id = :album_id
              AND user_id = :user_id
            LIMIT 1
        ";
        $albumStmt = $wordmageDb->prepare($albumSql);
        $albumStmt->bindValue(':album_id', $albumId, \PDO::PARAM_INT);
        $albumStmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $albumStmt->execute();

        $album = $albumStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$album) {
            return [
                'success' => false,
                'message' => 'Album not found or access denied.'
            ];
        }

        // Verify that the word is actually in the album.
        $itemSql = "
            SELECT album_id, word_id, is_locked
            FROM word_album_items
            WHERE album_id = :album_id
              AND word_id = :word_id
            LIMIT 1
        ";
        $itemStmt = $wordmageDb->prepare($itemSql);
        $itemStmt->bindValue(':album_id', $albumId, \PDO::PARAM_INT);
        $itemStmt->bindValue(':word_id', $wordId, \PDO::PARAM_INT);
        $itemStmt->execute();

        $item = $itemStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$item) {
            return [
                'success' => false,
                'message' => 'Word not found in album.'
            ];
        }

        // If already in desired state, just return success.
        if ((int)$item['is_locked'] === $isLocked) {
            return [
                'success' => true,
                'message' => $isLocked ? 'Word already locked.' : 'Word already unlocked.',
                'data' => [
                    'album_id' => $albumId,
                    'word_id' => $wordId,
                    'is_locked' => $isLocked
                ]
            ];
        }

        $updateSql = "
            UPDATE word_album_items
            SET is_locked = :is_locked
            WHERE album_id = :album_id
              AND word_id = :word_id
            LIMIT 1
        ";
        $updateStmt = $wordmageDb->prepare($updateSql);
        $updateStmt->bindValue(':is_locked', $isLocked, \PDO::PARAM_INT);
        $updateStmt->bindValue(':album_id', $albumId, \PDO::PARAM_INT);
        $updateStmt->bindValue(':word_id', $wordId, \PDO::PARAM_INT);

        $ok = $updateStmt->execute();

        if (!$ok) {
            return [
                'success' => false,
                'message' => 'Failed to update lock status.'
            ];
        }

        return [
            'success' => true,
            'message' => $isLocked ? 'Word locked.' : 'Word unlocked.',
            'data' => [
                'album_id' => $albumId,
                'word_id' => $wordId,
                'is_locked' => $isLocked
            ]
        ];
    }

}
