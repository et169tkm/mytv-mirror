<?php

require "config.php";

$prefix = null;
$url = null;
$cookie_jar_path = null;
$db = null;
$filetime = 0;
$effective_url = null;
$chunklist_url = null;

function init() {
    global $db, $prefix, $url, $cookie_jar_path, $filetime;
    $db = array();
    $prefix = "jade";
    $url = URL_TOKEN_JADE;
    $cookie_jar_path = DATA_DIR.$prefix."-".$filetime.".cookiejar";
    $filetime = time();
}

function fetch_loop() {
    global $cookie_jar_path, $prefix, $url, $filetime, $effective_url, $chunklist_url;
    l(__FUNCTION__."()");

    while (true) {
        $chunklist = download_chunklist();
print_r($chunklist);
        if ($chunklist) {
            $chunklist = parse_chunklist($chunklist);
            download_chunks($chunklist, $effective_url);
        }

        // increment filetime
        $chunklist_duration = 0;
        foreach ($chunklist as $chunk) {
            $chunklist_duration += $chunk["duration"];
        }
        
        $wait_time = ($filetime - 20) - time();
        if ($wait_time > 0) { // start early download 10 second before this chunk finish
            l("going to sleep ".$wait_time." seconds");
            sleep($wait_time);
        }
    }
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

function download_chunklist() {
    global $chunklist_list, $effective_url, $chunklist_url;

    // try download the chunk list
    l("getting chunk list");
    if ($chunklist_url) {
        $c = new_curl($chunklist_url);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
        $chunklist = curl_exec($c);
        $status = curl_getinfo($c, CURLINFO_HTTP_CODE);
        if ($status == 200) {
            return $chunklist;
        }
    }

    // if we can't download the chunk list, start over
    l("getting cdn url");
    $cdn_url = get_cdn_url();
    l("cdn url: ".$cdn_url);

    if (!$cdn_url) {
        // if we can't even get this, there's nothing more we can do
        exit(1);
    }

    l("getting list of chunk list urls");
    $chunklist_list_and_effective_url = get_chunklist_list_and_effective_url($cdn_url);
    if ($chunklist_list_and_effective_url) {
        $chunklist_list = $chunklist_list_and_effective_url[0];
        $effective_url = $chunklist_list_and_effective_url[1];
        $parsed_chunklist_list = parse_chunklist_list($chunklist_list);
        $chunklist_filename = $parsed_chunklist_list[count($parsed_chunklist_list)-1]["filename"];
        $chunklist_url = str_replace("playlist.m3u8", $chunklist_filename, $effective_url);
        l("chunklist url: ".$chunklist_url);
    } else {
        // Can't get chunk list URLs, should not happen
        exit(1);
    }

    l("getting chunk list again");
    $c = new_curl($chunklist_url);
    curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
    $chunklist = curl_exec($c);
    $status = curl_getinfo($c, CURLINFO_HTTP_CODE);
    if ($status == 200) {
        return $chunklist;
    } else {
        l("status: ".$status);
        exit(1);
    }
}

function download_chunks($chunklist, $effective_url) {
    global $db, $prefix, $filetime;
    $mh = curl_multi_init();
    $connections = array();
    $files = array();
    curl_multi_setopt($mh, CURLMOPT_PIPELINING, 1);
    curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, 1);
    foreach($chunklist as $chunk) {
        if (db_is_file_downloaded($db, $chunk["filename"])) {
            // this file is already downloaded, skip downloading
            l("skip downloading: : ".$chunk["filename"]);

            // fill in the corresponding array elements to make these arrays synchronized with $chunklist
            $connections[] = null;
            $files[] = null;
            continue;
        }
        l("going to download: ".$chunk["filename"]);
        $url = str_replace("playlist.m3u8", $chunk["filename"], $effective_url);
        $c = new_curl($url);
        $f = fopen(DATA_DIR.$chunk["filename"], "w");

        curl_setopt($c, CURLOPT_FILE, $f);
        curl_multi_add_handle($mh, $c);

        $connections[] = $c;
        $files[] = $f;

    }

    //execute the handles
    $still_running;
    do {
        $mrc = curl_multi_exec($mh, $still_running);
        curl_multi_select($mh, 5 /* wait x second */);
    } while ($still_running > 0);
    l("all downloads finished");

    $t = $filetime;
    for($i = 0; $i < count($connections); $i++) {
        $c = $connections[$i];
        $chunk = $chunklist[$i];
        if ($c) {
            curl_multi_remove_handle($mh, $c);
            $status = curl_getinfo($c, CURLINFO_HTTP_CODE);
            if ($status == 200) {
                db_add_file($db, $t, $chunk["filename"], $chunk["duration"]);   
            } else {
                l("chunk download failed, status: ".$status.", filename: ".$chunk["filename"]);
            }
            $t += $chunk["duration"];
        }
    }
    $filetime = $t;
    foreach($files as $f) {
        if ($f) {
            fclose($f);
        }
    }
    curl_multi_close($mh);
    db_save($db);
}

function db_is_file_downloaded(&$db, $filename) {
    foreach ($db as $row) {
        if ($row["filename"] == $filename) {
            return true;
        }
    }
    return false;
}
function db_add_file(&$db, $t, $filename, $duration) {
    $db[] = array(
        "time" => $t,
        "filename" => $filename,
        "duration" => $duration
    );
}
function db_save(&$db) {
    global $prefix;
    $f = fopen(DATA_DIR.$prefix.".json", "w");
    fwrite($f, json_encode($db));
    fclose($f);
}

function parse_chunklist($chunklist) {
    $lines = explode("\n", $chunklist);
    $matches = null;
    $chunks = array();
    $this_chunk = array();
    foreach($lines as $line) {
        $line = trim($line);
        if ($line != "") {
            if (preg_match("/^#/", $line)) {
                if (preg_match("/EXTINF:(\d*(\.\d+))/", $line, $matches)) {
                    $this_chunk["duration"] = $matches[1];
                }
            } else {
                $this_chunk["filename"] = $line;
                $chunks[] = $this_chunk;
                $this_chunk = array();
            }
        }
    }
    $this_chunk = null;
    return $chunks;
}

function get_chunklist_list_and_effective_url($chunklist_list_url) {
    l(__FUNCTION__."(), url: ".$chunklist_list_url);
    $c = new_curl($chunklist_list_url);
    curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
    $chunklist = curl_exec($c);
    $status = curl_getinfo($c, CURLINFO_HTTP_CODE);
    $effective_url = curl_getinfo($c, CURLINFO_EFFECTIVE_URL);
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
    $this_list = null;
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

function new_curl($url) {
    global $cookie_jar_path;
    $c = curl_init();
    curl_setopt($c, CURLOPT_URL, $url);
    curl_setopt($c, CURLOPT_COOKIEJAR, $cookie_jar_path);
    curl_setopt($c, CURLOPT_FOLLOWLOCATION, true);
    //curl_setopt($c, CURLOPT_USERAGENT, "curl/7.26.0");

    curl_setopt($c, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
    curl_setopt($c, CURLOPT_PROXY, "localhost:1080");

    return $c;
}
function l($log){
    print $log."\n";
}

init();
fetch_loop();
