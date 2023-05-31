<?php namespace Cosninix\Cos;

use Config;
use Exception;
use InvalidArgumentException;
use Log;
use Illuminate\Database\QueryException;

/**
 * Class Cos - Very static so happy
 */
class Cos {
	// just a bunch of happy handy methods

	/**
	 * if v1 is empty, return v1 else v2
	 * @param  [type] $v1 [description]
	 * @param  [type] $v2 [description]
	 * @return [type]     [description]
	 */
	public static function nvl(&$v1, $v2 = null) {
		return empty($v1) ? $v2 : $v1;
	}

	public static function ifnull($v1, $v2 = null) {
		return is_null($v1) ? $v2 : $v1;
	}

	/**
	 * if v1 is set, return v1 else v2
	 * NOTE: PHP BUG: passing array index will create this index and set to null
	 * @param  [type] $v1 [description]
	 * @param  [type] $v2 [description]
	 * @return [type]     [description]
	 */
	public static function ifset(&$v1, $v2 = null) {
		return isset($v1) ? $v1 : $v2;
	}

	/**
	 * if $v not set or not in $list, return $default. Else the value.
	 * @param $v
	 * @param $list
	 * @param $default
	 * @return mixed
	 */
	public static function ifsetlist(&$v, $list, $default) {
		if (isset($v)) {
			foreach ($list as $lv) {
				if ($v == $lv)
					return $lv;    // can only be any value in list
			}
		}
		return $default;
	}

	/**
	 * if v1 is empty, return v1 else v2 - v1 must be set
	 * @param $v1
	 * @param null $v2
	 */
	public static function def($v1, $v2 = null) {
		return empty($v1) ? $v2 : $v1;
	}

	/**
	 * add to v1 and set to 0 if zero
	 * @param $v1
	 * @param int $v2
	 * @return int
	 */
	public static function ifadd(&$v1, $v2 = 0) {
		if (!isset($v1))
			$v1 = 0;
		$v1 += $v2;
		return $v1;
	}

	/**
	 * boolval of a string - Y,y,1,yes,Yes,J,j,T,t are TRUE
	 * @param $s
	 * @param bool $def
	 * @return bool
	 */
	public static function boolval(&$s, $def = false) {
		if (!isset($s))
			return $def;
		$ss = trim($s." ");    // force to string and trim
		if (!$ss)
			return false;    // empty, 0, "0"
		switch (strtolower($ss[0])) {
		case 'j':
		case 'y':
		case '1':
			return true;
		}
		return false;
	}

	/**
	 * xcos::safeTransaction(callback) drop in replacement for DB::transaction
	 * executes a query inside a transaction closure and retries 3 times if the transaction throws a query exception
	 * @param callable|Closure $callback
	 * @throws \Illuminate\Database\QueryException|\Exception
	 * @return mixed
	 */
	public static function safeTransaction(Closure $callback) {
		for ($attempt = 1; $attempt <= 3; $attempt++) {
			try {
				return DB::transaction($callback);
			} catch (QueryException $e) {
				if ($e->getCode() != 40001 || $attempt >= 3) {
					throw $e;
				}
				Log::info("safeTransaction: deadlock found. Retry $attempt", ['exception' => $e]);
			}
		}
	}

	/**
	 * Extended version of htmlspecialchars
	 * @param $var
	 * @return array|string
	 */
	public static function html_escape($var) {
		if (is_array($var)) {
			return array_map('html_escape', $var);
		}
		else {
			return htmlspecialchars($var, ENT_QUOTES, config_item('charset'));
		}
	}

