<?php
//********************************************************************
//	main libfile - generic helpers
//
//  COSLIB (c)'2001,2002 Cosninix / rvw
//	Cosninix WEbApps
//********************************************************************
if ($konglib_prep!=1) {
	$konglib_prep=1;

	// additional libraries...
	//require("webdb.inc");
	//require("members.inc");
	if (!isset($beaver_prepend)) {
		function rlsw($txtnl,$txten) {
			global $lang;
			return $lang=="EN" ? $txten : $txtnl;
		}
		function plsw($txtnl,$txten) {
			echo rlsw($txtnl,$txten);
		}
		function nvl($v1,$v2) {
			// just like the sql counterpart..
			return empty($v1)?$v2:$v1;
		}
		function strutstr($haystack,$needle) {
			// like strstr, but returns the string UP TO, not including the needle
			if (strstr($haystack,$needle))
				return substr($haystack,0,strpos($haystack,$needle));
			return false;
		}
		function strstrex($haystack,$needle) {
			// like strstr, but returns the string after, NOT INCLUDING needle
			if (strstr($haystack,$needle))
				return substr($haystack,strpos($haystack,$needle)+strlen($needle));
			return false;
		}

		function strrepl($haystack,$needle,$repl) {
			return implode($repl,explode($needle,$haystack));
		}

		// unquote - removes silly quotes
		function unq($qst) {
			$qst=strrepl($qst,"'","''");
			$qst=strrepl($qst,"\\","");
			return $qst;
		}

		function uq($qst) {
		//	return strtr($qst,"'\\\"","` `");
			$qst=strrepl($qst,"'","''");
			$qst=strrepl($qst,"\\","");
			return $qst;
		}
	}

	function text2html($text) {
		$text=strrepl($text,sprintf("%c%c",13,10),"<br>");
		$text=strrepl($text,"&","&amp;");
		return $text;
	}
	function html2text($html) {
		$html=strrepl($html,"<br>",chr(13).chr(10));
		$html=strrepl($html,"&amp;","&");
		return $html;
	}

	// replace texts in string like [^xxx^] with the php variable
	// transemode = "HUQ"
	//		Q (dequote, single ' becomes '')
	//		H (html-trans newline to <br>)
	//		U (url-trans (space becomes +))
	function qsreplace($str,$transmode="") {
		if (strlen($str)>0) {
			while ( strstr(strstr($str,"[^"),"^]") ) {
				$fstart=strpos($str,"[^");
				$fend=strpos($str,"^]");
				$field= substr($str,$fstart+2,$fend-$fstart-2);
				$dateconvert=false;
				if (substr($field,0,3)=='dc:') {
					$dateconvert=true;	// do a dateconvert from dd-mm-yyyy to 'yyyymmdd' or NULL
					$field=substr($field,3);
				}
				switch($field) {
				case 'user':
					$fval=$GLOBALS['currentuser'];
					break;
				default:
					$fval=$GLOBALS[$field];	// this should give the value of varable with name
				}
				$fval=rtrim($fval);
				if (strstr($transmode,"Q"))
					$fval=unq($fval);
				if (strstr($transmode,"H"))
					$fval=text2html($fval);
				if (strstr($transmode,"U"))
					$fval=urlencode($fval);
				if ($dateconvert) {
					//echo "fval=$fval";
					list($dd, $dm, $dy) = split('[/.-]', $fval);
					if ($dd>0 && $dm>0 && $dy>0) {
						if ($dy<100)
							$dy+=2000;
						$fval=sprintf("'%04d%02d%02d'",$dy,$dm,$dd);
					}
					else
						$fval="NULL";
					//echo "..fval=$fval";
				}
				$str=substr($str,0,$fstart).$fval.substr($str,$fend+2);
	     	}
			if (strstr($str,"^^")) {
				$str=strutstr($str,"^^").$GLOBALS['zoek'].strstrex($str,"^^");
			}
		}
		return $str;
	}


	// replace texts in string like [[xxx]] with the value of that column in the query
	// transmode: see qsreplace
	function dbreplace($str,$db,$transmode="",$db2=false) {
		if (strlen($str)>0) {
			while ( strstr(strstr($str,"[["),"]]") ) {
				$fstart=strpos($str,"[[");
				$fend=strpos($str,"]]");
				$field= substr($str,$fstart+2,$fend-$fstart-2);
				if (is_object($db))
					$fval=$db->f("$field");	// this should give the value of varable with name
				else
					$fval="";
				if (strstr($transmode,"Q"))
					$fval=unq($fval);
				if (strstr($transmode,"H"))
					$fval=text2html($fval);
				if (strstr($transmode,"U"))
					$fval=urlencode($fval);
				$str=substr($str,0,$fstart).$fval.substr($str,$fend+2);
	     	}
			while ( strstr(strstr($str,"[2"),"2]") ) {
				$fstart=strpos($str,"[2");
				$fend=strpos($str,"2]");
				$field= substr($str,$fstart+2,$fend-$fstart-2);
				if (is_object($db2))
					$fval=$db2->f("$field");	// this should give the value of varable with name
				else
					$fval="NOMASTER";	// defaults to NOMASTER value ($db2=masterquery)
				if (strstr($transmode,"Q"))
					$fval=unq($fval);
				if (strstr($transmode,"H"))
					$fval=text2html($fval);
				if (strstr($transmode,"U"))
					$fval=urlencode($fval);
				$str=substr($str,0,$fstart).$fval.substr($str,$fend+2);
	     	}
		}
		return qsreplace($str,$transmode);
	}

	// add a current (global) variable to the url spec (var passtrough)
	// If var already in $url, do NOT replace!
	function addurlvar($url,$v) {
		if (!strstr($url,'&'.$v.'=') && !strstr($url,'?'.$v.'=')) {
			if (strstr($url,'?'))
				$url.='&'.$v.'='.urlencode($GLOBALS[$v]);
			else
				$url.='?'.$v.'='.urlencode($GLOBALS[$v]);
		}
		return $url;
	}

	// get me own url back, but then with an extra/replaced variable
	// (set to the current value of it)
	function selfurl_addvar($newkey,$newkeyval) {
		global $HTTP_GET_VARS,$PHP_SELF,$HTTP_HOST;
		while(list($key, $val) = each($HTTP_GET_VARS)) {
			if (strcasecmp($newkey,$key)!=0) {
				// only if its not the newkey
				$attributes .=  "$key=$val&";
			}
		}
		$attributes .= "$newkey=".urlencode($newkeyval); // .urlencode($GLOBALS[$newkey]);
		// doe maar niet$page = "http://".$HTTP_HOST;
		$page = $PHP_SELF; //$HTTP_SERVER_VARS['SCRIPT_NAME'];  //.$PATH_INFO.$PHP_SELF .
		$page.= "?" . $attributes;
		return $page;
	}

	function genmedia($id) {
	 	$dbm = new DB_Sql;
		$dbm->query("select * from media where id='$id'");
		if ($dbm->next_record()) {
			switch ($dbm->f("doctype")) {
			case "JPG":
			case "GIF":
					echo "<img src='".$dbm->f("url")."' border=0>";
					break;
			default:
					echo "<a href='".$dbm->f("url")."'>[[PLAY]]</a>";
			}
		}
		else {
			echo "Illegal media spec";
		}
	}

	/////////////////////////////////////////////////////////////
	// system to keep global variables and pass them in url's
	/////////////////////////////////////////////////////////////
	$keepvars = array();

	// register a var so it will be passed!
	function keepvar($evar) {
		global $keepvars;
		$keepvars[$evar]="Y";	// dummy value.. key only
	}
	// return new url with vars attached
	function rurl($baseurl) {
		global $keepvars;
		reset($keepvars);
		while (list ($evar, $dd) = each ($keepvars)) {
			$baseurl=addurlvar($baseurl,$evar);
		}
		return $baseurl;
	}
	// PRINT new url with vars attached
	function purl($baseurl) {
		echo rurl($baseurl);
	}

	function optgen($val,$opt,$desc) {
		echo "<option value='".$opt."' ";
		if ($val==$opt) {
			echo "SELECTED=SELECTED";
		}
		echo ">".$desc."</option>";
	}
}

// pas op: hierna achter ? en > mag geen ENKELE tekst staan, want dan werkt het HEADER statement niet meer!!!! (dus ook geen return!)
?>
