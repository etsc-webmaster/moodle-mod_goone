<?php
// this was built in a block plugin to show in course page and run per course
//to fetch completion data even if not in the go1completion table
//I'll enhance more to be used as cron task
require_once(dirname(__FILE__) . '/../../config.php');


$courseid = optional_param('course', 0, PARAM_INT);
$modcontext = context_course::instance($courseid, MUST_EXIST);
$goonemodules = $DB->get_records("goone", array("course" => $courseid));

sam_goone_generatetoken();
$course = $DB->get_record("course", array("id" => $courseid));

$courseusers = get_enrolled_users($modcontext);

$countt=1;
foreach ($courseusers as $courseuser) {
 if($courseuser->suspended==1){
 continue;
 }
    $fullname = explode(' ', $courseuser->firstname . ' ' . $courseuser->lastname, 2);
    $firstname = strtolower($fullname[0]);
    $lastname = strtolower($fullname[1]);

    $userfromgo1s = goone_getuser($courseuser);
    echo "<br>".$countt."-".count($courseusers)." => ".$courseuser->firstname . ' ' . $courseuser->lastname;
    $countt++;
    if (!isset($userfromgo1s->hits) ||$userfromgo1s->total == 0) {
        echo "<br><div style='color:red'>Not in Go1 </div>";
        continue;
    }

    foreach ($userfromgo1s->hits as $g1udata) {
       // echo strtolower($g1udata->first_name) . ' - ' . $firstname . ' - ' . strtolower($g1udata->last_name) . ' - ' . $lastname . "<br>";
        if (strtolower($g1udata->first_name) == $firstname && strtolower($g1udata->last_name) == $lastname) {

            foreach ($goonemodules as $goonemodule) {
                $lmsmid = $DB->get_record("course_modules", array("module" => 27, "course" => $courseid, "instance" => $goonemodule->id));
                $lmscompletion = $DB->get_record('course_modules_completion', ['coursemoduleid' => $lmsmid->id, 'userid' => $courseuser->id]);
                //completionstate //viewed
                if ((isset($lmscompletion->completionstate) && $lmscompletion->completionstate > 0) && (isset($lmscompletion->viewed) && $lmscompletion->viewed > 0)) {
                    echo "<br><div style='color:green'>Already completed </div> -> ".$goonemodule->name;
                    continue;
                }
               



                $completionss = goone_completion($g1udata->id, $goonemodule->loid);
                if (!isset($completionss->hits)) {
                     echo "<br><div style='color:blue'>Not started </div>  -> ".$goonemodule->name;
                    continue;
                }
                foreach ($completionss->hits as $completions) {
                    if ($completions->status == "completed") {
                            echo "<br><div style='color:brown'><b>completion set for</b></div>: ".$goonemodule->name;
                                             

                       if ((isset($lmscompletion->completionstate) && $lmscompletion->completionstate == 0) || (isset($lmscompletion->viewed) && $lmscompletion->viewed == 0)) {

                            $lmscompletion->completionstate = 1;
                            $lmscompletion->viewed = 1;
                            $lmscompletion->timemodified = strtotime($completions->end_date);
                            $DB->update_record('course_modules_completion', $lmscompletion);
                        } else {

                            $newcmc = new stdClass();
                            $newcmc->coursemoduleid = $lmsmid->id;
                            $newcmc->userid = $courseuser->id;
                            $newcmc->completionstate = 1;
                            $newcmc->viewed = 1;
                            $newcmc->timemodified = strtotime($completions->end_date);
                            $DB->insert_record('course_modules_completion', $newcmc);
                        }



                    }else{
                         echo "<br><div style='color:blue'>Not completed </div>  -> ".$goonemodule->name;
                    }


                }


            }
        }else{
            echo "<br><div style='color:blue'>check this user </div>  ";
        }
    }
}



function goone_getuser($courseuser)
{
    global $CFG, $DB;
    $fullname = explode(' ', $courseuser->firstname . ' ' . $courseuser->lastname, 2);
    $firstname = urlencode($fullname[0]);
    $lastname = urlencode($fullname[1]);

    $token = get_config('block_getgoone', 'token');

    $headers = array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    );



    $serverurl = "https://api.go1.com/v2/users?first_name=" . $firstname . "&last_name=" . $lastname;


    $cURLConnection = curl_init($serverurl);
    curl_setopt($cURLConnection, CURLOPT_HTTPHEADER, $headers);
    //curl_setopt($cURLConnection, CURLOPT_POSTFIELDS, $params);
    curl_setopt($cURLConnection, CURLOPT_RETURNTRANSFER, true);

    $apiResponse = curl_exec($cURLConnection);
    $info = curl_getinfo($cURLConnection);
    $curloutput = json_decode($apiResponse);


    if ($info['http_code'] == 200 && isset($curloutput->hits)) {
        return $curloutput;
    }

}


function sam_goone_generatetoken()
{
    global $CFG, $DB;

    $oauthid = get_config('mod_goone', 'client_id');
    $oauthsecret = get_config('mod_goone', 'client_secret');
    $params = array(
        'client_id' => $oauthid,
        'client_secret' => $oauthsecret,
        'grant_type' => 'client_credentials'
    );


    $serverurl = "https://auth.GO1.com/oauth/token";


    $cURLConnection = curl_init($serverurl);
    curl_setopt($cURLConnection, CURLOPT_POSTFIELDS, $params);
    curl_setopt($cURLConnection, CURLOPT_RETURNTRANSFER, true);

    $apiResponse = curl_exec($cURLConnection);
    curl_close($cURLConnection);

    $curloutput = json_decode($apiResponse);
    //$curlinfo = $curl->get_info();
    if (isset($curloutput->access_token)) {
        set_config('token', $curloutput->access_token, 'block_getgoone');
    } else {
        return false;
    }
}


function goone_completion($go1userid, $loid)
{

    $token = get_config('block_getgoone', 'token');

    $headers = array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    );


    $serverurl = "https://api.go1.com/v2/enrollments?status=completed&exclude_child_enrollments=true&lo_ids=" . $loid . "&user_id=" . $go1userid;


    $cURLConnection = curl_init($serverurl);
    curl_setopt($cURLConnection, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($cURLConnection, CURLOPT_RETURNTRANSFER, true);

    $apiResponse = curl_exec($cURLConnection);
    $info = curl_getinfo($cURLConnection);
    $curloutput = json_decode($apiResponse);


    if ($info['http_code'] == 200 && $curloutput->total > 0) {
        return $curloutput;
    }

}

?>