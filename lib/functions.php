<?php
require_once(__DIR__ . "/db.php");
$BASE_PATH = '/Project/';//This is going to be a helper for redirecting to our base project path since it's nested in another folder
function se($v, $k = null, $default = "", $isEcho = true) {
    if (is_array($v) && isset($k) && isset($v[$k])) {
        $returnValue = $v[$k];
    } else if (is_object($v) && isset($k) && isset($v->$k)) {
        $returnValue = $v->$k;
    } else {
        $returnValue = $v;
        //added 07-05-2021 to fix case where $k of $v isn't set
        //this is to kep htmlspecialchars happy
        if (is_array($returnValue) || is_object($returnValue)) {
            $returnValue = $default;
        }
    }
    if (!isset($returnValue)) {
        $returnValue = $default;
    }
    if ($isEcho) {
        //https://www.php.net/manual/en/function.htmlspecialchars.php
        echo htmlspecialchars($returnValue, ENT_QUOTES);
    } else {
        //https://www.php.net/manual/en/function.htmlspecialchars.php
        return htmlspecialchars($returnValue, ENT_QUOTES);
    }
}
//TODO 2: filter helpers
function sanitize_email($email = "") {
    return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
}
function is_valid_email($email = "") {
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL);
}
//TODO 3: User Helpers
function is_logged_in() {
    return isset($_SESSION["user"]); //se($_SESSION, "user", false, false);
}
function has_role($role) {
    if (is_logged_in() && isset($_SESSION["user"]["roles"])) {
        foreach ($_SESSION["user"]["roles"] as $r) {
            if ($r["name"] === $role) {
                return true;
            }
        }
    }
    return false;
}
function get_username() {
    if (is_logged_in()) { //we need to check for login first because "user" key may not exist
        return se($_SESSION["user"], "username", "", false);
    }
    return "";
}
function get_user_email() {
    if (is_logged_in()) { //we need to check for login first because "user" key may not exist
        return se($_SESSION["user"], "email", "", false);
    }
    return "";
}
function get_user_id() {
    if (is_logged_in()) { //we need to check for login first because "user" key may not exist
        return se($_SESSION["user"], "id", false, false);
    }
    return false;
}
//TODO 4: Flash Message Helpers
function flash($msg = "", $color = "info")
{
    $message = ["text" => $msg, "color" => $color];
    if (isset($_SESSION['flash'])) {
        array_push($_SESSION['flash'], $message);
    } else {
        $_SESSION['flash'] = array();
        array_push($_SESSION['flash'], $message);
    }
}

function save_score($score, $user_id, $showFlash = false)
{
    if ($user_id < 1) {
        flash("Error saving score, you may not be logged in", "warning");
        return;
    }
    if ($score <= 0) {
        flash("Scores of zero are not recorded", "warning");
        return;
    }
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO BGD_Scores (score, user_id) VALUES (:score, :uid)");
    try {
        $stmt->execute([":score" => $score, ":uid" => $user_id]);
        if ($showFlash) {
            flash("Saved score of $score", "success");
        }
    } catch (PDOException $e) {
        flash("Error saving score: " . var_export($e->errorInfo, true), "danger");
    }
}
//Get top 10's
/** Gets the top 10 scores for valid durations (day, week, month, lifetime) */
function get_top_10($duration = "day")
{
    $d = "day";
    if (in_array($duration, ["day", "week", "month", "lifetime"])) {
        //variable is safe
        $d = $duration;
    }
    $db = getDB();
    $query = "SELECT user_id,username, score, BGD_Scores.created from BGD_Scores join Users on BGD_Scores.user_id = Users.id";
    if ($d !== "lifetime") {
        //be very careful passing in a variable directly to SQL, I ensure it's a specific value from the in_array() above
        $query .= " WHERE BGD_Scores.created >= DATE_SUB(NOW(), INTERVAL 1 $d)";
    }
    //remember to prefix any ambiguous columns (Users and Scores both have created)
    $query .= " ORDER BY score Desc, BGD_Scores.created desc LIMIT 10"; //newest of the same score is ranked higher
    error_log($query);
    $stmt = $db->prepare($query);
    $results = [];
    try {
        $stmt->execute();
        $r = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($r) {
            $results = $r;
        }
    } catch (PDOException $e) {
        error_log("Error fetching scores for $d: " . var_export($e->errorInfo, true));
    }
    return $results;
}

