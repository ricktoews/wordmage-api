<?php

namespace WordMage;

class UserWordLearning
{
    public static function getUnscrambleQueue($request, $response, $args)
    {
        global $wordmageDb;

        $userId = self::getUserId($request);
        $albumId = isset($args['album_id']) ? (int)$args['album_id'] : 10;
        $limit = 10;

        $sql = "
            SELECT
                w.id,
                w.word,
                w.definition,
                w.source,

                uwl.times_seen,
                uwl.times_recalled_correctly,
                uwl.times_failed,
                uwl.streak,
                uwl.last_reviewed,
                uwl.next_review_at
            FROM word_album_items wai
            JOIN word_pool w
              ON w.id = wai.word_id
            LEFT JOIN user_word_learning uwl
              ON uwl.word_id = wai.word_id
             AND uwl.user_id = ?
            WHERE wai.album_id = ?
              AND (
                    uwl.next_review_at IS NULL
                 OR uwl.next_review_at <= NOW()
              )
            ORDER BY
                CASE WHEN uwl.next_review_at IS NULL THEN 0 ELSE 1 END,
                uwl.next_review_at ASC,
                RAND()
            LIMIT ?
        ";

        $stmt = $wordmageDb->prepare($sql);

    	$stmt->bindValue(1, $userId, \PDO::PARAM_INT);
    	$stmt->bindValue(2, $albumId, \PDO::PARAM_INT);
    	$stmt->bindValue(3, $limit, \PDO::PARAM_INT);

    	$stmt->execute();

    	$words = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $response->withJson([
            "success" => true,
            "album_id" => $albumId,
            "words" => $words
        ]);
    }

    public static function recordUnscrambleAttempt($request, $response, $args)
    {
        global $wordmageDb;

        $userId = self::getUserId($request);
        $data = json_decode($request->getBody()->getContents(), true);

        $wordId = isset($data['word_id']) ? (int)$data['word_id'] : 0;
        $result = isset($data['result']) ? $data['result'] : '';

        if (!$wordId || !in_array($result, ['correct', 'failed', 'revealed', 'skipped'])) {
            return $response->withJson([
                "success" => false,
                "error" => "Invalid word_id or result."
            ], 400);
        }

        $existing = self::getLearningRow($userId, $wordId);

        $timesSeen = $existing ? (int)$existing['times_seen'] : 0;
        $timesCorrect = $existing ? (int)$existing['times_recalled_correctly'] : 0;
        $timesFailed = $existing ? (int)$existing['times_failed'] : 0;
        $streak = $existing ? (int)$existing['streak'] : 0;

        $timesSeen++;

        if ($result === 'correct') {
            $timesCorrect++;
            $streak++;
        } else {
            $timesFailed++;
            $streak = 0;
        }

        $nextReviewAt = self::calculateNextReviewAt($result, $streak);

        $sql = "
            INSERT INTO user_word_learning (
                user_id,
                word_id,
                times_seen,
                times_recalled_correctly,
                times_failed,
                streak,
                last_reviewed,
                next_review_at
            )
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
                times_seen = VALUES(times_seen),
                times_recalled_correctly = VALUES(times_recalled_correctly),
                times_failed = VALUES(times_failed),
                streak = VALUES(streak),
                last_reviewed = NOW(),
                next_review_at = VALUES(next_review_at)
        ";

        $stmt = $wordmageDb->prepare($sql);
        $stmt->bind_param(
            "iiiiiis",
            $userId,
            $wordId,
            $timesSeen,
            $timesCorrect,
            $timesFailed,
            $streak,
            $nextReviewAt
        );
        $stmt->execute();

        return $response->withJson([
            "success" => true,
            "word_id" => $wordId,
            "result" => $result,
            "learning_state" => [
                "times_seen" => $timesSeen,
                "times_recalled_correctly" => $timesCorrect,
                "times_failed" => $timesFailed,
                "streak" => $streak,
                "last_reviewed" => date("Y-m-d H:i:s"),
                "next_review_at" => $nextReviewAt
            ]
        ]);
    }

    private static function getLearningRow($userId, $wordId)
    {
        global $wordmageDb;

        $sql = "
            SELECT *
            FROM user_word_learning
            WHERE user_id = ?
              AND word_id = ?
            LIMIT 1
        ";

        $stmt = $wordmageDb->prepare($sql);
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $wordId, \PDO::PARAM_INT);

    	$stmt->execute();

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function calculateNextReviewAt($result, $streak)
    {
        if ($result !== 'correct') {
            return date("Y-m-d H:i:s", strtotime("+1 day"));
        }

        if ($streak <= 1) {
            $days = 2;
        } elseif ($streak === 2) {
            $days = 4;
        } elseif ($streak === 3) {
            $days = 7;
        } elseif ($streak === 4) {
            $days = 14;
        } else {
            $days = 30;
        }

        return date("Y-m-d H:i:s", strtotime("+{$days} days"));
    }

    private static function getUserId($request)
    {
        // Adjust this to match however WordMage currently identifies users.
        // Common possibilities:
        // return (int)$request->getAttribute('user_id');
        // return (int)$_SESSION['user_id'];

        return 4;
    }
}
