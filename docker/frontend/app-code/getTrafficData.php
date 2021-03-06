<?php
$ini = parse_ini_file('config.ini.php');
define('GOOGLE_API_KEY', $ini['GOOGLE_API_KEY']);

$metric_name = "google_maps_call_duration";
$tag_name = "TitoTier";
$tag_value = "TitoFE";

// Extract parameters from URL
$needed_params = array("home_addr", "home_time", "work_addr", "work_time","home_range");

$params = extractParametersFromUrl($needed_params);


# Appel des googles API, on recupere la date avant et apres pour l'envoyer au tracing
$time_before=microtime(true);
$result = getTrafficData($params);
$logLine = "Info: Google API Requests finished.";
error_log(print_r($logLine, TRUE));
$time_after=microtime(true);


# send traces to prometheus
$home_addr_no_space = preg_replace('/\s+/', '', $params['home_addr']);
$home_addr_no_space = preg_replace('/[\x00-\x1F\x7F]/', '', $home_addr_no_space);
#$home_addr_no_space = utf8_decode($home_addr_no_space);
$work_addr_no_space = preg_replace('/\s+/', '', $params['work_addr']);
$work_addr_no_space = preg_replace('/[\x00-\x1F\x7F]/', '', $work_addr_no_space);
#$work_addr_no_space = utf8_decode($work_addr_no_space);
$time_diff = $time_after - $time_before;
# $cmd = "/var/www/html/sendTraces.py " . $time_diff . " " . $home_addr_no_space . " " . $work_addr_no_space . " > /dev/null 2>/dev/null &";
# $resultat = shell_exec($cmd);
# $logLine = "Info: sendTraces.py finished.";
$payload = join("\n", ['# TYPE tito_request_latency_seconds gauge', 'tito_request_latency_seconds{homeAddress="' . $home_addr_no_space . '",workAddress="' . $work_addr_no_space . '"} ' . $time_diff]) . "\n";

$prometheusUrl = getenv('TITO_PROMETHEUS_PUSHGATEWAY');
$cURLConnection = curl_init($prometheusUrl);
curl_setopt($cURLConnection, CURLOPT_POST, 1);
curl_setopt($cURLConnection, CURLOPT_POSTFIELDS, $payload);
curl_setopt($cURLConnection, CURLOPT_RETURNTRANSFER, true);
curl_setopt($cURLConnection, CURLOPT_HTTPHEADER, array('Content-Type: text/plain')); 

$apiResponse = curl_exec($cURLConnection);
curl_close($cURLConnection);

$logLine = "Info: Send metrics to Prometheus finished. API Response: " . $apiResponse;
error_log(print_r($logLine, TRUE));


//------------------------------------------------------------------------------

/**
 *
 * @param type $params
 * @throws \Exception
 */
