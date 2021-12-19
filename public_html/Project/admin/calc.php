<?php
//snippet from my functions.php
function calc_winners()
{
    $db = getDB();
    elog("Starting winner calc");
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
                                if (change_bills($fpr, "won-comp", -1, $aid, "First place in $title with score of $score")) {
                                    $atleastOne = true;
                                }
                                elog("User $user_id First place in $title with score of $score");
                            } else if ($index == 1) {
                                if (change_bills($spr, "won-comp", -1, $aid, "Second place in $title with score of $score")) {
                                    $atleastOne = true;
                                }
                                elog("User $user_id Second place in $title with score of $score");
                            } else if ($index == 2) {
                                if (change_bills($tpr, "won-comp", -1, $aid, "Third place in $title with score of $score")) {
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
            elog("No competitions ready");
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
        elog("No competitions to calc");
    }
    //close invalid comps
    $stmt = $db->prepare("UPDATE BGD_Competitions set did_calc = 1 WHERE expires <= CURRENT_TIMESTAMP() AND current_participants < min_participants AND did_calc = 0");
    try {
        $stmt->execute();
        $rows = $stmt->rowCount();
        elog("Closed $rows invalid competitions");
    } catch (PDOException $e) {
        error_log("Closing invalid comps error: " . var_export($e, true));
    }
    elog("Done calc winners");
}