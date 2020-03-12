<?php
class SynoDLMSearchHYTorrentLeech {

	private $wurl = 'https://www.torrentleech.org';
	private $qurl = '/torrents/browse/list/query/';
	private $lurl = '/user/account/login/';
	private $durl = '/download/';
	private $COOKIE = '/tmp/hy_torrentleech.cookie';    
	public $max_results = 0;
	public $debug = true;

	private function DebugLog($str) {
		if ($this->debug==true) {
			file_put_contents('/tmp/hy_torrentleech.log',"[DEBUG] " . $str . "\r\n\r\n",FILE_APPEND);
		}
	}

	public function __construct() { 
		$this->qurl=$this->wurl.$this->qurl;
		$this->wurl_host=$this->wurl_host.$this->lurl;
		$this->lurl=$this->wurl.$this->lurl;
		$this->durl=$this->wurl.$this->durl;
	}

	public function prepare($curl, $query, $username, $password) {
		$url = $this->qurl . urlencode($query);
		// Set curl options
		curl_setopt($curl, CURLOPT_VERBOSE, true);
		curl_setopt($curl, CURLOPT_STDERR, '/var/hy_torrentleech.log');
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_PORT, 443);
		
		$this->DebugLog("Function Prepare.");
		$this->DebugLog("URL:      " . $url);
		$this->DebugLog("Username: " . $username);
		$this->DebugLog("Password: " . $password);
		
		if ($username !== NULL && $password !== NULL) {
			$this->VerifyAccount($username, $password);
			curl_setopt($curl, CURLOPT_COOKIEFILE, $this->COOKIE);
		}

		$this->DebugLog($curl);
	}

	public function GetCookie() {
		return $this->COOKIE;
	}

	public function VerifyAccount($username, $password) {
		$ret = TRUE;

		// $this->DebugLog("Username: " . $username);
		// $this->DebugLog("Password: " . $password);
		// $this->DebugLog("Login URL: " . $this->lurl);
		if (file_exists($this->COOKIE)) {
				$this->DebugLog("Removing COOKIE file.");
				unlink($this->COOKIE);
		}

		$PostData = array('username'=>$username,'password'=>$password,'remember_me'=>'on','login'=>'submit');
		$PostData = http_build_query($PostData);

		$fscurl = curl_init();
		$headers = array
		(
			'Accept: text/html,application/xhtml+xml,application/xml,application/json;q=0.9,*;q=0.8',
			'Accept-Language: ru,en-us;q=0.7,en;q=0.3',
			'Accept-Encoding: deflate',
			'Accept-Charset: windows-1251,utf-8;q=0.7,*;q=0.7'
		);
		curl_setopt($fscurl, CURLOPT_HTTPHEADER,$headers); 
		curl_setopt($fscurl, CURLOPT_URL, $this->lurl);
		curl_setopt($fscurl, CURLOPT_FAILONERROR, 1);
		curl_setopt($fscurl, CURLOPT_REFERER, $this->lurl);
		curl_setopt($fscurl, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($fscurl, CURLOPT_RETURNTRANSFER,1);
		curl_setopt($fscurl, CURLOPT_TIMEOUT, 20);
		curl_setopt($fscurl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
		curl_setopt($fscurl, CURLOPT_POST, 1);
		curl_setopt($fscurl, CURLOPT_COOKIEJAR, $this->COOKIE);
		curl_setopt($fscurl, CURLOPT_COOKIEFILE, $this->COOKIE);
		curl_setopt($fscurl, CURLOPT_POSTFIELDS, $PostData);
		curl_setopt($fscurl, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($fscurl, CURLOPT_SSL_VERIFYPEER, false);

		$Result = curl_exec($fscurl);

		if (FALSE !== strpos($Result, '<title>Login :: TorrentLeech.org</title>')) {
			$this->DebugLog("Login Result: " . $Result);
			$this->DebugLog("Login Failed.");
			$ret = FALSE;
		} else {
			// Dirty patch to enable downloading direct torrents both with www and non-www links.
			curl_setopt($fscurl, CURLOPT_URL, $this->wurl_host);
			curl_setopt($fscurl, CURLOPT_REFERER, $this->wurl_host);
			curl_exec($fscurl);
			$this->DebugLog("Logged In.");
		}

		curl_close($fscurl);
		// Clean memory usage
		unset($fscurl);

		return $ret;
	}

	/**
	 * Returns a size in bytes
	 * 
	 * @param size 		unmodified size (e.g. 1)
	 * @param modifier	modifier (e.g. 'KB', 'MB', 'GB', 'TB')
	 * @return bytesize	size in bytes (e.g. 1,048,576)
	 */
	private function sizeInBytes($size, $modifier) {
		switch (strtoupper($modifier)) {
		case 'KB':
			return $size * 1024;
		case 'MB':
			return $size * 1024 * 1024;
		case 'GB':
			return $size * 1024 * 1024 * 1024;
		case 'TB':
			return $size * 1024 * 1024 * 1024 * 1024;
		default:
			return $size;
		}
	}

	private function parseCategory($categoryId) {
		switch ($categoryId) {
			case '26':
				return 'TV::Episodes';
				break;
			case '27':
				return 'TV::Boxsets';
				break;
			case '32':
				return 'TV::Episodes HD';
				break;
				
			default:
				return 'Unknown Category';
				break;
		}
	}

	private function getDownloadlink($fid, $filename) {
		return sprintf("https://www.torrentleech.org/download/%s/%s", $fid, $filename);
	}

	public function parse($plugin, $response) {
		
		//$this->DebugLog("Parsing response:\n" . $response);
		$resArr = json_decode($response, TRUE);
		$debugArr = print_r($resArr['torrentList'], TRUE);
		//$this->DebugLog($debugArr);
		//$this->DebugLog("TorrentList: " . count($resArr['torrentList']));
		$res = 0;
		foreach ($resArr['torrentList'] as $torrent) {
			$title="Unknown title";
			$download="Unknown download";
			$size=0;
			$datetime="1978-09-28";
			$page="Default page";
			$hash="";
			$seeds=0; 
			$leechs=0;
			$category="Unknown category";
			//$this->DebugLog(sprintf($this->$durl, $torrent['fid'], $torrent['filename']));
			$title = $torrent['name'];
			//$download = getDownloadlink($torrent['fid'], $torrent['filename']);
			$download = $this->durl . $torrent['fid'] . "/" . $torrent['filename'];
			$hash = md5($download);
			$page = $this->wurl . "/torrent/" . $torrent['fid'];
			$size = $this->sizeInBytes($torrent['size']);
			$datetime = date('Y-m-d',strtotime($torrent['dateAdded']));
			$seeds = $torrent['seeders'];
			$leechs = $torrent['leechers'];
			$category = $this->parseCategory($torrent['categoryID']);
			$plugin->addResult($title, $download, $size, $datetime, $page, $hash, $seeds, $leechs, $category);
			$res++;
		}
		
		$this->DebugLog("Count: " . $res);

		return $res;
		
	}
}
?>
