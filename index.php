<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use WordMage\Register as Register;
use WordMage\Capture as Capture;
use WordMage\Locate as Locate;
use WordMage\Words as Words;

require '/etc/php/connect/wordmage/pdo.php';
require '/etc/php/connect/wordmage/const.php';
require __DIR__ . '/vendor/autoload.php';

$app = new \Slim\App;

//-----------------------------------------------------------------------------
// CORS
//-----------------------------------------------------------------------------
$app->add(function ($request, $response, $next) {
	$newResponse = $response
		->withHeader('Access-Control-Allow-Origin', '*')
		->withHeader('Access-Control-Allow-Headers', array('Content-Type', 'X-Requested-With', 'Authorization'))
		->withHeader('Access-Control-Allow-Methods', array('GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'));

	if ($request->isOptions()) {
		return $newResponse;
	}

	return $next($request, $newResponse);
});

$app->post('/login', 'login');
$app->post('/register', 'register');
$app->post('/loadcustom', 'loadCustom');
$app->post('/savecustom', 'saveCustom');
$app->post('/savetraining', 'saveTraining');
$app->post('/capture', 'extractWordsFromPage');
$app->post('/locate', 'locate');
$app->post('/test-image-analysis', 'testImageAnalysis');

$app->get('/', function() {
	global $wordmageDb;
	$sql = "SELECT id, word, definition, source FROM word_pool ORDER BY word";

	$wordlist = array();
	error_log("====> wordmage /; wordmageDb: " . json_encode($wordmageDb));
	if ($wordmageDb) {
		$stmt = $wordmageDb->prepare($sql);
		$stmt->bindColumn(1, $id);
		$stmt->bindColumn(2, $word);
		$stmt->bindColumn(3, $definition);
		$stmt->bindColumn(4, $source);
		$params = array();
		if ($stmt->execute($params)) {
			while ($stmt->fetch()) {
			    $item = array('word' => $word, 'def' => $definition, 'source' => $source);
				if (json_encode($item)) {
					$wordlist[] = $item;
				}
			}
			echo json_encode($wordlist);
		}
	}
	else {
		echo json_encode(array());
	}
});

$app->post('/import-words', 'importSpreadsheetWords');
function importSpreadsheetWords(Request $request, Response $response) {
	$capture = new Capture();

	$payload = $capture->importWords($request, $response);
	return $response
		->withHeader('Content-Type', 'application/json')
		->write(json_encode($payload));
}

$app->post('/import-words-from-input', function() {
    global $wordmageDb;

    header('Content-Type: application/json');

    if (!$wordmageDb) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection not available']);
        return;
    }

    // Optional super-basic “auth” so random people can’t post
    $apiKeyHeader = isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : null;
    $expectedKey  = getenv('WORDMAGE_ADMIN_API_KEY'); // set this in your env / config

    if ($expectedKey && $apiKeyHeader !== $expectedKey) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }

    // Read raw body and decode JSON
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode([
            'error'   => 'Expected JSON array of word objects',
            'example' => [
                ['word' => 'auroral', 'definition' => 'Pertaining to dawn.', 'source' => 'manual import']
            ]
        ]);
        return;
    }

    $sql = "INSERT INTO word_pool (word, definition, source)
            VALUES (:word, :definition, :source)";
    $stmt = $wordmageDb->prepare($sql);

    $inserted = 0;
    $errors   = [];

    foreach ($data as $index => $item) {
        if (!isset($item['word']) || !is_string($item['word']) || trim($item['word']) === '') {
            $errors[] = "Item #$index is missing a valid 'word' field.";
            continue;
        }

        $word       = trim($item['word']);
        $definition = isset($item['def']) ? trim($item['def']) : null;
        $source     = isset($item['source']) ? trim($item['source']) : null;

        try {
            $stmt->execute([
                ':word'       => $word,
                ':definition' => $definition,
                ':source'     => $source,
            ]);
            $inserted++;
        } catch (PDOException $e) {
            $errors[] = "Error inserting '{$word}' at index {$index}: " . $e->getMessage();
        }
    }

    echo json_encode([
        'inserted' => $inserted,
        'total'    => count($data),
        'errors'   => $errors,
    ]);
});


