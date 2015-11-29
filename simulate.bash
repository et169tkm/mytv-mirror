#!/bin/bash

SCRIPT_TIME=`date '+%s'`
RECORD_DURATAION=7200

URL_TOKEN_JADE=http://token.tvb.com/stream/live/hls/mobilehd_jade.smil
URL_TOKEN_JADE_HD=http://token.tvb.com/stream/live/hls/mobilehd_hdj.smil
URL_TOKEN_INEWS=http://token.tvb.com/stream/live/hls/mobilehd_inews.smil
URL_TOKEN_J2=http://token.tvb.com/stream/live/hls/mobilehd_j2.smil

###### 576
curl -s -X POST -o "$SCRIPT_TIME-url-json.txt" "$URL_TOKEN_INEWS?feed"
cat "$SCRIPT_TIME-url-json.txt" | python -c 'import sys, json; print json.load(sys.stdin)["url"]' > "$SCRIPT_TIME-url.txt"
URL1=`cat "$SCRIPT_TIME-url.txt"`
file_count=0

while true; do
    ###### 1490
    # this creates a session, and redirect us to the session-specific url
    CHUNKLIST_TIME=`date +%s`
    URL2=`curl -Ls -o /dev/null -w %{url_effective} $URL1` # get the effective url (redirected url), some string swapping will be done later
    echo "session playlist url: $URL2"
    curl -s -o "$SCRIPT_TIME-playlist-$chunklist_count.txt" "$URL2"
    
    
    ###### 1543 this opens the chunklist url
    # read the file we last download, then put the chunklist file name into $URL2
    # get the first chunk list
    CHUNKLIST_NAME=`cat "$SCRIPT_TIME-playlist-$chunklist_count.txt" | grep -v "^#" | head -n 1`
    echo "chunklist name: $CHUNKLIST_NAME"
    
    # subsitute playlist.m3u8 with the first chunk list name
    URL3=`echo -n "$URL2" | sed "s/playlist.m3u8/$CHUNKLIST_NAME/"`
    echo -n $URL3
    curl -s -o "$SCRIPT_TIME-chunklist-$chunklist_count.txt" $URL3
    
    ###### download the real media files
    for name in `cat "$SCRIPT_TIME-chunklist-$chunklist_count.txt" | grep -v "^#"`; do
        URL4=`echo -n "$URL2" | sed "s/playlist.m3u8/$name/"`
        echo "Downloading from: $URL4"
        #echo "$name"
        curl -o "$SCRIPT_TIME-$file_count.ts" "$URL4"
        file_count=$(( file_count+1 ))
    done


    ###### wait until this chunklist finish
    expression=''
    for x in `cat "$SCRIPT_TIME-chunklist-$chunklist_count.txt" | grep 'EXTINF' | awk -F ':' '{print $2}' | tr -d ','`; do
        expression="$expression+$x"
    done
    expression="$expression+0"
    length=$(perl -e "print $expression")
    now=`date +%s`
    wait=$(( $CHUNKLIST_TIME + $length - $now ))

    next_chunklist_time=$(( $CHUNKLIST_TIME + $length ))
    finish_time=$(( $SCRIPT_TIME + $RECORD_DURATAION))
    if [ $finish_time -le $next_chunklist_time ]; then
        #finished downloading
        echo "Finished downloading"
        break
    else
        if [ $wait -gt 0 ]; then
            echo "Wait $wait seconds"
            sleep "$wait"
        else
            echo "Don't wait, start next chunklist right away"
        fi
    fi
done

