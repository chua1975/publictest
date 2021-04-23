<?php 
class LiquidPay
{

    private $api_key = '';
    private $secret_key = '';
    private $payee = '';
    private $base_url = '';

    private $curl;
    private $faker;
    private $curency = 'SGD';
    private $nonce = '12835819715';

    function __construct($merchat_id , $api_key, $secret_key, $base_url = "https://sandbox.api.liquidpay.com/openapi")
    {
        ini_set( 'serialize_precision', -1 );
        $this->api_key = $api_key; 
        $this->secret_key = $secret_key; 
        $this->payee = $merchat_id; 
        $this->base_url = $base_url;
        $this->curency = get_woocommerce_currency();
    }

    private function _parseArgs(array $args, array $defaults = [])
    {
        return array_replace_recursive($defaults, $args);
    }
    
    private function curlAppendQuery($url, $query) {
		if (empty($query)) return $url;
		if (is_array($query)) return "$url?".http_build_query($query);
		else return "$url?$query";
    }
    
    public function call($method, $path, $params = array()) {
		$baseurl = $this->base_url;
        $url = $baseurl.trim($path);
		$query = in_array($method, array('GET','DELETE')) ? $params : array();
		$payload = in_array($method, array('POST','PUT')) ? json_encode($params) : array();
        $curl = curl_init();
        $url2 = $this->curlAppendQuery($url, $query);
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url2,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array(
                "content-type: application/json",
                "idempotency-key: ".uniqid($this->getRandomStr(5)),
                "liquid-api-key: ".$this->api_key),
        ));

        if ($method != 'GET' && !empty($payload))
		{
			if (is_array($payload)) $payload = http_build_query($payload);
			curl_setopt ($curl, CURLOPT_POSTFIELDS, $payload);
		}

        $response = curl_exec($curl);
        $err = curl_error($curl);
        return json_decode($response);
        
	}

    private function _createSign($data)
    {
        $data["SECRET"] = $this->secret_key;
        $sign = hash('sha512', http_build_query($data));
        return $sign;
    }

    function paymentType()
    {
        
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->base_url."/v1/bill/qr/payloadtypes"."?payee=".$this->payee,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => array(
                "liquid-api-key: ".$this->api_key),
        ));
        $out = curl_exec($curl);
        curl_close($curl);

        return json_decode($out);
    }

    function makeNonce($n) {
        $characters = '0123456789';
        $randomString = '';
  
        for ($i = 0; $i < $n; $i++) {
            $index = rand(0, strlen($characters) - 1);
            $randomString .= $characters[$index];
        }
  
        return $randomString;
    }

    function getRandomStr($n) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $randomString = '';
  
        for ($i = 0; $i < $n; $i++) {
            $index = rand(0, strlen($characters) - 1);
            $randomString .= $characters[$index];
        }
  
        return $randomString;
    }

    function crypt($str, $type = 'e'){
        return $str;
        //return ($type === 'e') ? base64_encode($str) : base64_decode($str);
    }

    function createBill(array $detail = [])
    {
        
        $request = '';
        $requestQry = '';
        $nonce = '';
        $signature = '';
       // = uniqid($this->getRandomStr(5));
        $ref = $detail["bill_ref_no"];
        $items = $this->generateItems($detail);

        $output = [];
        $walk = function( $item, $key, $parent_key = '' ) use ( &$output, &$walk ) {
            is_array( $item ) 
                ? array_walk( $item, $walk, $key ) 
                : $output[] = http_build_query( array( $parent_key ?: $key => $item ) );
        };
        array_walk( $items["items"] , $walk );

        $payloads = array (
            //'amount' => $items["total"],
            'amount' => $detail["amount"],
            'bill_ref_no' => $this->crypt($ref . "-" . $this->getRandomStr(5)),
            'currency_code' => $this->curency,
            'payee' => $this->payee,
            'payload_code' => (isset($detail["transaction_id"]) && !empty($detail["transaction_id"])) ? $detail["transaction_id"] : "LIQUID",
            'service_type' => 'PAE',
        );

        if(!empty($items["items"])){
            $payloads = array (
                //'amount' => $items["total"],
                'amount' => $detail["amount"],
                'bill_ref_no' => $this->crypt($ref . "-" . $this->getRandomStr(5)),
                'currency_code' => $this->curency,
                'items' => "",
                'payee' => $this->payee,
                'payload_code' => (isset($detail["transaction_id"]) && !empty($detail["transaction_id"])) ? $detail["transaction_id"] : "LIQUID",
                'service_type' => 'PAE',
            );

            $output = "";
            $testing = [];
            foreach ($items["items"] as $values) {
                ksort($values);
                foreach ($values as $key => $value) {
                    $output .= "&" . $key . "=" . urlencode($value);
                    $testing = array_merge($testing, [
                        "&" . $key . "=" . urlencode($value)
                    ]);
                }
            }

            // asort($testing);
            $output = implode("", $testing);
            ksort($payloads);

            $requestQry = http_build_query($payloads);
            $requestQry = str_replace("&items=", $output , $requestQry);
            $requestQry = strtoupper($requestQry);
        }
        
        $nonce = $this->makeNonce(10);
        $requestQry .='&NONCE='.$nonce.'&SECRET='.$this->secret_key;
        // $requestQry = urldecode($requestQry);
        $signature = strtoupper(hash('sha512', $requestQry));
        
        
        $payloads['items'] = $items["items"];
        // $payload =  array_merge($extra, $payload);
        ksort($payloads);
        $requestArrayWithSign = $payloads;
        $requestArrayWithSign['nonce'] = $nonce;
        $requestArrayWithSign['sign'] = $signature;

       

        $response = $this->call("POST","/v1/bill/consumer/scan", ($requestArrayWithSign));
        
        if($response->type == "error"){
            sleep(2);
            $response = $this->call("POST","/v1/bill/consumer/scan", ($requestArrayWithSign));
           // $response = $this->call("GET","/v1/bill/".$ref);
        }

        
        /*print "<pre>";
            print_r($requestQry);
            print_r($payloads);
            print_r($response);
            print(json_encode($requestArrayWithSign));
            print "<br/>". $signature;
        print "</pre>";*/
        
        return $response;

        
    }

    function findBill($str)
    {
        $response =  $this->call("GET","/v1/bill/$str");
        $bill_ref_no = $this->crypt($str, 'd');
        return $response;
    }

    
    function generateItems($items)
    {
        $data = [];
        $amount = 0;
        /*echo '<pre>';
        var_dump($items);
        echo '</pre>';*/

        /*foreach($items["shipping"] as $shipping_id => $shipping_item_obj){
            $item = [];
            $item = [
                'item_number' => $shipping_item_obj->get_method_id(),
                'item_name' => $shipping_item_obj->get_name(),
                'item_quantity' => 1,
                'item_unit_price' => $shipping_item_obj->get_total(),
            ];
            ksort($item);
            $data[]  = $item;
            $amount += $shipping_item_obj->get_total();
        }*/
        foreach ($items["items"] as $item_id => $item_data) {
            /*echo 'PID: ' . $item_data->get_product_id();
            echo '<br />SKU: ' . $item_data->get_sku();*/
            /*echo '<pre>';
            var_dump($item_data->get_product()->get_sku());
            echo '</pre>';*/
            
            $item = [];
            $item = [
                //'item_number' => (trim($item_data->get_id())),
                'item_number' => (trim(!empty($item_data->get_product()->get_sku()) ? $item_data->get_product()->get_sku() : $item_data->get_product_id())),
                'item_name' => $item_data->get_name(),
                'item_quantity' => ($item_data->get_quantity()),
                // 'item_unit_price' => (($item_data->get_total() / $item_data->get_quantity()))
                'item_unit_price' => $item_data->get_product()->get_price()
            ];
            ksort($item);
            $data[]  = $item;
            $amount += $item_data->get_total();
        }
        return [
            'items' => $data, 
            'total' => $amount
        ];
    }
    
}