$app->post('/words/sendlocal', 'wordsSendLocal');
$app->get('/words/retrievelocal/{code}', 'wordsRetrieveLocal');
$app->post('/words/add', 'addWord');
$app->get('/get-words', 'getWords');
$app->get('/get-words-page', 'getWordsPage');
$app->post('/get-random-page-data', 'getRandomPageData');
$app->get('/words/mood/{slug}', function ($request, $response, $args) {
    $words = new \WordMage\Words();
    $results = $words->getWordsByMood($args['slug'], 13);
    return $response->withJson($results);
});
$app->get('/moods', function ($request, $response) {
    $words = new \WordMage\Words();
    return $response->withJson($words->getMoods());
});

$app->get('/custom-mood/{mood_text}', function ($request, $response, $args) {
    $customMoods = new \WordMage\CustomMoods();
    $results = $customMoods->getWordsByCustomText($args['mood_text'], 13);
    return $response->withJson($results);
});

$app->post('/custom-mood', function ($request, $response) {
    $data = $request->getParsedBody();

    $customMoodId = isset($data['custom_mood_id']) ? (int)$data['custom_mood_id'] : 0;
    $limit = isset($data['limit']) ? (int)$data['limit'] : 13;
    $exclude_word_ids = isset($data['exclude_word_ids']) && is_array($data['exclude_word_ids']) ? $data['exclude_word_ids'] : [];

    $customMoods = new \WordMage\CustomMoods();

    if ($customMoodId > 0) {
        $embedding = $customMoods->getMoodEmbeddingById($customMoodId, 4);
        if ($embedding === null) {
            return $response->withStatus(404)
                            ->withJson(['error' => 'Custom mood not found']);
        }
    }

    $finalWords = [];
    $position = 1;

    $freshWords = $customMoods->findWordsForMoodText([], 100, $embedding);

    foreach ($freshWords as $word) {
        $finalWords[] = [
            'id' => (int)$word['id'],
            'is_locked' => 0,
            'position' => $position++
        ];
    }

    return $response->withJson($finalWords);
});

$app->put('/custom-moods/{id}', function ($request, $response, $args) {
    $data = json_decode($request->getBody()->getContents(), true);

    $userId = 4; // replace later with actual logged-in user id
    $moodId = isset($args['id']) ? (int)$args['id'] : 0;
    $moodText = isset($data['mood_text']) ? $data['mood_text'] : '';

    $customMoods = new \WordMage\CustomMoods();
    $result = $customMoods->updateCustomMood($userId, $moodId, $moodText);

    if (!$result['success']) {
        return $response->withStatus($result['status'])->withJson([
            'error' => $result['error']
        ]);
    }

    return $response->withJson($result['data']);
});

$app->delete('/custom-moods/{id}', function ($request, $response, $args) {
    $userId = 4; // replace later with actual logged-in user id
    $moodId = isset($args['id']) ? (int)$args['id'] : 0;

    $customMoods = new \WordMage\CustomMoods();
    $result = $customMoods->deleteCustomMood($userId, $moodId);

    if (!$result['success']) {
        return $response->withStatus($result['status'])->withJson([
            'error' => $result['error']
        ]);
    }

    return $response->withJson($result['data']);
});

$app->get('/albums', function ($request, $response) {
    $userId = 4; // replace later with actual logged-in user id
    $limit = $request->getParam('limit', 50);

    $albums = new \WordMage\WordAlbums();
    $result = $albums->getAlbumsByUser($userId, $limit);

    if (!$result['success']) {
        return $response->withStatus($result['status'])->withJson([
            'error' => $result['error']
        ]);
    }

    return $response->withJson($result['data']);
});