	/**
	 * Sanitize a utf8 string. Remove invalid sequences and invalid control characters
	 * @param $dirty
	 * @return string
	 */
	public static function sanitizeString($dirty) {
		// 	Valid utf8 consists of
		//		0xxxxxxx
		//		110xxxxx 10xxxxxx
		//		1110xxxx 10xxxxxx 10xxxxxx
		//		11110xxx 10xxxxxx 10xxxxxx 10xxxxxx
		$len = strlen($dirty);
		$clean='';
		$checkUtf8Seq=0; $utf8Seq='';
		for($i = 0; $i < $len; $i++){
			$ch=$dirty[$i];
			$c = ord($ch);
			// 1 or more utf8 sequence characters to check
			if ($checkUtf8Seq>=1) {
				if ($c < 0x80 || $c >= 0xc0) {			  // above 10xx xxxx below 1100 0000 is valid
					$checkUtf8Seq=0;	// reset
					continue;
				}
				$utf8Seq.=$ch;
				if (--$checkUtf8Seq == 0) {
					$clean .= $utf8Seq;    // full clean utf8 sequence
					$utf8Seq='';
				}
			}
			elseif ($c >= 0x80) {
				if ($c >= 0xf8)
					continue;                        		// above 1111 1xxx
				elseif ($c >= 0xf0) $checkUtf8Seq = 3;      // above 1111 0xxx
				elseif ($c >= 0xe0) $checkUtf8Seq = 2;      // above 1110 0xxx
				elseif ($c >= 0xc0) $checkUtf8Seq = 1;      // above 1100 0xxx
				else
					continue;                        		// below 1100 0000 above 1000 0000 is invalid as startbyte
				$utf8Seq=$ch;
			}
			elseif ($c < 0x20) {
				// only allow a limited set of control characters
				if (!in_array($c, [ 0x09, 0x0a, 0x0d, 0x1b ]))	// TAB, LF, CR, ESC
					continue;
				$clean.=$ch;	// valid
			}
			else
				$clean.=$ch;	// valid
		}
		return $clean;
	}

	/**
	 * Mysql-UQ mysql quoter (utf8).
	 * Alternative to the obsolete mysql_real_escape_string and mysqli_real_escape_string
	 * Mysql must be configured utf8
	 * @param $value
	 * @param string $cast
	 * @throws Exception
	 * @return string
	 */
	public static function muq($value, $cast = null) {
		if (is_object($value) || is_array($value))
			throw new InvalidArgumentException("Muq: can't process object or array");

		if (is_string($value))
			$value=static::sanitizeString($value);	// sanitize to prevent sql-injections

		// Quote if not a number or a numeric string
		if (!is_numeric($value) || $cast == 'string') {
			// put single quotes around it.
			$value = "'".addslashes($value)."'";    // sanitized so safe to use addslashes
		}
		return $value;
	}

	public static function jsEscape($s) {
		return json_encode($s);
	}

	/**
	 * uq - legacy unquoter - Unsafe!
	 * @param $value
	 * @return string
	 */
	public static function uq($value) {    // mysql quoter
		$value = addslashes($value);    // unsafe: sql-injections possible
		return $value;
	}

	/**
	 * Convert a full path like /x/y/x/www.console.whatever.laravel/app/controller/xController.php
	 * to a more printable /app/controller/xController.php
	 * @param $fullpath
	 * @return string
	 */
	public static function PrintablePath($fullpath) {
		$base = base_path();
		return str_replace($base, '', $fullpath);
	}

	public static function LogException($exception, $extra = "") {
		$pathInfo = Request::getPathInfo();
		$message = $exception->getMessage() ?: 'Exception';

		$file = xcos::PrintablePath($exception->getFile());
		$line = $exception->getLine();
		$acc = Account::getCurrentAccount();
		$accname = $acc ? '<'.$acc->name.'>' : '';
		Log::error("{$file}:{$line} $accname $message $extra @ //".App::environment()."$pathInfo ");
		Log::info("$exception"); // stacktrace
	}

	/**
	 * convert a 0=>vala 1=>valb to a vala=>vala valb=>valb array
	 * only if all keys are numbers!
	 * @param $a
	 * @return array
	 */
	public static function array_v_to_kv($a) {
		$rv = [];
		foreach ($a as $k => $v) {
			if (is_numeric($k))
				$rv[$v] = $v;
			else
				return $a;    // non-numeric key
		}
		return $rv;
	}

	/**
	 * convert a 0=>[keyfield valuefield .... ] 1=>[keyfield valuefield .... ] to a id=>name  array
	 * example: id/keyfield
	 * @param $a
	 * @param mixed $keyfield
	 * @param mixed $valuefield
	 * @return array
	 */
	public static function array_fields_to_kv($a, $keyfield, $valuefield) {
		$rv = [];
		foreach ($a as $v) {
			$rv[$v[$keyfield]] = $v[$valuefield];
		}
		return $rv;
	}