function get_best_score($user_id)
{
    $query = "SELECT score from BGD_Scores WHERE user_id = :id ORDER BY score desc LIMIT 1";
    $db = getDB();
    $stmt = $db->prepare($query);
    try {
        $stmt->execute([":id" => $user_id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            return (int)se($r, "score", 0, false);
        }
    } catch (PDOException $e) {
        error_log("Error fetching best score for user $user_id: " . var_export($e->errorInfo, true));
    }
    return 0;
}

function get_latest_scores($user_id, $limit = 10)
{
    if ($limit < 1 || $limit > 50) {
        $limit = 10;
    }
    $query = "SELECT score, created from BGD_Scores where user_id = :id ORDER BY created desc LIMIT :limit";
    $db = getDB();
    //IMPORTANT: this is required for the execute to set the limit variables properly
    //otherwise it'll convert the values to a string and the query will fail since LIMIT expects only numerical values and doesn't cast
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    //END IMPORTANT

    $stmt = $db->prepare($query);
    try {
        $stmt->execute([":id" => $user_id, ":limit" => $limit]);
        $r = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($r) {
            return $r;
        }
    } catch (PDOException $e) {
        error_log("Error getting latest $limit scores for user $user_id: " . var_export($e->errorInfo, true));
    }
    return [];
}
//snippet from my functions.php
/**
 * Will fetch the account of the logged in user, or create a new one if it doesn't exist yet.
 * Exists here so it may be called on any desired page and not just login
 * Will populate/refresh $_SESSION["user"]["account"] regardless.
 * Make sure this is called after the session has been set
 */
function get_random_str($length)
{
    //https://stackoverflow.com/a/13733588
    //$bytes = random_bytes($length / 2);
    //return bin2hex($bytes);

    //https://stackoverflow.com/a/40974772
    return substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', 36)), 0, $length);
}

function get_points()
{
    if (is_logged_in()) { //we need to check for login first because "user" key may not exist
        return (int)se($_SESSION["user"], "points", 0,false);
    }
    return 0;
}
function get_user_account_id()
{
    if (is_logged_in() && isset($_SESSION["user"])) {
        return (int)se($_SESSION["user"], "id", 0, false);
    }
    return 0;
}
function change_points($user_id, $point_change, $reason)
{
    if ($point_change != 0) {
        $query = "INSERT INTO Point_History (user_id, point_change, reason)
            Values (:usr, :pc, :r)";
        //I'll insert both records at once, note the placeholders kept the same and the ones changed.
       
        $params[":usr"] = $user_id;
        $params[":pc"] = $point_change;
        $params[":r"] = $reason;
        error_log("Points change query $query");
        error_log("Points change params: " . var_export($params, true));
        $db = getDB();
        $stmt = $db->prepare($query);
        try {
            $stmt->execute($params);
            //Only refresh the points of the user if the logged in user's id is part of the transfer
            //this is needed so future features don't waste time/resources or potentially cause an error when a calculation 
            error_log("Points changed for $user_id by $point_change for $reason");
            refresh_account_balance($user_id);
            error_log("After balance refresh");
            return true;
        } catch (PDOException $e) {
            flash("Error transfering points: " . var_export($e->errorInfo, true), "danger");
            error_log("error transfering points: " . var_export($e, true));
        }
    }
    return false;
}

function refresh_account_balance($user_id)
{
    if (is_logged_in()) {
        //cache account balance via Point_History history
        $query = "UPDATE Users set points = (SELECT IFNULL(SUM(point_change), 0) from Point_History WHERE user_id= :usr) WHERE id = :usr";
        $db = getDB();
        $stmt = $db->prepare($query);
        $stmt->execute([":usr"=>$user_id]);
        $stmt = $db->prepare("SELECT points from Users where id = :uid");
        try{
            $stmt->execute([":usr"=>$user_id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $_SESSION["user"]["points"] = (int)se($r, "points", 0, false);
        }
        catch (PDOException $e) 
        {
            error_log("error getting points: " . var_export($e, true));
        }
    }
}
function save_data($table, $data, $ignore = ["submit"])
{
    $table = se($table, null, null, false);
    $db = getDB();
    $query = "INSERT INTO $table "; //be sure you trust $table
    //https://www.php.net/manual/en/functions.anonymous.php Example#3
    $columns = array_filter(array_keys($data), function ($x) use ($ignore) {
        return !in_array($x, $ignore); // $x !== "submit";
    });
    //arrow function uses fn and doesn't have return or { }
    //https://www.php.net/manual/en/functions.arrow.php
    $placeholders = array_map(fn ($x) => ":$x", $columns);
    $query .= "(" . join(",", $columns) . ") VALUES (" . join(",", $placeholders) . ")";

    $params = [];
    foreach ($columns as $col) {
        $params[":$col"] = $data[$col];
    }
    $stmt = $db->prepare($query);
    try {
        $stmt->execute($params);
        //https://www.php.net/manual/en/pdo.lastinsertid.php
        //echo "Successfully added new record with id " . $db->lastInsertId();
        return $db->lastInsertId();
    } catch (PDOException $e) {
        //echo "<pre>" . var_export($e->errorInfo, true) . "</pre>";
        flash("<pre>" . var_export($e->errorInfo, true) . "</pre>");
        return -1;
    }
}
function redirect($path)
{ //header headache
    //https://www.php.net/manual/en/function.headers-sent.php#90160
    /*headers are sent at the end of script execution otherwise they are sent when the buffer reaches it's limit and emptied */
    if (!headers_sent()) {
        //php redirect
        die(header("Location: " . get_url($path)));
    }
    //javascript redirect
    echo "<script>window.location.href='" . get_url($path) . "';</script>";
    //metadata redirect (runs if javascript is disabled)
    echo "<noscript><meta http-equiv=\"refresh\" content=\"0;url=" . get_url($path) . "\"/></noscript>";
    die();
}
function get_url($dest)
{
    global $BASE_PATH;
    if (str_starts_with($dest, "/")) {
        //handle absolute path
        return $dest;
    }
    //handle relative path
    return $BASE_PATH . $dest;
}
function users_check_duplicate($errorInfo)
{
    if ($errorInfo[1] === 1062) {
        //https://www.php.net/manual/en/function.preg-match.php
        preg_match("/Users.(\w+)/", $errorInfo[2], $matches);
        if (isset($matches[1])) {
            flash("The chosen " . $matches[1] . " is not available.", "warning");
        } else {
            //TODO come up with a nice error message
            flash("<pre>" . var_export($errorInfo, true) . "</pre>");
        }
    } else {
        //TODO come up with a nice error message
        flash("<pre>" . var_export($errorInfo, true) . "</pre>");
    }
}
function getMessages()
{
    if (isset($_SESSION['flash'])) {
        $flashes = $_SESSION['flash'];
        $_SESSION['flash'] = array();
        return $flashes;
    }
    return array();
}
function reset_session()
{
    session_unset();
    session_destroy();
    session_start();
}
function paginate($query, $params = [], $per_page = 10)
{
    global $page; //will be available after function is called
    try {
        $page = (int)se($_GET, "page", 1, false);
    } catch (Exception $e) {
        //safety for if page is received as not a number
        $page = 1;
    }
    $db = getDB();
    $stmt = $db->prepare($query);
    try {
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("paginate error: " . var_export($e, true));
    }
    $total = 0;
    if (isset($result)) {
        $total = (int)se($result, "total", 0, false);
    }
    global $total_pages; //will be available after function is called
    $total_pages = ceil($total / $per_page);
    global $offset; //will be available after function is called
    $offset = ($page - 1) * $per_page;
}
//updates or inserts page into query string while persisting anything already present
function persistQueryString($page)
{
    $_GET["page"] = $page;
    return http_build_query($_GET);
}
function elog($data)
{
    echo "<br>" . var_export($data, true) . "<br>";
    error_log(var_export($data, true));
}
function calc_winners()
{
    $db = getDB();
    //elog("Starting winner calc");
    $calced_comps = [];
    $stmt = $db->prepare("select c.id,c.title, first_place, second_place, third_place, current_reward 
    from BGD_Competitions c JOIN BGD_Payout_Options po on c.payout_option = po.id 
    where expires <= CURRENT_TIMESTAMP() AND did_calc = 0 AND current_participants >= min_participants LIMIT 10");
    try {
        $stmt->execute();
        $r = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($r) {
            $rc = $stmt->rowCount();
            elog("Validating $rc comps");
            foreach ($r as $row) {
                $fp = floatval(se($row, "first_place", 0, false) / 100);
                $sp = floatval(se($row, "second_place", 0, false) / 100);
                $tp = floatval(se($row, "third_place", 0, false) / 100);
                $reward = (int)se($row, "current_reward", 0, false);
                $title = se($row, "title", "-", false);
                $fpr = ceil($reward * $fp);
                $spr = ceil($reward * $sp);
                $tpr = ceil($reward * $tp);
                $comp_id = se($row, "id", -1, false);
                
                try {
                    $r = get_top_scores_for_comp($comp_id, 3);
                    if ($r) {
                        $atleastOne = false;
                        foreach ($r as $index => $row) {
                            $aid = se($row, "account_id", -1, false);
                            $score = se($row, "score", 0, false);
                            $user_id = se($row, "user_id", -1, false);
                            if ($index == 0) {
                                if (change_points($fpr, "won-comp", -1, $aid, "First place in $title with score of $score")) {
                                    $atleastOne = true;
                                }
                                elog("User $user_id First place in $title with score of $score");
                            } else if ($index == 1) {
                                if (change_points($spr, "won-comp", -1, $aid, "Second place in $title with score of $score")) {
                                    $atleastOne = true;
                                }
                                elog("User $user_id Second place in $title with score of $score");
                            } else if ($index == 1) {
                                if (change_points($tpr, "won-comp", -1, $aid, "Third place in $title with score of $score")) {
                                    $atleastOne = true;
                                }
                                elog("User $user_id Third place in $title with score of $score");
                            }
                        }
                        if ($atleastOne) {
                            array_push($calced_comps, $comp_id);
                        }
                    } else {
                        elog("No eligible scores");
                    }
                } catch (PDOException $e) {
                    error_log("Getting winners error: " . var_export($e, true));
                }
            }
        } else {
            //elog("No competitions ready");
        }
    } catch (PDOException $e) {
        error_log("Getting Expired Comps error: " . var_export($e, true));
    }
    //closing calced comps
    if (count($calced_comps) > 0) {
        $query = "UPDATE BGD_Competitions set did_calc = 1 AND did_payout = 1 WHERE id in ";
        $query = "(" . str_repeat("?,", count($calced_comps) - 1) . "?)";
        elog("Close query: $query");
        $stmt = $db->prepare($query);
        try {
            $stmt->execute($calced_comps);
            $updated = $stmt->rowCount();
            elog("Marked $updated comps complete and calced");
        } catch (PDOException $e) {
            error_log("Closing valid comps error: " . var_export($e, true));
        }
    } else {
        //elog("No competitions to calc");
    }
    //close invalid comps
    $stmt = $db->prepare("UPDATE BGD_Competitions set did_calc = 1 WHERE expires <= CURRENT_TIMESTAMP() AND current_participants < min_participants AND did_calc = 0");
    try {
        $stmt->execute();
        $rows = $stmt->rowCount();
        //elog("Closed $rows invalid competitions");
    } catch (PDOException $e) {
        error_log("Closing invalid comps error: " . var_export($e, true));
    }
}
    //elog("Done calc winners");
    function update_participants($comp_id)
{
    $db = getDB();
    $stmt = $db->prepare("UPDATE BGD_Competitions set current_participants = (SELECT IFNULL(COUNT(1),0) FROM BGD_UserComps WHERE competition_id = :cid), 
    current_reward = IF(join_cost > 0, current_reward + CEILING(join_cost * 0.5), current_reward) WHERE id = :cid");
    try {
        $stmt->execute([":cid" => $comp_id]);
        return true;
    } catch (PDOException $e) {
        error_log("Update competition participant error: " . var_export($e, true));
    }
    return false;
}

function add_to_competition($comp_id, $user_id)
{
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO BGD_UserComps (user_id, competition_id) VALUES (:uid, :cid)");
    try {
        $stmt->execute([":uid" => $user_id, ":cid" => $comp_id]);
        update_participants($comp_id);
        return true;
    } catch (PDOException $e) {
        error_log("Join Competition error: " . var_export($e, true));
    }
    return false;
}
function get_top_scores_for_comp($comp_id, $limit = 10)
{
    $db = getDB();
    //below if a user can win more than one place
    /*$stmt = $db->prepare(
        "SELECT score, s.created, username, u.id as user_id FROM BGD_Scores s 
    JOIN BGD_UserComps uc on uc.user_id = s.user_id 
    JOIN BGD_Competitions c on c.id = uc.competition_id
    JOIN Users u on u.id = s.user_id WHERE c.id = :cid AND s.score >= c.min_score AND s.created 
    BETWEEN uc.created AND c.expires ORDER BY s.score desc LIMIT :limit"
    );*/
    //Below if a user can't win more than one place
    $stmt = $db->prepare("SELECT * FROM (SELECT s.user_id, s.score,s.created, a.id as account_id, DENSE_RANK() OVER (PARTITION BY s.user_id ORDER BY s.score desc) as `rank` FROM BGD_Scores s
    JOIN BGD_UserComps uc on uc.user_id = s.user_id
    JOIN BGD_Competitions c on uc.competition_id = c.id
    WHERE c.id = :cid AND s.created BETWEEN uc.created AND c.expires
    )as t where `rank` = 1 ORDER BY score desc LIMIT :limit");
    $scores = [];
    try {
        $stmt->bindValue(":cid", $comp_id, PDO::PARAM_INT);
        $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
        $stmt->execute();
        $r = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($r) {
            $scores = $r;
        }
    } catch (PDOException $e) {
        flash("There was a problem fetching scores, please try again later", "danger");
        error_log("List competition scores error: " . var_export($e, true));
    }
    return $scores;
}

function join_competition($comp_id, $user_id, $cost)
{
    $balance = get_points();
    if ($comp_id > 0) {
        if ($balance >= $cost) {
            $db = getDB();
            $stmt = $db->prepare("SELECT title, join_cost from BGD_Competitions where id = :id");
            try {
                $stmt->execute([":id" => $comp_id]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $cost = (int)se($r, "join_cost", 0, false);
                    $name = se($r, "title", "", false);
                    if ($balance >= $cost) {
                        if (change_points($user_id, -$cost, "Comp" )) {
                            if (add_to_competition($comp_id, $user_id)) {
                                flash("Successfully joined $name", "success");
                            }
                        } else {
                            flash("Failed to pay for competition", "danger");
                        }
                    } else {
                        flash("You can't afford to join this competition", "warning");
                    }
                }
            } catch (PDOException $e) {
                error_log("Comp lookup error " . var_export($e, true));
                flash("There was an error looking up the competition", "danger");
            }
        } else {
            flash("You can't afford to join this competition", "warning");
        }
    } else {
        flash("Invalid competition, please try again", "danger");
    }
}
?>