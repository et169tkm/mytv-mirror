<?php

define("URL_TOKEN_JADE", "http://token.tvb.com/stream/live/hls/mobilehd_jade.smil", true/* case-sensitive*/);
define("URL_TOKEN_JADE_HD", "http://token.tvb.com/stream/live/hls/mobilehd_hdj.smil", true/* case-sensitive*/);
define("URL_TOKEN_INEWS", "http://token.tvb.com/stream/live/hls/mobilehd_inews.smil", true/* case-sensitive*/);
define("URL_TOKEN_J2", "http://token.tvb.com/stream/live/hls/mobilehd_j2.smil", true/* case-sensitive*/);
define("DATA_DIR", "data/", true);

$prefix = null;
$url = null;
$cookie_jar_path = null;

function fetch_loop() {
    global $cookie_jar_path, $prefix, $url;

    l(__FUNCTION__."()");
    $t = time();

    $prefix = "jade";
    $url = URL_TOKEN_INEWS;
    $cookie_jar_path = DATA_DIR.$prefix."-".$t.".cookiejar";
    
    $cdn_url = get_cdn_url();
    l($cdn_url);
    $chunklist_list_and_effective_url = get_chunklist_list_and_effective_url($cdn_url);
    $chunklist_list = $chunklist_list_and_effective_url[0];
    $effective_url = $chunklist_list_and_effective_url[1];
    $parsed_chunklist_list = parse_chunklist_list($chunklist_list);
}

function get_chunklist_list_and_effective_url($chunklist_url) {
    l(__FUNCTION__."(), url: ".$chunklist_url);
    $c = new_curl($chunklist_url);
    curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
    $chunklist = curl_exec($c);
    $status = curl_getinfo($c, CURLINFO_HTTP_CODE);
    $effective_url= curl_getinfo($c, CURLINFO_EFFECTIVE_URL);
    curl_close($c);
    if ($status == 200) {
        return array($chunklist, $effective_url);
    } else {
        l(__FUNCTION__." status :".$status);
        return null;
    }
}

function parse_chunklist_list($chunklist_list) {
    $list = array();
    $this_list = array();
    $matches = null;
    foreach(explode("\n", $chunklist_list) as $line) {
        $line = trim($line);
l("line: ".$line);
        if ($line != "") {
            if (preg_match("/^#/", $line)) {
                if (preg_match("/.+:BANDWIDTH=(\d+)$/", $line, $matches)){
                    $this_list["bandwidth"] = $matches[1];
                }
            } else {
                // this is the file name
                $this_list["filename"] = $line;
                $list = insert_sorted($list, $this_list, "bandwidth");
                $this_list = array();
            }
        }
    }
    return $list;
}

function insert_sorted($array, $element, $sort_key) {
    for($i = 0; $i < count($array); $i++) {
        if ($array[$i][$sort_key] > $element[$sort_key]){
            $part1 = array_slice($array, 0, $i, true);
            $part2 = array_slice($array, $i, count($array)-$i, true);
            return array_merge($part1, array($element), $part2);
        }
    }
    return array_merge($array, array($element));
}

function get_cdn_url() {
    global $url;
    l(__FUNCTION__."()");

    $c = new_curl($url."?feed");
    curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($c, CURLOPT_POST, true);
    curl_setopt($c, CURLOPT_POSTFIELDS, "feed");
    $json = curl_exec($c);
    $status = curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);

    if ($status == 200) {
        $feedinfo = json_decode($json, true);
        if (isset($feedinfo["url"])) {
            return $feedinfo["url"];
        } else {
            l("url not found in json: " + $json);
        }
    } else {
        l(__FUNCTION__." status :".$status);
    }
    return null;
}
function new_curl($url) {
    global $cookie_jar_path;
    $c = curl_init();
    curl_setopt($c, CURLOPT_URL, $url);
    curl_setopt($c, CURLOPT_COOKIEJAR, $cookie_jar_path);
    curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
    //curl_setopt($c, CURLOPT_USERAGENT, "curl/7.26.0");
    return $c;
}
function l($log){
    print $log."\n";
}

fetch_loop();
