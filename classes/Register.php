<?php
namespace WordMage;

class Register {

	function automationWebhook($data) {
		$hookUrl = AUTOMATION_WEBHOOK;
		$apiKey = X_MAKE_APIKEY;
		error_log("====> automationWebhook: $hookUrl, $apiKey; " . json_encode($data));
		$ch = curl_init($hookUrl);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			"x-make-apikey: $apiKey"
		));

		$response = curl_exec($ch);
		error_log("====> automationWebhook response: " . json_encode($response));
		curl_close($ch);

		// Optionally log or handle errors here
		return $response;
	}

	function emailIsAvailable($email) {
error_log('Called EmailIsAvailable ' . $email);
		global $wordmageDb;

		$sql = "SELECT id FROM users WHERE email=:email";
		$stmt = $wordmageDb->prepare($sql);
		$stmt->bindColumn(1, $id);
		$params = array('email' => $email);
		$stmt->execute($params);

		if ($stmt->fetch()) {
			error_log("EmailIsAvailable: $email already used; id $id");
			$result = false;
		}
		else {
			error_log("EmailIsAvailable: $email not in use.");
			$result = true;
		}
		return $result;
	}


	function registerEmail($email, $password) {
		global $wordmageDb;

		$sql = "INSERT INTO users (email, password) VALUES (:email, :password)";
		$stmt = $wordmageDb->prepare($sql);
		$params = array(':email' => $email, ':password' => $password);
		$stmt->execute($params);
		$id = $wordmageDb->lastInsertId();
		return $id;
	}


	function registerCustom($user_id, $custom) {
		global $wordmageDb;

		$sql = "INSERT INTO user_custom (user_id, custom) VALUES (:user_id, :custom)";
		$stmt = $wordmageDb->prepare($sql);
		$params = array(':user_id' => $user_id, ':custom' => $custom);
		$result = $stmt->execute($params);
		if ($result) {
			$newRecId = $wordmageDb->lastInsertId();
			return $newRecId;
		}
		else {
			return false;
		}
	}

/*
	function loadCustom($user_id) {
		global $wordmageDb;
		$result = [];
		if ($wordmageDb) {
			$sql = "SELECT custom, training FROM user_custom WHERE user_id=:user_id";
			$stmt = $wordmageDb->prepare($sql);
			$stmt->bindColumn(1, $custom);
			$stmt->bindColumn(2, $training);
			$params = array(':user_id' => $user_id);
			$stmt->execute($params);
			if ($stmt->fetch()) {
				$result = array('custom' => json_decode($custom), 'training' => json_decode($training));
			}
		}
		return $result;
	}
*/


        function loadCustom($user_id) {
            global $wordmageDb;

            $result = [
                'custom' => (object)[],
                'training' => (object)[],
                'custom_moods' => []
            ];

            if (!$wordmageDb) return $result;

            // 1) Load existing JSON blobs
            $sql = "SELECT custom, training FROM user_custom WHERE user_id=:user_id LIMIT 1";
            $stmt = $wordmageDb->prepare($sql);
            $stmt->execute([':user_id' => (int)$user_id]);
        
            if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $result['custom'] = $row['custom'] ? json_decode($row['custom']) : (object)[];
                $result['training'] = $row['training'] ? json_decode($row['training']) : (object)[];
            }
        
            // 2) Load recent custom moods (for button list / reuse)
            $stmt2 = $wordmageDb->prepare("
                SELECT id, mood_text, last_used_at, use_count
                FROM custom_moods
                WHERE user_id = :user_id
                ORDER BY last_used_at DESC, id DESC
                LIMIT 50
            ");
            $stmt2->execute([':user_id' => (int)$user_id]);
        
            $result['custom_moods'] = [];
            while ($m = $stmt2->fetch(\PDO::FETCH_ASSOC)) {
                $result['custom_moods'][] = [
                    'id' => (int)$m['id'],
                    'text' => $m['mood_text'],
                    'last_used_at' => $m['last_used_at'],
                    'use_count' => (int)$m['use_count'],
                ];
            }

            return $result;
        }


	function saveCustom($user_id, $custom, $wordObj = null) {
		global $wordmageDb;
		$sql = "UPDATE user_custom SET custom=:custom WHERE user_id=:user_id";
		$stmt = $wordmageDb->prepare($sql);
		$params = array(':user_id' => $user_id, ':custom' => $custom);
		$result = $stmt->execute($params);
		if ($wordObj) {
			$this->automationWebhook($wordObj);
		}
		return $result;
	}


	function saveTraining($user_id, $training) {
		global $wordmageDb;
		$sql = "UPDATE user_custom SET training=:training WHERE user_id=:user_id";
		$stmt = $wordmageDb->prepare($sql);
		$params = array(':user_id' => $user_id, ':training' => $training);
		$result = $stmt->execute($params);
		return $result;
	}


	function login($email, $password, $auth = false) {
		global $wordmageDb;

		if ($auth) {
			$sql = "
				SELECT user_id, custom FROM user_custom
				JOIN users u ON user_id=u.id
				WHERE email=:email 
			";
			$stmt = $wordmageDb->prepare($sql);
			$stmt->bindColumn(1, $user_id);
			$stmt->bindColumn(2, $custom);
			$params = array(':email' => $email);
		} else {
			$sql = "
				SELECT user_id, custom FROM users u
				JOIN user_custom ON user_id=u.id
				WHERE email=:email 
				AND password=:password";
			$stmt = $wordmageDb->prepare($sql);
			$stmt->bindColumn(1, $user_id);
			$stmt->bindColumn(2, $custom);
			$params = array(':email' => $email, ':password' => $password);
		}
		$stmt->execute($params);
		$payload = array();
		if ($stmt->fetch()) {
			$payload['user_id'] = $user_id;
			$payload['custom'] = $custom;
		}
		else {
			$payload['user_id'] = -1;
		}
		return $payload;
	}

	function addWord($payload) {
		global $wordmageDb;
		$status = '';
		// Sanitize payload
		$word = isset($payload['word']) ? trim(filter_var($payload['word'], FILTER_SANITIZE_STRING)) : '';
		$definition = isset($payload['definition']) ? trim(filter_var($payload['definition'], FILTER_SANITIZE_STRING)) : '';
		$source = isset($payload['source']) ? trim(filter_var($payload['source'], FILTER_SANITIZE_STRING)) : '';

		$wordRE = "/^[\p{L}](?:[\p{L}'\- ]*[\p{L}])?$/u";
		if (!preg_match($wordRE, $word)) {
			$status = "invalid word: $word";
			return $status;
		}

		if (strlen($definition) > 1000 || strlen($source) > 255) {
			$status = 'input too long';
			return $status;
		}

		if ($wordmageDb) {
			// Check for existing word
			$sql = "SELECT id FROM word_pool WHERE word = :word";
			$stmt = $wordmageDb->prepare($sql);
			$stmt->bindValue(':word', $word);
			$stmt->execute();

			if ($stmt->rowCount() > 0) {
				$status = 'already in list';
			} else {
				$status = 'success';
				// Insert new word
				/*
				$insertSql = "INSERT INTO word_pool (word, definition, source) VALUES (:word, :definition, :source)";
				$insertStmt = $wordmageDb->prepare($insertSql);
				$insertStmt->bindValue(':word', $word);
				$insertStmt->bindValue(':definition', $definition);
				$insertStmt->bindValue(':source', $source);
				if ($insertStmt->execute()) {
					$status = 'success';
				} else {
					$status = 'insert failed';
				}
				 */
			}
		} else {
			$status = 'db connection failed';
		}

		// Return JSON response
		return $status;
	}


}