	/**
	 *    supertrim, removes all spaces and punctuation like -_;:'"`.,/!
	 * UL = and convert upper/lowercase
	 * S = Supertrim (DEFAULT
	 * T = normal trim
	 * X = xrteme. Only 0-9A-Za-z and . is allowed, -_./ are converted to . and others are removed. All cases Uppercase
	 * x = xtreme, but converted lowercase
	 * h = html special: convert <> to [], remove &
	 * @param $sorg
	 * @param string $case
	 * @return mixed|string
	 */
	public static function supertrim($sorg, $case = '') {
		$s = strtr($sorg,
				   "-_;:'`.,/!?\"",
				   "            ");
		$s = str_replace(' ', '', $s);
		switch ($case) {
		case 'U':
		case 'u':
			$s = strtoupper($s);
			break;
		case 'l':
		case 'L':
			$s = strtolower($s);
			break;
		case 'h':
		case 'H':
			$sorg = str_replace(['<', '>', '&', "'", "\"", ';', "\\", "\`"], ['[', ']', ' ', '', '', '.', '', ''], $sorg);
			$sorg = filter_var($sorg, FILTER_DEFAULT, FILTER_FLAG_STRIP_LOW);
			if (!mb_check_encoding($sorg, 'UTF-8')) {
				// dont trust it. Make it ascii only
				Log::error("Invalid utf-8 detected: ".$sorg);
				$sorg = filter_var($sorg, FILTER_DEFAULT, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH);
			}
			return $sorg;
		case 'x':
		case 'X':
			$rv = '';
			$sorg = strtr($sorg,
							"-_./",        // these 'dot-like' characters all become dots
							"....");
			for ($i = 0; $i < strlen($sorg); $i++) {
				$ch = $sorg[$i];
				// all other characters are removed
				if (!preg_match('/[0-9a-zA-Z\.]/', $ch)) {
					if ($ch == '.')
						$rv .= $ch;    // only dots. But dots are matched, so why test ?
				}
				else
					$rv .= $ch;
			}
			$s = ($case == 'x' ? strtolower($rv) : strtoupper($rv));
			break;
		}
		return $s;
	}

	/**
	 * @param $s
	 * @return mixed|string
	 */
	public static function slug($s) {
		return static::supertrim($s, 'x');    // to get a slug, we use xtreme supertrim
	}

	/**
	 * trim and caseconvert
	 * @param $s
	 * @param string $case
	 * @return string
	 */
	public static function trimcase($s, $case = '') {
		$s = trim($s);
		switch ($case) {
		case 'U':
		case 'u':
			$s = strtoupper($s);
			break;
		case 'l':
		case 'L':
			$s = strtolower($s);
			break;
		}
		return $s;
	}

	/**
	 * Url and PATH conversions
	 * @param $url
	 * @return bool
	 */
	public static function isUrl($url) {
		return (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0);
	}


	/**
	 * @param $url
	 * @return bool|string
	 */
	public static function url2path($url) {
		$url = trim($url);
		if (!$url)
			return false;    // empty -> empty
		// GENERIC method - lets find our base
		if (strpos($url, URL::to('')) !== false) {
			// starts with our baseurl.. so we can convert
			return public_path().'/'.substr($url, strlen(URL::to('')) + 1);
		}
		// assume it is on our host in our public path
		return public_path().parse_url($url, PHP_URL_PATH);
		//Log::error("cos::url2path $url -> ERROR");
		//return false;
	}

	/**
	 * @param $file
	 * @return bool|string
	 */
	public static function path2url($file) {
		$file = trim(str_replace('/mnt/ebs2', '/var/www', $file));    // convert mnt/ebs2 to /var/www due to symlinks
		if (!$file)
			return '';    // empty -> empty
		if (strpos($file, 'http://') !== false)
			return $file;    // already an url
		// generic..
		if (($ix = strpos($file, 'public')) !== false)
			return URL::to(substr($file, $ix + strlen('public')));

		Log::error("cos::path2url $file -> ERROR");
		return false;
	}

	/**
	 * @param $str
	 * @return string
	 */
	public static function urlencode($str) {
		$str = str_replace('/', '@', $str);    // no foreward slashes
		return urlencode($str);
	}