$app->get('/albums/{id}', function ($request, $response, $args) {
    $userId = 4; // replace later with actual logged-in user id
    $albumId = isset($args['id']) ? (int)$args['id'] : 0;

    $albums = new \WordMage\WordAlbums();
    $result = $albums->getAlbumById($userId, $albumId);

    if (!$result['success']) {
        return $response->withStatus($result['status'])->withJson([
            'error' => $result['error']
        ]);
    }

    return $response->withJson($result['data']);
});

$app->post('/albums', function ($request, $response) {
    $data = json_decode($request->getBody()->getContents(), true);

    $userId = 4; // replace later with actual logged-in user id
    $title = isset($data['title']) ? trim($data['title']) : '';
    $moodText = isset($data['mood_text']) ? trim($data['mood_text']) : null;
    $wordIds = isset($data['word_ids']) && is_array($data['word_ids']) ? $data['word_ids'] : [];

    if ($title === '') {
        return $response->withStatus(400)->withJson(['error' => 'title is required']);
    }

    /*
    if (count($wordIds) === 0) {
        return $response->withStatus(400)->withJson(['error' => 'word_ids is required']);
    }
    */

    $albums = new \WordMage\WordAlbums();
    $result = $albums->createAlbum($userId, $title, $moodText, $wordIds);

    if (!$result['success']) {
        return $response->withStatus($result['status'])->withJson([
            'error' => $result['error']
        ]);
    }

    return $response->withJson($result['data']);
});


$app->put('/albums/{id}', function ($request, $response, $args) {
    $data = json_decode($request->getBody()->getContents(), true);

    $userId = 4; // replace later with actual logged-in user id
    $albumId = isset($args['id']) ? (int)$args['id'] : 0;
    $title = isset($data['title']) ? trim($data['title']) : '';
    $moodText = isset($data['mood_text']) ? trim($data['mood_text']) : '';
    $words = isset($data['words']) && is_array($data['words']) ? $data['words'] : [];

    $albums = new \WordMage\WordAlbums();
    $result = $albums->updateAlbum($userId, $albumId, $title, $moodText, $words);

    if (!$result['success']) {
        return $response->withStatus($result['status'])->withJson([
            'error' => $result['error']
        ]);
    }

    return $response->withJson($result['data']);
});

$app->patch('/albums/{id}/mood-text', function ($request, $response, $args) {
    $data = json_decode($request->getBody()->getContents(), true);

    $userId = 4; // replace later with actual logged-in user id
    $albumId = isset($args['id']) ? (int)$args['id'] : 0;
    $moodText = isset($data['mood_text']) ? trim((string)$data['mood_text']) : '';

    $albums = new \WordMage\WordAlbums();
    $result = $albums->updateAlbumMoodText($userId, $albumId, $moodText);

    if (!$result['success']) {
        return $response->withStatus($result['status'])->withJson([
            'error' => $result['error']
        ]);
    }

    return $response->withJson($result['data']);
});

$app->post('/albums/{id}/refresh', function ($request, $response, $args) {
    $albumId = isset($args['id']) ? (int)$args['id'] : 0;

    $albums = new \WordMage\WordAlbums();
    $result = $albums->refreshAlbum($albumId);

    if (!$result['success']) {
        return $response->withStatus($result['status'])->withJson([
            'error' => $result['error']
        ]);
    }

    return $response->withJson($result['data']);
});


$app->post('/albums/add-word', function ($request, $response) {
    $data = json_decode($request->getBody()->getContents(), true);

    $albumId = (int)($data['album_id'] ?? 0);
    $wordId  = (int)($data['word_id'] ?? 0);

    if (!$albumId || !$wordId) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'album_id and word_id are required'
        ]));
        return $response
            ->withStatus(400)
            ->withHeader('Content-Type', 'application/json');
    }

    try {
        $wordAlbums = new \WordMage\WordAlbums();
        $result = $wordAlbums->addWordToAlbumFront($albumId, $wordId);

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => $result['message']
        ]));

        return $response->withHeader('Content-Type', 'application/json');

    } catch (\Throwable $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]));

        return $response
            ->withStatus(500)
            ->withHeader('Content-Type', 'application/json');
    }
});


