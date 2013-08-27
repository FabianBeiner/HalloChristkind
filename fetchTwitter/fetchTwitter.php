<?php
// Locked?
if (file_exists('fetchTwitter.lock')) {
    if ((time() - filemtime('fetchTwitter.lock')) <= 300) {
        die();
    }
}
file_put_contents('fetchTwitter.lock', 'LOCKED');

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

// OAuth Library
require_once 'tmhOAuth.php';
require_once 'tmhUtilities.php';

// Define the Twitter @mention string
$sTwitter = '@HalloChristkind';

// Create OAuth object
$tmhOAuth = new tmhOAuth(array(
    'consumer_key'    => ''
   ,'consumer_secret' => ''
   ,'user_token'      => ''
   ,'user_secret'     => ''
));

// Open SQLite3 database
$oDb = new SQLite3('../HalloChristkind.s3db', SQLITE3_OPEN_READWRITE);

// Get the ID of the latest Tweet
$oResult = $oDb->query('SELECT wish_uid FROM wishes WHERE network = "Twitter" ORDER BY wish_uid DESC LIMIT 1');
$aResult = $oResult->fetchArray(SQLITE3_ASSOC);
$iLast   = $aResult['wish_uid'];

// Get the mentions
if ($iLast === NULL) {
    $iResponse = $tmhOAuth->request('GET', $tmhOAuth->url('1.1/statuses/mentions_timeline'), array('count' => 100, 'trim_user' => false, 'contributor_details' => false, 'include_entities' => false));
}
else {
    $iResponse = $tmhOAuth->request('GET', $tmhOAuth->url('1.1/statuses/mentions_timeline'), array('count' => 100, 'trim_user' => false, 'contributor_details' => false, 'include_entities' => false, 'since_id' => $iLast));
}

// The request worked
if ($iResponse == 200) {
    // Decode response
    $oJson = json_decode($tmhOAuth->response['response']);

    // Handle the results
    foreach ($oJson as $aReply) {
        $sUid      = trim($aReply->user->screen_name);
        $sName     = trim($aReply->user->name);
        $sWish     = ltrim(trim($aReply->text), '.');
        $sWishDate = strtotime(trim($aReply->created_at));
        $sWishUid  = trim($aReply->id_str);

        // If this is a "real" mention (eg. "@HalloChristin Ich wünsche mir …")
        if (substr(strtolower($sWish), 0, strlen($sTwitter)) === strtolower($sTwitter)) {
            $sWish = $oDb->escapeString(cleanString(trim(substr($sWish, strlen($sTwitter)))));

            // Check, if the user already sent a wish
            $oResult  = $oDb->query('SELECT id, wish_date, printed FROM wishes WHERE network = "Twitter" AND uid = "' . $sUid . '" ORDER BY id DESC');
            $aResult  = $oResult->fetchArray(SQLITE3_ASSOC);

            // New wish
            if ($aResult === false) {
                if ($oDb->exec("INSERT INTO wishes (uid, name, wish, wish_date, wish_uid, network, printed) VALUES ('$sUid', '$sName', '$sWish', '$sWishDate', '$sWishUid', 'Twitter', 0)")) {
                    echo date('Y-m-d H:i:s') . ' + Twitter: Neuer Wunsch von "' . $sUid . '" gespeichert.' . PHP_EOL;
                }
                else {
                    file_put_contents('../HalloChristkind.log', date('Y-m-d H:i:s') . " + Twitter: Database failed: INSERT INTO wishes (uid, name, wish, wish_date, wish_uid, network, printed) VALUES ('$sUid', '$sName', '$sWish', '$sWishDate', '$sWishUid', 'Twitter', 0)" . PHP_EOL, FILE_APPEND);
                }
            }
            else {
                // Wish not yet printed
                if (!$aResult['printed']) {
                    // New wish is newer than the one in database
                    if ($sWishDate > $aResult['wish_date']) {
                        if ($oDb->exec("UPDATE wishes SET wish = '$sWish', wish_date = '$sWishDate', wish_uid = '$sWishUid' WHERE uid = '$sUid'")) {
                            echo date('Y-m-d H:i:s') . ' + Twitter: Wunsch von "' . $sUid . '" erneuert.' . PHP_EOL;
                        }
                        else {
                            file_put_contents('../HalloChristkind.log', date('Y-m-d H:i:s') . " + Twitter: Database failed: UPDATE wishes SET wish = '$sWish', wish_date = '$sWishDate', wish_uid = '$sWishUid' WHERE uid = '$sUid'" . PHP_EOL, FILE_APPEND);
                        }
                    }
                }
            }
        }
    }
}
else {
    file_put_contents('../HalloChristkind.log', date('Y-m-d H:i:s') . ' + Twitter: Request failed: ' . print_r($tmhOAuth->response['response'], true)  . PHP_EOL, FILE_APPEND);
}

// Close database
$oDb->close();
unlink('fetchTwitter.lock');