	/**
	 * @param $data
	 * @return string
	 */
	public static function base64url_encode($data) {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	/**
	 * @param $data
	 * @return string
	 */
	public static function base64url_decode($data) {
		return base64_decode(strtr($data, '-_', '+/'));
		//return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data)+(strlen($data) % 4), '=', STR_PAD_RIGHT));
	}

	/**
	 * @param $bytes
	 * @return string
	 */
	public static function formatFileSize($bytes) {
		if ($bytes >= 1073741824) {
			return number_format($bytes / 1073741824, 2).' GB';
		}
		elseif ($bytes >= 1048576) {
			return number_format($bytes / 1048576, 2).' MB';
		}
		elseif ($bytes >= 1024) {
			return number_format($bytes / 1024, 2).' KB';
		}
		elseif ($bytes != 1) {
			return $bytes.' bytes';
		}
		return "1 byte";
	}

	/**
	 * better json decoder: parse and give error as text
	 * @param $json
	 * @param string $err
	 * @return mixed
	 */
	public static function parseJson($json, &$err = '') {
		$err = '';
		$data = json_decode($json, true);    // to assoc array
		if ($data === null) {
			$json_err = [
				JSON_ERROR_NONE => '',
				JSON_ERROR_DEPTH => 'max stack depth exceeded',
				JSON_ERROR_STATE_MISMATCH => 'underflow or the modes mismatch',
				JSON_ERROR_CTRL_CHAR => 'unexpected control character fount',
				JSON_ERROR_SYNTAX => 'syntax error, malformed json',
				JSON_ERROR_UTF8 => 'malformed utf-8, possibly incorrectly encoded'];
			$jse = json_last_error();
			$err = static::ifset($json_err[$jse], 'unknown error');
		}
		return $data;
	}

	/**
	 * callJsonAPi - http post/get with json
	 * @param $url
	 * @param string $method
	 * @param array $data
	 * @param int $err
	 * @param null|mixed $credentials
	 * @return bool|mixed
	 */
	public static function callJsonApi($url, $method = 'get', $data = [], &$err = 0, $credentials = null,
									   callable $sigcalcfunc = null
	) {
		$ispost = (Str::lower($method) == 'post');
		$header = [
			'Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7',
			'Accept: application/json'];
		if (!preg_match('|^http(s)?://|i', $url)) {
			$url = url($url);
		}
		$ch = curl_init();

		if ($ispost) {
			if (!is_string($data))
				$eData = json_encode($data);    // convert array to json
			else
				$eData = $data;    // assume it is json already
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST,
						"POST");    // dont use CURLOPT_POST as this will force data into postfields
			curl_setopt($ch, CURLOPT_POSTFIELDS, $eData);
			$header[] = 'Content-Type: application/json';

			$header[] = 'Content-Length: '.strlen($eData);
		}
		if (is_callable($sigcalcfunc)) {
			$signature = $sigcalcfunc($url, ($ispost ? $eData : ''));
			$url .= '&signature='.$signature;
		}
		//echo "callJsonApi - $method $url $eData \n";
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
		curl_setopt($ch, CURLOPT_ENCODING, "deflate,gzip");
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);    // allow 302's
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		if ($credentials && $credentials['username'] && $credentials['password'])
			curl_setopt($ch, CURLOPT_USERPWD, $credentials['username'].":".$credentials['password']);
		curl_setopt($ch, CURLOPT_FAILONERROR, true);
		curl_setopt($ch, CURLINFO_HEADER_OUT, 1);    // allow us to see what we've send..
		$content = curl_exec($ch);
		if ($content === false) {
			// error!
			$err = curl_error($ch);    // curl_errno($ch)
			Log('callJsonApi error: '.curl_error($ch)."\nheader send: ".curl_getinfo($ch, CURLINFO_HEADER_OUT));
			return false;
		}
		// echo /* tsk::log */ ('callJsonApi: '.$url."\n  OK--> ".$content." ".curl_error($ch));
		curl_close($ch);
		//echo "---------------------\n".$content."\n----------------\n";
		$arr = static::parseJson($content);
		//print_r($arr);echo "\n--------------";
		return $arr;
	}


	/**
	 * non-blocking (sort of) fire-and-forget async http call
	 * @param $url
	 * @param $params
	 * @param string $type
	 */
	public static function asyncHttp($url, $params, $type = 'GET') {
		$post_params = [];
		foreach ($params as $key => &$val) {
			if (is_array($val))
				$val = implode(',', $val);
			$post_params[] = $key.'='.urlencode($val);
		}
		$post_string = implode('&', $post_params);

		$parts = parse_url($url);
		//echo print_r($parts, TRUE);
		$fp = fsockopen($parts['host'],
						(isset($parts['scheme']) && $parts['scheme'] == 'https') ? 443 : 80,
						$errno, $errstr, 30);

		$out = "$type ".$parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '')." HTTP/1.1\r\n";
		$out .= "Host: ".$parts['host']."\r\n";
		$out .= "Content-Type: application/x-www-form-urlencoded\r\n";
		$out .= "Content-Length: ".strlen($post_string)."\r\n";
		$out .= "Connection: Close\r\n\r\n";
		// Data goes in the request body for a POST request
		if ('POST' == $type && isset($post_string))
			$out .= $post_string;
		fwrite($fp,
			   $out);    // here is the sort-of-blocking part: Php waits for the socket to open before starting to write.
		fclose($fp);    // lets not wait for an answer
	}

	/**
	 * set language to browser's language
	 */
	public static function setBrowserLanguage() {
		// detect browser language
		$header_accept_lang = Request::header('Accept-Language');
		//Log::info('header accept languages: '.$header_accept_lang);
		$headerlang = Str::lower(substr($header_accept_lang, 0, 2));
		$availablelang = Config::get('app.available_languages');
		if ($headerlang && $availablelang && in_array($headerlang, $availablelang)) {
			// browser lang is supported, use it
			App::setLocale($headerlang);
			return true;
		}
		//}
		return false;
	}

	/**
	 * for Hard-dropping tables and avoid those pesky foreign-key errors
	 * @param $callable
	 */
	public static function tryCatchDrop($callable) {
		if (is_callable($callable)) {
			try {
				DB::statement('SET foreign_key_checks = 0');
				call_user_func($callable);
				DB::statement('SET foreign_key_checks = 1');
			} catch (Exception $e) {
				$trace = $e->getTrace();
				$file = isset($trace[2]['file']) ? $trace[2]['file'] : __FILE__;
				Log::error("tryCatchDrop error: $file: ".$e->getMessage()."\n");
			}
		}
	}

	/**
	 * failsafe percentage calculator
	 * @param $old
	 * @param $new
	 * @param int $precision
	 * @return false|float|int|string [type]      [description]
	 */
	public static function percDiffString($old, $new, $precision = 1) {
		// div by zero check
		if (abs($old) <= 1E-15) {
			if ($new == 0)
				return 0;
			else
				return "&#8734;";    // infinite
		}
		return round(100 * ($new - $old) / $old, $precision);
	}

	/**
	 * @param $part
	 * @param $total
	 * @param int $precision
	 * @return float|int
	 */
	public static function percString($part, $total, $precision = 1) {
		if (abs($total) <= 1E-15) {
			return 0;
		}
		return round(100 * $part / $total, $precision);
	}

	/**
	 * Replace {{..}} varibles in test with subsitutions
	 * {{ variable ? exists string : not exists string }}  use $ to show variable
	 * @param  [type] $text      [description]
	 * @param array $translate [description]
	 * @param mixed $urlencode
	 * @return [type]            [description]
	 */
	public static function personalizeText($text, $translate = [], $urlencode = false) {
		return preg_replace_callback(
			'/\{\{(.*?)\}\}/s',
			function ($match) use ($urlencode, $translate) {
				$k = trim($match[1]);
				// format {{key}} or {{key?..:...}}
				if (preg_match('/([^?]+)\?(.+?)\\\:(.*)/s', $k, $m2)) {
					$k = trim($m2[1]);
					$thenval = $m2[2];
					$elseval = $m2[3];
					if (isset($translate[$k]) && trim($translate[$k]))
						return str_replace('$', $translate[$k], $thenval);
					return $elseval;
				}
				return xcos::ifset($translate[$k], 'not found');
			},
			$text);

	}

	const encodeKey = "changethistoYOURkey";

	/**
	 * encode a numeric id or string to a checked hash string
	 * @param $id - number or string to hase
	 * @param $validtime - time (unixtime) until becomes invalid (0=forever = default)
	 * @return string
	 */
	public static function encodeId($id, $validtime = 0) {
		// use first 2 letters to define hashtype
		//	VT=valid till. Format VTddddddddddEEEEE...EEEid
		//	IN=infinite valid
		if ($validtime)
			$enc = sprintf("VT%010d%s%s", $validtime, self::encodeKey, $id);
		else
			$enc = 'IN'.self::encodeKey.$id;
		return Crypt::encrypt($enc);
	}

	/**
	 * decode hash string cid to id or FALSE
	 * @param null $cid
	 * @return bool|int|string
	 */
	public static function decodeId($cid = null) {
		if ($cid === 0)
			return 0;
		try {
			$id = @Crypt::decrypt($cid);
		} catch (Exception $e) {
			Log::info("decodeID: decode error, check not found!");
			return false;
		}
		switch (substr($id, 0, 2)) {
		case 'VT':
			$maxvalid = substr($id, 2, 10);
			if (time() > $maxvalid) {
				Log::info("decodeID: decode error, hash not valid any more");
				return false;
			}
			$id = substr($id, 12);
			break;
		case 'IN':
			$id = substr($id, 2);
			break;
		default:
			Log::info("decodeID: invalid type ".substr($id, 0, 2));
			return false;
		}
		if (substr($id, 0, strlen(self::encodeKey)) != self::encodeKey) {
			Log::info("decodeID: decode error, invalid check - check not found!");
			return false;
		}
		return substr($id, strlen(self::encodeKey));
	}

	// true if this host MUST redirect to https
	public static function isSecureServer() {
		return in_array(Request::server('HTTP_HOST'), [
			// enter a list of domains here
		]);
	}

	/**
	 * SocketIO endpoint always available on same host, suffixed by /socket.io
	 *
	 * @return string
	 */
	public static function socketioEndpoint() {
		return '/socket.io';
	}

	public static function varDumpToString($var) {
		ob_start();
		var_dump($var);
		$result = ob_get_clean();
		return $result;
	}

	public static function bitrotate($decimal, $bits) {
		$binary = decbin($decimal);
		return (bindec(substr($binary, $bits).substr($binary, 0, $bits)));
	}

	//static private $anyindex="0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
	private static $anyindex = "PQnopqYZzAU1de3FG0hijJKLrstBCD2uvR";

	// Decimal > Custom
	public static function dec2any($num, $base = 62, $index = false) {
		$index = self::$anyindex;
		$base = strlen($index);    // 62
		$i1 = $num;
		$num = ($num * 83) + 17;    // set check
		$i2 = $num;
		$num = static::obfuscate($num);
		$i3 = $num;
		$out = "";
		for ($t = floor(log10($num) / log10($base)); $t >= 0; $t--) {
			$a = floor($num / pow($base, $t));
			$out = $out.substr($index, $a, 1);
			$num = $num - ($a * pow($base, $t));
		}
		Log::info("dec2any $i1 to $i2 obfuscated $i3 to any $out");
		return $out;
	}

	// Custom > Decimal
	public static function any2dec($num, $base = 62, $index = false) {
		$index = self::$anyindex;
		$base = strlen($index);
		$out = 0;
		$len = strlen($num) - 1;
		for ($t = 0; $t <= $len; $t++) {
			$sp = strpos($index, substr($num, $t, 1));
			if ($sp === false) {
				// invalid character
				Log::info("any2dec $num to $out invalid character");
				return null;    // error
			}
			$out = $out + $sp * pow($base, $len - $t);
		}
		$out2 = static::obfuscate($out, true);    // de-obfuscate
		if ((($out2 - 17) % 83) != 0) {        // check the checkbits
			// check did not work
			Log::info("any2dec $num to $out de-obfuscated $out2  not valid");
			return null;    // error
		}
		$out3 = ($out2 - 17) / 83;
		Log::info("any2dec $num to $out de-obfuscated $out2  returns $out3");
		return $out3;
	}

	public static function obfuscate($x, $restore = false) {
		// *** Shuffle bits (method used here is described in D.Knuth's vol.4a chapter 7.1.3)
		$mask1 = 0x00550055;
		$d1 = 7;
		$mask2 = 0x0000cccc;
		$d2 = 14;

		if (!$restore) {
			// Obfuscate
			$t = ($x ^ ($x >> $d1)) & $mask1;
			$u = $x ^ $t ^ ($t << $d1);
			$t = ($u ^ ($u >> $d2)) & $mask2;
			return $u ^ $t ^ ($t << $d2);
		}
		else {
			// Restore
			$t = ($x ^ ($x >> $d2)) & $mask2;
			$u = $x ^ $t ^ ($t << $d2);
			$t = ($u ^ ($u >> $d1)) & $mask1;
			return $u ^ $t ^ ($t << $d1);
		}
	}

	/**
	 * Return full url but with some querystrings replaced
	 * @param $extra  array of qs values like ['pg'=>'2','offset'=>'4']
	 * @return string
	 */
	public static function fullUrlWithQuery($extra) {
		$url = Request::url(); // url without query
		$query = Request::query(); // query

		//Replace parameter:
		return $url.'?'.http_build_query(array_merge($query, $extra));
	}
}
