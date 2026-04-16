<?php


namespace WordMage;

class Capture {
	function insertWord($word, $source, $page) {
		global $wordmageDb;

		// Prepare your DB insertion (suggested once outside the loop)
		$stmt = $wordmageDb->prepare("
		    INSERT INTO word_pool
		        (word, definition, source, page_no, qa, original_definition)
		    VALUES
		        (:word, :definition, :source, :page_no, :qa, :original_definition)
		");

		$stmt->execute([
		    ':word'               => $word['word'],
		    ':definition'         => $word['definition'],             // AI-generated
		    ':source'             => $source,
		    ':page_no'            => $page,
		    ':qa'                 => 1,                               // initial QA status
		    ':original_definition'=> $word['original_definition']     // from image
		]);

	}


	function ocr($dropboxPath, $imageUrl) {
/*
		$SYSTEM_CONTENT = <<<EOT
			You are an OCR assistant. 
			You will analyze an image of a page from a book containing words and definitions. Extract words and definitions from the image, as well as the page number. Also, provide an accurate definition of the word that's not based on the one from the image. This is the generated definition. If no such definition is available, the generated definition should be blank. Return JSON only, in this format:
			{ "data": { "page", "words": [{"word", "definition": <generated definition>, "original_definition": <definintion from image> }] } }.
                        If the definition includes part of speech, example, citation, do not include those in the definition.
EOT;
*/
		$SYSTEM_CONTENT = <<<EOT
You are an OCR + definition-generation assistant.

TASKS:
1. Analyze the image of a book page.
2. Extract each vocabulary word and its ORIGINAL definition exactly as printed in the book.
3. Generate a NEW, accurate definition that is NOT based on the one from the image and does NOT copy, quote, or closely paraphrase it.
4. If you cannot determine a generated definition, return an empty string "".
5. Extract the page number if present; otherwise return "".

OUTPUT REQUIREMENTS:
Return JSON ONLY in this exact structure:

{
  "data": {
    "page": "<page_number_as_string>",
    "words": [
      {
        "word": "<word>",
        "definition": "<generated_definition>",
        "original_definition": "<definition_from_image>"
      }
    ]
  }
}

RULES:
- All values MUST be strings, even if empty.
- Do NOT include part of speech, examples, citations, or pronunciation.
- The generated definition MUST be your own wording, NOT derived from the book text.
- Do NOT include any explanatory text outside the JSON.
- Definitions should be brief (1–2 sentences maximum).
EOT;


		$apiKey = OPENAI_API_KEY;

		$path_parts = explode('/', $dropboxPath);

		// The source is the second-to-last element (folder name)
		$source = $path_parts[count($path_parts) - 2];

		// API endpoint
		$url = "https://api.openai.com/v1/chat/completions";

		// Prepare payload
		$payload = [
		    "model" => "gpt-4o-mini",
		    "messages" => [
		        [
		            "role" => 'system',
		            "content" => $SYSTEM_CONTENT
		        ],
		        [
		            "role" => 'user',
		            "content" => [
		                ["type" => "text", "text" => "Here is the image to process."],
		                ["type" => "image_url", "image_url" => ["url" => $imageUrl]]
		            ]
		        ]
		    ],
		    "response_format" => [ "type" => "json_object" ]
		];

		// Initialize cURL
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
		    "Content-Type: application/json",
		    "Authorization: Bearer $apiKey"
		]);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

		// Execute
		$response = curl_exec($ch);
		if (curl_errno($ch)) {
		    echo "Error: " . curl_error($ch);
		    exit;
		}
		curl_close($ch);

