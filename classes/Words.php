<?php
namespace WordMage;

class Words {

    public function getRandomWords($count = 20)
    {
        global $wordmageDb;

        if (!$wordmageDb) {
            return [];
        }

        $count = (int)$count;
        if ($count < 1) {
            $count = 20;
        }
        if ($count > 100) {
            $count = 100;
        }

        try {
            $sql = "
                SELECT wp.id, wp.word, wp.definition, wp.source
                FROM word_pool wp
                ORDER BY RAND()
                LIMIT {$count}
            ";

            $stmt = $wordmageDb->prepare($sql);
            $stmt->execute();

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $wordlist = [];
            foreach ($rows as $row) {
                $wordlist[] = [
                    'id' => (int)$row['id'],
                    'word' => $row['word'],
                    'def' => $row['definition'],
                    'source' => $row['source']
                ];
            }

            return $wordlist;
        } catch (Exception $e) {
            throw $e;
        }
    }


    public function getWordsPage($startsWith = 'a', $afterWord = '', $limit = 50)
    {
        global $wordmageDb;

        if (!$wordmageDb) {
            return [
                'words' => [],
                'has_more' => false,
                'next_cursor' => null
            ];
        }

        $startsWith = trim((string)$startsWith);
        $afterWord = trim((string)$afterWord);
        $limit = (int)$limit;

        if ($startsWith === '') {
            $startsWith = 'a';
        }

        if ($limit < 1) {
            $limit = 50;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        try {
            $params = [];

            if ($afterWord !== '') {
                $sql = "
                    SELECT id, word, definition, source
                    FROM word_pool
                    WHERE word > :after_word
                    ORDER BY word ASC
                    LIMIT " . ($limit + 1);

                $params['after_word'] = $afterWord;
            } else {
                $sql = "
                    SELECT id, word, definition, source
                    FROM word_pool
                    WHERE word >= :starts_with
                    ORDER BY word ASC
                    LIMIT " . ($limit + 1);

                $params['starts_with'] = $startsWith;
            }

            $stmt = $wordmageDb->prepare($sql);
            $stmt->execute($params);

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $hasMore = count($rows) > $limit;
            if ($hasMore) {
                array_pop($rows);
            }

            $wordlist = [];
            foreach ($rows as $row) {
                $item = [
                    'id' => (int)$row['id'],
                    'word' => $row['word'],
                    'def' => $row['definition'],
                    'source' => $row['source']
                ];

                if (json_encode($item)) {
                    $wordlist[] = $item;
                }
            }

            $nextCursor = null;
            if ($hasMore && !empty($wordlist)) {
                $last = end($wordlist);
                $nextCursor = $last['word'];
            }

            return [
                'words' => $wordlist,
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }



	function getWordPool() {
		global $wordmageDb;
		$sql = "SELECT id, word, definition, source FROM word_pool ORDER BY word";

		$wordlist = array();
		if ($wordmageDb) {
			$stmt = $wordmageDb->prepare($sql);
			$stmt->bindColumn(1, $id);
			$stmt->bindColumn(2, $word);
			$stmt->bindColumn(3, $definition);
			$stmt->bindColumn(4, $source);
			$params = array();
			if ($stmt->execute($params)) {
				while ($stmt->fetch()) {
				    $item = array('id' => $id, 'word' => $word, 'def' => $definition, 'source' => $source);
					if (json_encode($item)) {
						$wordlist[] = $item;
					}
				}
				return $wordlist;
			}
		}
		else {
			return array();
		}
	}


	function getCollective() {
		global $wordmageDb;
		$sql = "SELECT id, term, refers_to, expression, source, highlight FROM collective_nouns ORDER BY term";

		$wordlist = array();
		if ($wordmageDb) {
			$stmt = $wordmageDb->prepare($sql);
			$stmt->bindColumn(1, $id);
			$stmt->bindColumn(2, $term);
			$stmt->bindColumn(3, $refersTo);
			$stmt->bindColumn(4, $expression);
			$stmt->bindColumn(5, $source);
			$stmt->bindColumn(6, $highlight);
			$params = array();
			if ($stmt->execute($params)) {
				while ($stmt->fetch()) {
				    $item = array('term' => $term, 'refersTo' => $refersTo, 'expression' => $expression, 'source' => $source, 'highlight' => !!$highlight);
					if (json_encode($item)) {
						$wordlist[] = $item;
					}
				}
				return $wordlist;
			}
		}
		else {
			return array();
		}
	}


	function getWordsByMood($slug, $limit = 13) {
            global $wordmageDb;

            $sql = "
                SELECT wp.id, wp.word, wp.definition, wp.source
                FROM moods m
                JOIN mood_top_words mtw
                  ON mtw.mood_id = m.id
                 AND mtw.model = 'text-embedding-3-small'
                JOIN word_pool wp
                  ON wp.id = mtw.word_id
                WHERE m.slug = :slug
                  AND mtw.rank_num <= 75
                ORDER BY RAND()
                LIMIT :limit
            ";

            $wordlist = array();

            if ($wordmageDb) {
                $stmt = $wordmageDb->prepare($sql);

                $stmt->bindValue(':slug', $slug, \PDO::PARAM_STR);
                $stmt->bindValue(':limit', (int)$limit, \PDO::PARAM_INT);

                if ($stmt->execute()) {
                    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                        $item = array(
                            'id' => $row['id'],
                            'word' => $row['word'],
                            'def' => $row['definition'],
                            'source' => $row['source']
                        );

                        if (json_encode($item)) {
                            $wordlist[] = $item;
                        }
                    }
                }
            }

            return $wordlist;
        }

	function getMoods() {
            global $wordmageDb;

            $sql = "SELECT slug, label, description FROM moods ORDER BY label";

            $list = array();
            if ($wordmageDb) {
                $stmt = $wordmageDb->prepare($sql);
                if ($stmt->execute()) {
                    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                        $list[] = array(
                            'slug' => $row['slug'],
                            'label' => $row['label'],
                            'description' => $row['description'],
                        );
                    }
                }
            }
            return $list;
        }

}