function getTrafficData($params) {
    $result = array('total'=>0);
    $days = array("monday", "tuesday", "wednesday", "thursday", "friday");

    foreach ($days as $day) {
        $result[$day] = array("home_to_work" => array("time" => null, 'range_less'=>[], 'range_more'=>[]), "work_to_home" => array("time" => null));

        $home_time = strtotime("next " . $day . "+" . substr($params['home_time'], 0, 2) . "hours +" . substr($params['home_time'], 3, 2) . "minutes");
        $work_time = strtotime("next " . $day . "+" . substr($params['work_time'], 0, 2) . "hours +" . substr($params['work_time'], 3, 2) . "minutes");

        $array_times = [];
        // Home to work
        $home_response = callGoogleApi($params['home_addr'], $params['work_addr'], $home_time);
        if ($home_response->status !== "OK") {
            echo "Google error response status (home): " . $home_response->status;
            throw new \Exception("Google error response status: " . $home_response->status, 502);
        }
        $result[$day]['home_to_work']['start'] = $home_time;
        $result[$day]['home_to_work']['time'] = $home_response->routes[0]->legs[0]->duration_in_traffic->value;

        for($i=(-1*$params['home_range']); $i<0;$i+=600){
            $time_tmp = $home_time + $i;
            $home_response = callGoogleApi($params['home_addr'], $params['work_addr'], $time_tmp);
            if ($home_response->status !== "OK") {
                echo "Google error response status (range): " . $home_response->status;
                throw new \Exception("Google error response status (range): " . $home_response->status, 502);
            }
            $result[$day]['home_to_work']['range_less'][$time_tmp.""] = [$home_response->routes[0]->legs[0]->duration_in_traffic->value, false, false];
            $array_times[$time_tmp] = $home_response->routes[0]->legs[0]->duration_in_traffic->value;
        }
        for($i=600; $i<=($params['home_range']);$i+=600){
            $time_tmp = $home_time + $i;
            $home_response = callGoogleApi($params['home_addr'], $params['work_addr'], $time_tmp);
            if ($home_response->status !== "OK") {
                echo "Google error response status (range): " . $home_response->status;
                throw new \Exception("Google error response status (range): " . $home_response->status, 502);
            }
            $result[$day]['home_to_work']['range_more'][$time_tmp.""] = [$home_response->routes[0]->legs[0]->duration_in_traffic->value, false, false];
            $array_times[$time_tmp] = $home_response->routes[0]->legs[0]->duration_in_traffic->value;
        }

        defineMinAndMax($result[$day]['home_to_work'], $array_times);

        // Work to home
        $work_response = callGoogleApi($params['work_addr'], $params['home_addr'], $work_time);
        if ($work_response->status !== "OK") {
            echo "Google error response status (work): " . $work_response->status;
            throw new \Exception("Google error response status (work): " . $work_response->status, 502);
        }
        $result[$day]['work_to_home']['start'] = $work_time;
        $result[$day]['work_to_home']['time'] = $work_response->routes[0]->legs[0]->duration_in_traffic->value;

        $result[$day]['total'] = $result[$day]['work_to_home']['time'] + $result[$day]['home_to_work']['time'];
        $result['total'] += $result[$day]['total'];
    }
    return $result;
}

function defineMinAndMax(&$result, $array_times){
    $min_time = $result['time'];
    $max_time = null;
    $min_hour = null;
    $max_hour = null;

    foreach($array_times as $hour => $time){
        if($time < $min_time){
            $min_hour = $hour;
            $min_time = $time;
        }
        if($time > $max_time){
            $max_hour = $hour;
            $max_time = $time;
        }
    }
    $result['min'] = false;
    if (isset($result['range_less'][$min_hour])){
        $result['range_less'][$min_hour][1] = true;
    } else if (isset($result['range_more'][$min_hour])){
        $result['range_more'][$min_hour][1] = true;
    } else{
        $result['min'] = true;
    }

    $result['max'] = false;
    if (isset($result['range_less'][$max_hour])){
        $result['range_less'][$max_hour][2] = true;
    } else if (isset($result['range_more'][$max_hour])){
        $result['range_more'][$max_hour][2] = true;
    } else{
        $result['max'] = true;
    }
}

/**
 * Call the Google API
 *
 * @param type $origin
 * @param type $dest
 * @param type $time
 * @return type
 */
function callGoogleApi($origin, $dest, $time) {

#retrieving variables
global $metric_name;
global $tag_name;
global $tag_value;

#fetch data from cache
    $dataKey = trim(strtolower($origin)) . " - " . trim(strtolower($dest)) . " - " . trim(strtolower($time));
    $dataKey = preg_replace('/\s+/', '', $dataKey);
    $dataKey = preg_replace('/[\x00-\x1F\x7F]/', '', $dataKey);
    $serverIp = getenv('TITO_MEMCACHED_HOST');
    $serverPort = 11211;
    $memcached = null;

    if (!empty($serverIp)) {
        $memcached = new Memcached();
        $memcached->setOption(Memcached::OPT_CLIENT_MODE, Memcached::DYNAMIC_CLIENT_MODE);
        $logLine = "Info: Connecting to Memcached server " . $serverIp . ":" . $serverPort . "";
        error_log(print_r($logLine, TRUE));
        $result = $memcached->addServer($serverIp, $serverPort) or die ("Could not connect");
        if (!$result) {
            $logLine = "Error: Could not add memcached server " . $serverIp . ":" . $serverPort . "";
            error_log(print_r($logLine, TRUE));
        }

        $response = $memcached->get($dataKey);
        if ($response) {
            $logLine = "Info: Fetched existing data from cache with key " . $dataKey;
            error_log(print_r($logLine, TRUE));
            return json_decode($response);
        } else {
            $logLine = "Info: Could not find cache with key " . $dataKey;
            error_log(print_r($logLine, TRUE));
        }
    } else {
        $logLine = "Error: No memcached server specified. Skipping memcached integration.";
        error_log(print_r($logLine, TRUE));
    }

    $url = "https://maps.googleapis.com/maps/api/directions/json?origin=" . str_replace(' ', '%20', $origin) . "&destination=" . str_replace(' ', '%20', $dest) . "&departure_time=" . $time . "&traffic_model=pessimistic&key=" . GOOGLE_API_KEY;

#to monitor the google maps call duration_in_traffic
    $time1=microtime(true);

#Google call
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_PROXYPORT, 3128);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    $response = curl_exec($ch);
    curl_close($ch);