		// Decode and output result
		$data = json_decode($response, true);
		$intermediate_payload = json_decode($data['choices'][0]['message']['content'], true);
		$page = $intermediate_payload['data']['page'];
		$intermediate_payload['data']['source'] = $source;
		$payload = array();
		foreach ($intermediate_payload['data']['words'] as $word) {
                    $wordToAdd = [
                        "include"    => true,
                        "word"       => $word['word'],
                        "definition" => $word['definition'],
                        "original_definition" => $word['original_definition'],
                        "source"     => $source,
                        "page"       => $page,
                    ];
                    $payload[] = $wordToAdd;
		    $this->insertWord($word, $source, $page);
		}
                $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		error_log("====> Captured (should include=true): " . $payload);
		return $payload;

	}

	public function mockOcr(): string {
        $sample = [
            [
                "include"    => true,
                "word"       => "ABAXIAL",
                "definition" => "off the centerline, eccentric. Compare OBLQUITY",
                "include"    => true,
                "source"     => "The Logodaedalian’s Dictionary of Interesting and Unusual Words",
                "page"       => "1"
            ],
            [
                "include"    => true,
                "word"       => "ABIDTORY",
                "definition" => "a hidden or secret place",
                "include"    => true,
                "source"     => "The Logodaedalian’s Dictionary of Interesting and Unusual Words",
                "page"       => "1"
            ],
            [
                "include"    => true,
                "word"       => "ABLUTE",
                "definition" => "to cleanse",
                "include"    => true,
                "source"     => "The Logodaedalian’s Dictionary of Interesting and Unusual Words",
                "page"       => "1"
            ]
        ];

        $payload = json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	return $payload;
    }


    public function importWords($request, $response)
    {
        global $wordmageDb; // PDO instance

        // Read raw JSON body from Zapier
        $rawBody = (string) $request->getBody();

        if (trim($rawBody) === '') {
            return [
                'status'  => 'error',
                'message' => 'Empty request body.',
            ];
        }

        $decoded = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status'  => 'error',
                'message' => 'Invalid JSON: ' . json_last_error_msg(),
            ];
        }

        // Normalise to an array of rows:
        // 1) { "rows": [...] }
        // 2) [ {...}, {...} ]
        // 3) {...} -> [ {...} ]
        if (isset($decoded['rows']) && is_array($decoded['rows'])) {
            $rows = $decoded['rows'];
        } elseif (is_array($decoded) && array_keys($decoded) === range(0, count($decoded) - 1)) {
            // Numeric array
            $rows = $decoded;
        } else {
            // Single row object
            $rows = [$decoded];
        }

        if (!is_array($rows)) {
            return [
                'status'  => 'error',
                'message' => 'Expected an array of rows or a "rows" array.',
            ];
        }

        $sql = "
            INSERT INTO word_pool (
                word,
                definition,
                source,
                page_no,
                qa,
                original_definition
            ) VALUES (
                :word,
                :definition,
                :source,
                :page_no,
                :qa,
                :original_definition
            )
        ";

        /** @var PDO $wordmageDb */
        $stmt = $wordmageDb->prepare($sql);

        $processedWords = [];
        $skippedRows    = [];
	error_log('====> IMPORT WORDS FROM SPREADSHEET: ');
	error_log(json_encode($rows));
	error_log('====> END IMPORT WORDS FROM SPREADSHEET <=====');
        foreach ($rows as $index => $row) {
            // Require at least "word"
            if (!isset($row['word']) || trim($row['word']) === '') {
                $skippedRows[] = [
                    'index'   => $index,
                    'reason'  => 'Missing "word" field',
                    'payload' => $row,
                ];
                continue;
            }

            $word       = trim($row['word']);
            $definition = isset($row['definition'])
                ? trim((string) $row['definition'])
                : null;
            $source     = isset($row['source'])
                ? trim((string) $row['source'])
                : null;

            // page_no: prefer "page_no", fall back to "page"
            if (isset($row['page_no'])) {
                $pageNo = trim((string) $row['page_no']);
            } elseif (isset($row['page'])) {
                $pageNo = trim((string) $row['page']);
            } else {
                $pageNo = null;
            }

            // original_definition: fall back to definition if not provided
            $originalDefinition = isset($row['original_definition'])
                ? trim((string) $row['original_definition'])
                : $definition;

            try {
                $stmt->execute([
                    ':word'                => $word,
                    ':definition'          => $definition,
                    ':source'              => $source,
                    ':page_no'             => $pageNo,
                    ':qa'                  => 1, // as requested
                    ':original_definition' => $originalDefinition,
                ]);

                $processedWords[] = $word;
            } catch (\PDOException $e) {
                $skippedRows[] = [
                    'index'   => $index,
                    'reason'  => 'DB error: ' . $e->getMessage(),
                    'payload' => $row,
                ];
                continue;
            }
        }

        return [
            'status'          => 'ok',
            'processed_count' => count($processedWords),
            'processed_words' => $processedWords,
            'words_text'      => implode(', ', $processedWords),
            'skipped_count'   => count($skippedRows),
            'skipped_rows'    => $skippedRows,
        ];
    }


}
