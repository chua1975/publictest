<?php 

add_action("init", "ST_InitPageWriteRule");
function ST_InitPageWriteRule() {
  add_rewrite_rule("liquidpay$", "index.php?liquidpay-webhook=1&step=1", "top");
}


add_filter("query_vars", "ST_InitPageQueryVars");
function ST_InitPageQueryVars($query_vars) {
  $query_vars[] = "liquidpay-webhook";
  return $query_vars;
}


add_action("parse_request", "ST_InitPageParseRequest");

function ST_InitPageParseRequest(&$wp) {
  if ( array_key_exists( "liquidpay-webhook", $wp->query_vars ) ) {
    
    $data = file_get_contents('php://input');
    
    $body = json_encode([ 
        "date" => date('m/d/Y h:i:s a', time()),
        "body" => $data
    ] );
    
    $log_file = plugin_dir_path( __FILE__ ) . "../logs/logs.log";
    
    if (is_writable($log_file)) {
        file_put_contents($log_file, $body .  "\n" , FILE_APPEND );
        print_r("file is writable");
    } else {
        $log_file = fopen( $log_file, "w") or die("Unable to open file!");
        fwrite($log_file, $body . "\n");
        print_r("file is not writable");
    }

    if ($data) {
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,"http://sas.dewatasoft.com/wc-api/liquidpay-webhook");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $server_output = curl_exec($ch);

        curl_close ($ch);
        print_r($server_output); 
        
    } else {
        print "webhook";
    }
        exit();
    }
  
    return;
}