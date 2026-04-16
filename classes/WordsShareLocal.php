<?php
define('SHARE_DIR', '/var/www/words-share/');
define('CODE_CHARS', 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
define('CODE_LENGTH', 6);

class WordsShareLocal {


	private static function generateCode() {
		$code = '';
		for ($i = 0; $i < CODE_LENGTH; $i++) {
			$charNdx = rand(0, strlen(CODE_CHARS));
			$code .= substr(CODE_CHARS, $charNdx, 1);
		}
		return $code;
	}

	public static function receive($userData) {
		$code = self::generateCode();
		$filename = $code . '.json';
		file_put_contents(SHARE_DIR . $filename, $userData);
		return $code;
	}

	public static function send($code) {
		$filename = $code . '.json';
		$file = SHARE_DIR . $filename;
		if (file_exists($file)) {
			$userData = file_get_contents($file);
			unlink($file);
		} else {
			$userData = '{"msg": "Code no longer valid."}';
		}
		return json_decode($userData, true);
	}

}

