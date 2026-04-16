<?php


namespace WordMage;

class Locate {

	function locate($pictureUrl, $preferences) {
		$SYSTEM_CONTENT = <<<EOT
As a location identification expert, your task is to analyze the provided image and determine the geographical location depicted within it. Please consider any landmarks, natural features, or architectural styles that may help in identifying the location.

If you can determine the location, list places within walking distance that match these preferences: $preferences

**Expected Output Format:**
- Provide the name of the location (e.g., "Eiffel Tower, Paris") or a brief description if the exact name is unknown (e.g., "a beach in Hawaii"). Also, list places you found within walking distance. For each place, give the approximate walking time. If possible, provide directions. The output should be in json: { "location", "description", "places" }

**Constraints:**
- Ensure your response is concise and directly related to the image.
- If the location is not identifiable, state that clearly.
EOT;

		$apiKey = OPENAI_API_KEY;

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
		                ["type" => "image_url", "image_url" => ["url" => $pictureUrl]]
		            ]
		        ]
		    ],
		    "response_format" => [ "type" => "json_object" ]
		];
//error_log('====> locate OpenAI payload: ' . json_encode($payload));
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
//error_log('====> locate OpenAI response: ' . json_encode($data));

		$payload = json_decode($data['choices'][0]['message']['content'], true);
                $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
//		error_log("====> Locate payload: " . json_encode($payload));
		return $payload;

	}

}