#to monitor the google maps call duration_in_traffic
    $time2=microtime(TRUE);
    #wavefront(gethostname(), $metric_name,$time2-$time1,$time2, $tag_name, $tag_value);

    $home_response = json_decode($response);

    if (!empty($serverIp)) {
        #write data to cache if response was valid
        if ($home_response->status === "OK") {
            $logLine = "Info: Write data into cache with key " . $dataKey;
            error_log(print_r($logLine, TRUE));
            $memcached->set($dataKey, $response);
        } else {
            $logLine = "Info: Error in response, not writing data into cache with key " . $dataKey;
            error_log(print_r($logLine, TRUE));
        }
    }

    return $home_response;
}

/**
 * Get an array of parameters from the URL
 * If a needed parameter is missing, throw an exception
 *
 * @param array $needed_params
 * @return array
 * @throws \Exception
 */
function extractParametersFromUrl(array $needed_params = array()) {
    if (empty($_GET['home_addr'])) {
       //$LogLine = "_GET EST NULL;TITO-App requested from UI";
       $LogLine = "Appel de TITO via POST;TITO-App requested from UI";
       error_log(print_r($LogLine, TRUE));
       $params = $_POST;
    } else {
       //$LogLine = "_POST EST NULL;TITO-App requested from URL";
       $LogLine = "Appel de TITO via GET;TITO-App requested from URL";
       error_log(print_r($LogLine, TRUE));
       $params = $_GET;
    }

    // Adding LOG
    $home_for_log = $params['home_addr'];
    $work_for_log = $params['work_addr'];
    $LogLine = "TITO-App;home=$home_for_log;work=$work_for_log;";
    error_log(print_r($LogLine, TRUE));

    $result = array();
    foreach ($needed_params as $param_name) {
        if (!isset($params[$param_name])) {
            echo "Missing parameter '" . $param_name . "' for this API function.";
            throw new \Exception("Missing parameter '" . $param_name . "' for this API function.", 501);
        }
        $result[$param_name] = $params[$param_name];
    }
    return $result;
}

#this function send data to Wavefront to showcase Wavefront ability to ingest metric application
function wavefront($source_name,$metric_name,$metric_value,$metric_epoch,$tag_name,$tag_value) 
{
   #retrieve wavefront proxy details
   $wf_proxy_name=getenv('PROXY_NAME');
   $wf_proxy_port=getenv('PROXY_PORT');

   #exit if no proxy_name entered
   if (empty($wf_proxy_name) || empty($wf_proxy_port)) {
      error_log("wavefront - error : wavefront parameter missing, please check PROXY_NAME and PROXY_PORT configuration");
      return;
    }

   #error_log("wavefront - info : Socket creation : starting...");
   $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
   if ($socket === false) {
      error_log("wavefront - error : socket_create() failed: reason: " . socket_strerror(socket_last_error()) . "\n");
   }
   else {
      #error_log("wavefront - info : Socket creation : Successful");
      socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 2, 'usec' => 0));   # 2 sec Timeout
      socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 2, 'usec' => 0));   # 2 sec Timeout
      error_log("wavefront - info : Attempting to connect to '$wf_proxy_name' on port '$wf_proxy_port'...");
      $result = socket_connect($socket, $wf_proxy_name, $wf_proxy_port);
      if ($result === false) {
         error_log("wavefront - error : socket_connect() failed. Reason: ($result) " . socket_strerror(socket_last_error($socket)));
      }
      else {
         $data_point = "$metric_name $metric_value $metric_epoch source=$source_name  $tag_name=$tag_value\n";     
         # '\n' a la fin du data_point est indispensable dans le data format de wavefront
         error_log("wavefront - info : Sending Wavefront Data point: $data_point");
         socket_write($socket, $data_point, strlen($data_point));
      }

      #error_log("wavefront - info : Closing socket...");
      socket_close($socket);
   }
}
