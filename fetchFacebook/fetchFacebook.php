<?php
// Locked?
if (file_exists('fetchFacebook.lock')) {
    if ((time() - filemtime('fetchFacebook.lock')) <= 300) {
        die();
    }
}
file_put_contents('fetchFacebook.lock', 'LOCKED');

// Clean incoming strings
function cleanString($sText) {
    setlocale(LC_ALL, 'de_DE');
    $sText = strip_tags($sText);
    $sText = html_entity_decode($sText);
    $sText = iconv('utf-8', 'ascii//TRANSLIT', $sText);
    $sText = preg_replace('/[^(\x20-\x7F)]*/', '', $sText);
    $sText = str_replace(array('\r\n', '\n', '\r', '\t'), array(' ',' ',' ',''), $sText);
    return $sText;
}

// Short string
function truncate($str, $len) {
    $tail = max(0, $len-10);
    $trunk = substr($str, 0, $tail);
    $trunk .= strrev(preg_replace('~^..+?[\s,:]\b|^...~', '...', strrev(substr($str, $tail, $len-$tail))));
    return $trunk;
}

// Load Feed
function loadFeed($sPage, $sSince = false) {
    $sUrl = 'https://graph.facebook.com/' . $sPage. '/feed?access_token=###THIS-IS-A-SECRET-YOU-WILL-HAVE-TO-FIND-OUT-FOR-YOURSELF###&limit=100&' . (($sSince) ? 'since=' . $sSince : '');
    $oCurl = curl_init($sUrl);
    curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($oCurl, CURLOPT_SSL_VERIFYHOST, false);
    $page = json_decode(curl_exec($oCurl));
    curl_close($oCurl);
    return $page->data;
}

// Open SQLite3 database
$oDb = new SQLite3('../HalloChristkind.s3db', SQLITE3_OPEN_READWRITE);

// Get the timestamp of the latest entry
$oResult = $oDb->query('SELECT wish_date FROM wishes WHERE network = "Facebook" ORDER BY wish_date DESC LIMIT 1');
$aResult = $oResult->fetchArray(SQLITE3_ASSOC);
$iLast   = $aResult['wish_date'];

// Get the updates
$oFeed = loadFeed('HalloChristkind', $iLast);
if ($oFeed) {
    foreach ($oFeed as $oPost) {
        if ($oPost->type == 'status' && $oPost->from->id != 383756825043458) {
            $sUid      = trim($oPost->from->id);
            $sName     = trim($oPost->from->name);
            $sWish     = $oDb->escapeString(truncate(cleanString(trim($oPost->message)), 300));
            $sWishDate = strtotime(trim($oPost->created_time));
            $sWishUid  = trim($oPost->id);

            // Check, if the user already sent a wish
            $oResult  = $oDb->query('SELECT id, wish_date, printed FROM wishes WHERE network = "Facebook" AND uid = "' . $sUid . '" ORDER BY id DESC');
            $aResult  = $oResult->fetchArray(SQLITE3_ASSOC);

            // New wish
            if ($aResult === false) {
                if ($oDb->exec("INSERT INTO wishes (uid, name, wish, wish_date, wish_uid, network, printed) VALUES ('$sUid', '$sName', '$sWish', '$sWishDate', '$sWishUid', 'Facebook', 0)")) {
                    echo date('Y-m-d H:i:s') . ' + Facebook: Neuer Wunsch von "' . $sName . '" gespeichert.' . PHP_EOL;
                }
                else {
                    file_put_contents('../HalloChristkind.log', date('Y-m-d H:i:s') . " + Facebook: Database failed: INSERT INTO wishes (uid, name, wish, wish_date, wish_uid, network, printed) VALUES ('$sUid', '$sName', '$sWish', '$sWishDate', '$sWishUid', 'Facebook', 0)" . PHP_EOL, FILE_APPEND);
                }
            }
            else {
                // Wish not yet printed
                if (!$aResult['printed']) {
                    // New wish is newer than the one in database
                    if ($sWishDate > $aResult['wish_date']) {
                        if ($oDb->exec("UPDATE wishes SET wish = '$sWish', wish_date = '$sWishDate', wish_uid = '$sWishUid' WHERE uid = '$sUid'")) {
                            echo date('Y-m-d H:i:s') . ' + Facebook: Wunsch von "' . $sName . '" erneuert.' . PHP_EOL;
                        }
                        else {
                            file_put_contents('../HalloChristkind.log', date('Y-m-d H:i:s') . " + Twitter: Database failed: UPDATE wishes SET wish = '$sWish', wish_date = '$sWishDate', wish_uid = '$sWishUid' WHERE uid = '$sUid'" . PHP_EOL, FILE_APPEND);
                        }
                    }
                }
            }
        }
    }
} else {
    file_put_contents('../HalloChristkind.log', date('Y-m-d H:i:s') . ' + Facebook: Request failed!' . PHP_EOL, FILE_APPEND);
}

// Close database
$oDb->close();
unlink('fetchFacebook.lock');