$app->post('/albums/delete-word', function ($request, $response) {
    $data = json_decode($request->getBody()->getContents(), true);

    $albumId = (int)($data['album_id'] ?? 0);
    $wordId  = (int)($data['word_id'] ?? 0);

    if (!$albumId || !$wordId) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'album_id and word_id are required'
        ]));

        return $response
            ->withStatus(400)
            ->withHeader('Content-Type', 'application/json');
    }

    try {
        $wordAlbums = new \WordMage\WordAlbums();
        $result = $wordAlbums->removeWordFromAlbum($albumId, $wordId);

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => $result['message']
        ]));

        return $response->withHeader('Content-Type', 'application/json');

    } catch (\Throwable $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]));

        return $response
            ->withStatus(500)
            ->withHeader('Content-Type', 'application/json');
    }
});


$app->patch('/albums/{albumId}/words/{wordId}/lock', function ($request, $response, $args) {
    $userId = 4;

    if (!$userId) {
        $payload = [
            'success' => false,
            'message' => 'Unauthorized.'
        ];
        return $response->withJson($payload, 401);
    }

    $albumId = (int)$args['albumId'];
    $wordId = (int)$args['wordId'];

    $body = json_decode($request->getBody()->getContents(), true);

    if (!is_array($body) || !array_key_exists('is_locked', $body)) {
        $payload = [
            'success' => false,
            'message' => 'Missing required field: is_locked.'
        ];
        return $response->withJson($payload, 400);
    }

    $isLocked = filter_var($body['is_locked'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    if ($isLocked === null) {
        $payload = [
            'success' => false,
            'message' => 'Invalid value for is_locked.'
        ];
        return $response->withJson($payload, 400);
    }

    $wordAlbums = new \WordMage\WordAlbums();
    $result = $wordAlbums->setAlbumWordLockStatus($userId, $albumId, $wordId, $isLocked);

    $status = $result['success'] ? 200 : 400;

    if (!$result['success'] && $result['message'] === 'Album not found or access denied.') {
        $status = 404;
    } elseif (!$result['success'] && $result['message'] === 'Word not found in album.') {
        $status = 404;
    }

    return $response->withJson($result, $status);
});


$app->delete('/albums/{id}', function ($request, $response, $args) {
    $userId = 4; // replace later with actual logged-in user id
    $albumId = isset($args['id']) ? (int)$args['id'] : 0;

    $albums = new \WordMage\WordAlbums();
    $result = $albums->deleteAlbum($userId, $albumId);

    if (!$result['success']) {
        return $response->withStatus($result['status'])->withJson([
            'error' => $result['error']
        ]);
    }

    return $response->withJson($result['data']);
});


$app->run();

function getWords(Request $request, Response $response) {
	$words = new Words();
	$wordPool = $words->getWordPool();
	$collective = $words->getCollective();
	$payload = array(
		'wordPool' => $wordPool,
		'collective' => $collective,
	);

	return $response
		->withHeader('Content-Type', 'application/json')
		->write(json_encode($payload));
}

function getRandomPageData($request, $response, $args) {
    $body = $request->getBody();
    $data = json_decode($body, true);
    $user_id = $data['user_id'];
    $count = isset($data['count']) ? $data['count'] : 20;

    $count = (int)$count;
    if ($count < 1) {
        $count = 20;
    }
    if ($count > 100) {
        $count = 100;
    }

    $wordAlbums = new \WordMage\WordAlbums();
    $featured_favorite_data = $wordAlbums->getFeaturedFavoriteByUser($user_id);
    $featured_favorite = $featured_favorite_data['data'];

    try {
        $wordsObj = new Words();
        $words = $wordsObj->getRandomWords($count);

        $payload = [
            'success' => true,
            'count' => count($words),
            'words' => $words,
            'featured_favorite' => $featured_favorite,
        ];

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->write(json_encode($payload));
    } catch (Exception $e) {
        $payload = [
            'success' => false,
            'error' => $e->getMessage()
        ];

        return $response
            ->withStatus(500)
            ->withHeader('Content-Type', 'application/json')
            ->write(json_encode($payload));
    }
}


function getWordsPage($request, $response, $args) {
    $startsWith = trim((string)$request->getQueryParam('starts_with', 'a'));
    $afterWord  = trim((string)$request->getQueryParam('after_word', ''));
    $limit      = (int)$request->getQueryParam('limit', 50);

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
        $wordsObj = new Words();
        $result = $wordsObj->getWordsPage($startsWith, $afterWord, $limit);

        return $response->withJson([
            'success' => true,
            'starts_with' => $startsWith,
            'limit' => $limit,
            'words' => $result['words'],
            'has_more' => $result['has_more'],
            'next_cursor' => $result['next_cursor']
        ]);
    } catch (Exception $e) {
        return $response->withStatus(500)->withJson([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}



function wordsSendLocal(Request $request, Response $response) {
	$wordsShare = new WordsShareLocal();
	$body = $request->getBody();
	$code = $wordsShare::receive($body);	

	echo json_encode(array('code' => $code));
}

function wordsRetrieveLocal(Request $request, Response $response, array $args) {
	$code = $args['code'];
	$wordsShare = new WordsShareLocal();
	$userData = $wordsShare::send($code);	

	echo json_encode($userData);
}

function login(Request $request, Response $response) {
	$body = $request->getBody();
	$data = json_decode($body, true);
	$email = $data['email'];
	$password = isset($data['password']) ? $data['password'] : '';
	$google = isset($data['google']) && $data['google'] ? true : false;
	$register = new \WordMage\Register();
	$payload = $register->login($email, $password, $google);
	echo json_encode($payload);
}

function register(Request $request, Response $response) {
	global $wordmageDb;

	$body = $request->getBody();
	$data = json_decode($body, true);
	$email = $data['email'];
	$password = $data['password'];
	$custom = json_encode($data['userData']);
	// Check database.
	$register = new Register();
	$result = $register->emailIsAvailable($email);

	if ($result) {
		$id = $register->registerEmail($email, $password);
		$customId = $register->registerCustom($id, $custom);
		$payload = array('status' => true, 'user_id' => $id);
	}
	else {
		$payload = array('status' => false, 'msg' => 'Email already registered.');
	}
	echo json_encode($payload);

}

function loadCustom(Request $request, Response $response) {
	$body = $request->getBody();
	$data = json_decode($body, true);
	$user_id = $data['user_id'];
	$register = new Register();
	$custom_data = $register->loadCustom($user_id);
        $wordAlbums = new \WordMage\WordAlbums();
        $liked = $wordAlbums->getFavoritesByUser($user_id);
        $learn = $wordAlbums->getLearnByUser($user_id);
	$permanentAlbumIds = $wordAlbums->getPermanentAlbumsByUser($user_id);
	$custom_data['liked'] = $liked['data'];
	$custom_data['learn'] = $learn['data'];
	$custom_data['album_ids'] = $permanentAlbumIds;
//	unset($custom_data['custom']);
	$payload = $custom_data;
	echo json_encode($payload);
}

function saveCustom(Request $request, Response $response) {
	$body = $request->getBody();
	$data = json_decode($body, true);
	$user_id = $data['user_id'];
	$custom = json_encode($data['custom']);
	$wordObj = isset($data['wordObj']) ? $data['wordObj'] : null;
	$register = new Register();
	$result = $register->saveCustom($user_id, $custom, $wordObj);
	$payload = $result;
	echo json_encode($payload);
}

function saveTraining(Request $request, Response $response) {
	$body = $request->getBody();
	$data = json_decode($body, true);
	$user_id = $data['user_id'];
	$training = json_encode($data['training']);
	$register = new Register();
	$result = $register->saveTraining($user_id, $training);
	$payload = $result;
	echo json_encode($payload);
}

function addWord(Request $request, Response $response) {
	$body = $request->getBody();
	$data = json_decode($body, true);
	error_log("====> addWord data: " . json_encode($data));
	$word = $data['word'];
	$register = new Register();
	$result = $register->addWord($data);
	error_log('====> addWord result: ' . json_encode($result));
	echo json_encode($result);
}

function extractWordsFromPage(Request $request, Response $response) {
	$body = $request->getBody();
	$data = json_decode($body, true);
	$dropboxPath = isset($data['dropboxPath']) ? $data['dropboxPath'] : null;
	$imageUrl = isset($data['imageUrl']) ? $data['imageUrl'] : null;
	$hash = isset($data['hash']) ? $data['hash'] : null;
	$capture = new Capture();
	$result = $capture->ocr($dropboxPath, $imageUrl, $hash);
	//$result = $capture->mockOcr();
	$response->getBody()->write($result);
	return $response->withHeader('Content-Type', 'application/json');
//	echo $result;
}

function testImageAnalysis(Request $request, Response $response) {
	error_log('====> testImageAnalysis form submission TOP');
    // 1) Multipart fields
    $post = $request->getParsedBody() ?? [];
    $prefsRaw = trim($post['preferences'] ?? '');
    $tagsRaw  = trim($post['tags'] ?? '');

    // 2) Try to decode preferences as JSON; if it fails, keep as string
    $preferences = json_decode($prefsRaw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $preferences = $prefsRaw; // plain text fallback
    }

    // 3) Handle the uploaded image
    $uploadedFiles = $request->getUploadedFiles();
    if (!isset($uploadedFiles['photo'])) {
        error_log('No file field "photo" found');
        return $response->withStatus(400)->write('Missing photo');
    }
    $photo = $uploadedFiles['photo'];

    if ($photo->getError() !== UPLOAD_ERR_OK) {
        error_log('Upload error: ' . $photo->getError());
        return $response->withStatus(400)->write('Upload error');
    }

    // 4) Store file to a public folder (e.g., /var/www/public/uploads)
    $safeName = bin2hex(random_bytes(8)) . '-' . preg_replace('/[^\w.\-]+/','_', $photo->getClientFilename());
    $publicDir = __DIR__ . '/public/uploads'; // adjust for your project structure
    if (!is_dir($publicDir)) mkdir($publicDir, 0755, true);

    $photo->moveTo($publicDir . DIRECTORY_SEPARATOR . $safeName);

    // 5) Build a public URL (adjust base URL!)
    $baseUrl   = 'https://wordmage.toews-api.com/public'; // <- set this
    $imageUrl  = $baseUrl . '/uploads/' . $safeName;

    // 6) Optional: normalize tags
    $tags = array_values(array_filter(array_map('trim', explode(',', $tagsRaw ?? ''))));

    $locate = new Locate();
    $pictureData = $locate->locate($imageUrl, $preferences);

    // 7) Log & return JSON
    $result = [
        'image_url'    => $imageUrl,
        'picture_data' => $pictureData,
        'preferences'  => $preferences, // array if valid JSON, else string
        'tags'         => $tags,
        'filename'     => $safeName,
        'content_type' => $photo->getClientMediaType(),
        'size_bytes'   => filesize($publicDir . DIRECTORY_SEPARATOR . $safeName),
    ];
    error_log('upload result: ' . json_encode($result, JSON_UNESCAPED_SLASHES));

    $response = $response->withHeader('Content-Type', 'application/json');
    $response->getBody()->write(json_encode($pictureData, JSON_UNESCAPED_SLASHES));
    return $response;
}


function locate(Request $request, Response $response) {
	$contentType = $request->getHeaderLine('Content-Type') ?? '';
	$body = $request->getBody();
	$data = json_decode($body, true);
	$pictureUrl = $data['pictureUrl'];

	$locate = new Locate();
	$result = $locate->locate($pictureUrl);
error_log("====> locate result <===== " . json_encode($result));
	$response->getBody()->write($result);
	return $response->withHeader('Content-Type', 'application/json');

}
