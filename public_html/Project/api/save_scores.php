<?php

$response = ["message" => "There was a problem saving your score"];
http_response_code(400);

$data = $_POST;
error_log("req: " . var_export($data, true));
if (isset($data["score"])) {
    session_start();
    $reject = false;
    require_once(__DIR__ . "/../../../lib/functions.php");
    $user_id = get_user_id();
    if ($user_id <= 0) {
        $reject = true;
        error_log("User not logged in");
        http_response_code(403);
        $response["message"] = "You must be logged in to save your score";
        flash($response["message"], "warning");
    }
    if (!$reject) {
        $score = (int)se($data, "score", 0, false);
            http_response_code(200);
            error_log("saving score");
            save_score($score, $user_id, true);
            //purchase feature to pay to earn points (free play doesn't earn)
           
                $response["message"] = "Score Saved!";
            
            error_log("Score of $score saved successfully for $user_id");
       
    }
}
echo json_encode($response);