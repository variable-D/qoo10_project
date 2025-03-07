<?php
date_default_timezone_set("Asia/Seoul");
log_write(date("Y-m-d H:i:s"), '크론탭 실행 시작', 'sk_qoo10_cron_log.txt');

require "/var/www/html8443/mallapi/db_info.php";
$db_conn = mysqli_connect($db_host, $db_user, $db_pwd, $db_category, $db_port);
if (mysqli_connect_errno()) {
    die("DB 연결 실패: " . mysqli_connect_error());
}

include_once "/var/www/html/mobile_app/mgr/phpbarcode/src/BarcodeGeneratorPNG.php";
include_once "/var/www/html/mobile_app/mgr/phpqrcode/qrlib.php";

$start_date = date("Ymd", strtotime("-3 days")); // 현재 날짜 부터 3일전
$end_date = date("Ymd");// 현재 날짜

/************************************************************************
 *  qoo10 API 이용해서 데이터 가져오기
 ************************************************************************/


// ✅ Qoo10 API 이용해서 POST 요청을 통해서 데이터 가져오기
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.qoo10.jp/GMKT.INC.Front.QAPIService/ebayjapan.qapi");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/x-www-form-urlencoded",
    "Accept: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    "v" => "1.0",
    "returnType" => "json",
    "method" => "ShippingBasic.GetShippingInfo_v2",
    "key" => "S5bnbfynQvNaWXYBDkJxpTovGg2tnREpIMm4cFltC3uAwdlXcCoO8o8z_g_2_e1mmaLnWe_g_1_8niXMXEZOYHLGAOnH54dMfJdsNtttf9XRlSGRehs91qzM_g_1_ja51Q_g_3__g_3_",
    "ShippingStat" => "2",
    "search_Sdate" => $start_date,
    "search_Edate" => $end_date,
    "search_condition" => "2"
]));


$res = curl_exec($ch);
curl_close($ch);
$qoo10_data = json_decode($res, true);


// ✅ 조회된 데이터 없을 시 종료
if (empty($qoo10_data['ResultObject'])) {
    die();
}

// ✅ Qoo10 API 오류 시 종료
if (!$qoo10_data || $qoo10_data["ResultCode"] != 0) {
    die();
}

$processed_orders = [];
$query = "SELECT order_id FROM qoo10_response_order_id_esim_tb WHERE ymd BETWEEN '$start_date' AND '$end_date'";
$result = mysqli_query($db_conn, $query);

while ($row = mysqli_fetch_assoc($result)) {
    $processed_orders[] = $row['order_id'];
}

// ✅ 새로운 배열에 이미 처리된 주문은 포함시키지 않음
$filtered_orders = [];
foreach ($qoo10_data['ResultObject'] as $order) {
    if (!in_array($order['orderNo'], $processed_orders)) {
        $filtered_orders[] = $order;
    }
}

// ✅ 기존 데이터 대체 (처리되지 않은 주문만 남김)
$qoo10_data['ResultObject'] = $filtered_orders;

// ✅ 모든 주문이 이미 처리되었으면 종료
if (empty($qoo10_data['ResultObject'])) {
    die();
}

// ✅ 처리된 주문을 `qoo10_response_order_id_esim_tb` 테이블에 기록
foreach ($qoo10_data['ResultObject'] as $order) {
    $order_id = $order['orderNo'];
    $insert_query = "INSERT INTO qoo10_response_order_id_esim_tb (ymd, order_id) VALUES ('$end_date', '$order_id')";
    log_write(date("Y-m-d H:i:s"), $qoo10_data, 'qoo10_esim_api_return_log.txt');
    mysqli_query($db_conn, $insert_query);
}



/************************************************************************
 * qoo10 API에서 가져온 데이터를 반복 실행 (주문 수량만큼) sk API 요청
 * 1. API6 요청 (가용 단말 수 확인)
 * 2. 가용 단말 수가 없으면 API7 요청 중단
 * 3. API7 요청
 * 4. API7 성공 후 이메일 전송
 ************************************************************************/

// ✅ Qoo10 API에서 가져온 데이터 (`$qoo10_data`)를 반복 실행

foreach ($qoo10_data['ResultObject'] as $order) {
    $order_id = (string) $order['orderNo'];  // 주문번호를 문자열로 변환
    $order_qty = intval($order["orderQty"]); // 수량 가져오기

    // ✅ 주문번호로 시작하는 order_item_code 생성
    for ($i = 0; $i < $order_qty; $i++) {
        $order_item_code = get_order_item_code($db_conn, $order_id);
        $buyer_name = $order['buyer']; // ✅ 구매자명
        $buyer_email = $order['buyerEmail']; // ✅ 이메일
        $payment_date = str_replace(['-', ' ', ':'], '', $order['PaymentDate']); // ✅ "YYYYMMDDHHMMSS" 형식으로 변환
        $shop_no = 15; // ✅ 쇼핑몰 번호
        $sk_api_url = "https://223.62.242.91/api/swinghub"; // ✅ API URL
        // 고정값
        $sk_api7_type = 'api7'; // API7 타입
        $company = '프리피아'; // 회사명
        $sk_api6_type = 'api6'; // API6 타입
        $roming_typ_cd = '16'; // 로밍유형코드
        $post_sale_org_id = 'V992470000'; //소속영업조직ID
        $dom_cntc_num = '0000'; //국내연락전화번호
        $nation_cd = 'GHA'; //국적코드
        $rcmndr_id = '1313033433'; // 추천인ID
        $total_cnt = '1'; //TOTAL_CNT
        $email = "cs@prepia.co.kr";
        $itemNo = isset($order['itemCode']) ? $order['itemCode'] : null;

        $product_code = "";
        $esimDays = "";

        switch ($itemNo) {
            case "1136977068":
                $product_code = "NA00007679";
                $esimDays = "1";
                break;
            case "1138153829":
                $product_code = "NA00008813";
                $esimDays = "2";
                break;
            case "1136977481":
                $product_code = "NA00008249";
                $esimDays = "3";
                break;
            case "1138287236":
                $product_code = "NA00008814";
                $esimDays = "4";
                break;
            case "1136977566":
                $product_code = "NA00008250";
                $esimDays = "5";
                break;
            case "1138287953":
                $product_code = "NA00008815";
                $esimDays = "6";
                break;
            case "1136977992":
                $product_code = "NA00008777";
                $esimDays = "7";
                break;
            case "1138288000":
                $product_code = "NA00008816";
                $esimDays = "8";
                break;
            case "1138288114":
                $product_code = "NA00008817";
                $esimDays = "9";
                break;
            case "1136978980":
                $product_code = "NA00008251";
                $esimDays = "10";
                break;
            case "1136979476":
                $product_code = "NA00008778";
                $esimDays = "15";
                break;
            case "1136980105":
                $product_code = "NA00008252";
                $esimDays = "20";
                break;
            case "1136982071":
                $product_code = "NA00008253";
                $esimDays = "30";
                break;
            case "1136982235":
                $product_code = "NA00008779";
                $esimDays = "60";
                break;
            case "1136982869":
                $product_code = "NA00008780";
                $esimDays = "90";
                break;
            default:
                continue;  // 매칭되지 않으면 다음 주문으로 넘어감
        }


        $esimEmailOK = 0; //이메일 양식 확인

        if (filter_var($buyer_email, FILTER_VALIDATE_EMAIL)) {
            $esimEmailOK = 1; //이메일 양식 확인
        }

        $sk_api6_data = json_encode([
            "company" => $company,
            "apiType" => $sk_api6_type,
            "roming_typ_cd" => $roming_typ_cd,
            "post_sale_org_id" => $post_sale_org_id,
            "rental_fee_prod_id" => $product_code
        ], JSON_UNESCAPED_UNICODE);



        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $sk_api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json' ) );
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $sk_api6_data);

        $sk_api6_res = curl_exec($ch);
        curl_close($ch);
        $sk_api6_response = json_decode($sk_api6_res, true);

        if (!$sk_api6_response || !isset($sk_api6_response["RSV_EQP_CNT"])) {
            log_write(date("Y-m-d H:i:s"), "❌ API6 응답 오류 발생: " . json_encode($sk_api6_response), 'sk_qoo10_esim_api6_error_log.txt');
            die("API6 응답 오류 발생");
        }

        $rsv_eqp_cnt = intval($sk_api6_response["RSV_EQP_CNT"]);
        // ✅ API6 요청 데이터 및 응답 로그 저장
        log_write2(json_encode($sk_api6_data, JSON_UNESCAPED_UNICODE), 'sk_qoo10_esim_api6_param.txt');
        log_write($order_id, $sk_api6_response, 'sk_qoo10_esim_api6_return_log.txt');



        $sk_api7_data = json_encode([
            "company" => $company,
            "apiType" => $sk_api7_type,
            "RENTAL_SCHD_STA_DTM" => $end_date,
            "RENTAL_SCHD_END_DTM" => $end_date,
            "RENTAL_SALE_ORG_ID" => $post_sale_org_id,
            "DOM_CNTC_NUM" => $dom_cntc_num,
            "EMAIL_ADDR" => $email,
            "RSV_RCV_DTM" => $payment_date,
            "ROMING_PASSPORT_NUM" => $order_item_code,
            "CUST_NM" => $buyer_name,
            "NATION_CD" => $nation_cd,
            "RCMNDR_ID" => $rcmndr_id,
            "TOTAL_CNT" => $total_cnt,
            "IN1" => [
                [
                    "RSV_VOU_NUM" => $order_item_code,
                    "ROMING_TYP_CD" => $roming_typ_cd,
                    "RENTAL_FEE_PROD_ID" => $product_code
                ]
            ]
        ], JSON_UNESCAPED_UNICODE);



        log_write2(json_encode($sk_api6_data, JSON_UNESCAPED_UNICODE), 'sk_qoo10_esim_api6_param.txt');
        log_write2(json_encode($sk_api7_data, JSON_UNESCAPED_UNICODE), 'sk_qoo10_esim_api7_param.txt');


        // SK API 전송 데이터
        $query = "INSERT INTO sk_api_send (order_item_code, rental_schd_sta_dtm, rental_schd_end_dtm, rental_sale_org_id, dom_cntc_num, email_addr, rsv_rcv_dtm, roming_passport_num, cust_nm, nation_cd, rcmndr_id, total_cnt, roming_typ_cd, rental_fee_prod_id) 
						VALUES ('$order_item_code','$end_date','$end_date','$post_sale_org_id','$dom_cntc_num','$email','$payment_date','$order_id','$buyer_name','$nation_cd','$rcmndr_id','$total_cnt','$roming_typ_cd','$product_code')";

        $result = mysqli_query($db_conn, $query);
        if (!$result) {
            echo "[DB ERROR] $query \n" . mysqli_error($db_conn) . " \n";
        }


        if ($rsv_eqp_cnt > 1) {

            // ✅ API7 요청
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $sk_api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json' ) );
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $sk_api7_data);

            $apiDTS = date("Y-m-d H:i:s");
            $sk_api7_res = curl_exec($ch);
            if ($sk_api7_res) {
                $apiDTR = date("Y-m-d H:i:s");
            }
            curl_close($ch);
            $sk_api7_response = json_decode($sk_api7_res, true);

// ✅ API7 응답 확인 및 오류 처리
            if (!$sk_api7_response || !isset($sk_api7_response['OUT1'])) {
                log_write($order_id, $sk_api7_res, 'sk_qoo10_esim_api7_error_log.txt');
                die("API7 응답 오류 발생");
            }

            $order_ymd = date("Ymd");
            // API 리턴값 오류 확인
            if (!$sk_api7_response['OUT1']) {
                $emailState = 0;
                $note = $res; //오류 메세지
                $query = "INSERT INTO tb_esim_order_item_sk_red (order_id, order_item_code, shop, send_api_time, return_api_time, buy_user_name, passport, esim_day, email_state, buy_user_email, note, order_ymd) 
								VALUES ('$order_id','$order_item_code','$shop_no','$apiDTS','$apiDTR','$buyer_name','$order_item_code','$esimDays',$emailState,'$buyer_email','$note',$order_ymd)";

                $result = mysqli_query($db_conn, $query);
                if (!$result) {
                    echo "[DB ERROR] $query \n" . mysqli_error($db_conn) . " \n";
                }
                die;
            }

            $api7_return_data = $sk_api7_response['OUT1'];

            $rental_mst_num = $api7_return_data[0]['RENTAL_MST_NUM'];
            $eqp_mdl_cd = $api7_return_data[0]['EQP_MDL_CD'];
            $esim_mapping_id = $api7_return_data[0]['ESIM_MAPPING_ID'];
            $eqp_ser_num = $api7_return_data[0]['EQP_SER_NUM'];
            $roming_phon_num = $api7_return_data[0]['ROMING_PHON_NUM'];
            $roming_num = $api7_return_data[0]['ROMING_NUM'];

            $total_cnt = $sk_api7_response['TOTAL_CNT'];
            $rental_mgmt_num = $sk_api7_response['RENTAL_MGMT_NUM'];


            // 리턴 데이터
            $query = "INSERT INTO sk_api_return (order_item_code, rental_mst_num, eqp_mdl_cd, esim_mapping_id, eqp_ser_num, roming_phon_num, roming_num, total_cnt, rental_mgmt_num) 
							VALUES ('$order_item_code','$rental_mst_num','$eqp_mdl_cd','$esim_mapping_id','$eqp_ser_num','$roming_phon_num','$roming_num','$total_cnt','$rental_mgmt_num')";

            $result = mysqli_query($db_conn, $query);
            if (!$result) {
                echo "[DB ERROR] $query \n" . mysqli_error($db_conn) . " \n";
            }


            $qr_arr = explode("$", $esim_mapping_id);
            $smdp = $qr_arr[0] . '$' . $qr_arr[1];
            $varQRCodePath = $qr_arr[2];

            /************************
             * 이메일 발송
             *************************/

            if ($esimEmailOK == 1) { // 이메일 양식이 맞을 경우

                if ($varQRCodePath) {

                    ob_start();
                    QRcode::png($esim_mapping_id, "/var/www/html/mobile_app/api/sk_qrcode/$varQRCodePath.png", "L", 12, 2);
                    ob_end_clean();
                    $varQRCodeImg = "https://www.koreaesim.com/mobile_app/api/sk_qrcode/$varQRCodePath.png";

                    // eSIM 이용 기간 1일이거나 국문몰($shop_no==1)일 경우 번호 제공되지 않음
                    /**** 번호 표시 수정
                     *
                     * if($esimDays == 1){
                     * $varCtn = 'Not Provided';
                     * }else{
                     * $varCtn = $roming_phon_num;
                     * }
                     ****/
                    $query = "SELECT * FROM sk_api_return WHERE order_item_code = '$order_item_code'";
                    $result = mysqli_query($db_conn, $query);

                    if (!$result) {
                        echo "[DB ERROR] $query<br>" . mysqli_error($db_conn);
                    } else {
                        if (mysqli_num_rows($result) > 0 && $esimDays !== "1") {
                            // Fetch the data
                            $row = mysqli_fetch_assoc($result);
                            $rentalMgmtNum = isset($row['rental_mgmt_num']) ? $row['rental_mgmt_num'] : '';
                            $varCtn = isset($roming_phon_num) ? $roming_phon_num : ''; // ctn 값이 없을 경우 기본값 설정
                        } else {
                            $rentalMgmtNum = ''; // 기본값
                            $varCtn = ''; // 기본값
                        }
                    }



                    include "/var/www/html/mobile_app/mgr/email_contents/email_contents_sk_jp.php";



                    $result = mail($buyer_email, $email_subject, $email_contents, $email_headers);

                    $emailDTS = date("Y-m-d H:i:s");


                    if ($result) {
                        //메일 전송 성공
                        $emailState = 2;
                        $query = "INSERT INTO tb_esim_order_item_sk_red (order_id, order_item_code, shop, send_api_time, return_api_time, buy_user_name, passport, esim_day, email_state, send_email_time, buy_user_email, payment_date, ctn, qr_code, smdp_address, activation_code, order_ymd) 
                    VALUES ('$order_id','$order_item_code','$shop_no','$apiDTS','$apiDTR','$buyer_name','$order_item_code','$esimDays',$emailState,'$emailDTS','$buyer_email','$payment_date','$roming_phon_num','$esim_mapping_id','$smdp','$varQRCodePath', $order_ymd)";

                    } else {
                        // 메일 전송 실패
                        $emailState = 0;
                        $query = "INSERT INTO tb_esim_order_item_sk_red (order_id, order_item_code, shop, send_api_time, return_api_time, buy_user_name, passport, esim_day, email_state, send_email_time, buy_user_email, payment_date, ctn, qr_code, smdp_address, activation_code, order_ymd) 
                    VALUES ('$order_id','$order_item_code','$shop_no','$apiDTS','$apiDTR','$buyer_name','$order_item_code','$esimDays',$emailState,'$emailDTS','$buyer_email','$payment_date','$roming_phon_num','$esim_mapping_id','$smdp','$varQRCodePath', $order_ymd)";

                    }

                    $result = mysqli_query($db_conn, $query);
                    if (!$result) {
                        echo "[DB ERROR] $query \n" . mysqli_error($db_conn) . " \n";
                    }


                } //if($varQRCodePath)

            } else { // 이메일 양식이 틀릴 경우

                $emailState = 0;
                $note = "이메일 양식 오류";
                $query = "INSERT INTO tb_esim_order_item_sk_red (order_id, order_item_code, shop, send_api_time, return_api_time, buy_user_name, passport, esim_day, email_state, buy_user_email, payment_date, note, ctn, qr_code, smdp_address, activation_code, order_ymd) 
            VALUES ('$order_id','$order_item_code','$shop_no','$apiDTS','$apiDTR','$buyer_name','$order_item_code','$esimDays',$emailState,'$buyer_email','$payment_date','$note','$roming_phon_num','$esim_mapping_id','$smdp','$varQRCodePath', $order_ymd)";

                $result = mysqli_query($db_conn, $query);
                if (!$result) {
                    echo "[DB ERROR] $query \n" . mysqli_error($db_conn) . " \n";
                }

            }
        } else {  //if($resData['RSV_EQP_CNT'] < 1) 단말기 가용 수량이 부족 할 때

            $emailState = 0;
            $note = $res; //오류 메세지
            $query = "INSERT INTO tb_esim_order_item_sk_red (order_id, order_item_code, shop, send_api_time, return_api_time, buy_user_name, passport, esim_day, email_state, buy_user_email, note, order_ymd) 
        VALUES ('$order_id','$order_item_code','$shop_no','$apiDTS','$apiDTR','$buyer_name','$order_item_code','$esimDays',$emailState,'$buyer_email','$note',$order_ymd)";

            $result = mysqli_query($db_conn, $query);
            if (!$result) {
                echo "[DB ERROR] $query \n" . mysqli_error($db_conn) . " \n";
            }
        } //if($resData['RSV_EQP_CNT'] > 1)

        log_write(date("Y-m-d H:i:s"), '크론탭 실행 종료', 'sk_qoo10_cron_log.txt');
    }

}


function log_write($order_item_code, $log_data, $file_name) {
    $log_dir = '/var/www/html8443/mallapi/qoo10/logs/';
    $log_txt = "\r\n(" . date("Y-m-d H:i:s") . ") [" . $order_item_code . "]\r\n" . json_encode($log_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($log_dir . $file_name, $log_txt . "\r\n\r\n", FILE_APPEND);
}


function log_write2($log_data, $file_name)
{

    //디렉토리 경로
    $log_dir = '/var/www/html8443/mallapi/qoo10/logs/';

    $log_txt = "\r\n";
    $log_txt .= '(' . date("Y-m-d H:i:s") . ')' . "\r\n";
    $log_txt .= $log_data;

    $log_file = fopen($log_dir . $file_name, 'a');
    fwrite($log_file, $log_txt . "\r\n\r\n");
    fclose($log_file);

}
function get_order_item_code($db_conn, $order_id) {
    // 기존 데이터 조회 (해당 order_id로 시작하는 order_item_code 중 가장 큰 값 조회)
    $query = "SELECT order_item_code FROM tb_esim_order_item_sk_red 
              WHERE order_item_code LIKE ? 
              ORDER BY order_item_code DESC LIMIT 1";

    $stmt = mysqli_prepare($db_conn, $query);
    if (!$stmt) {
        die("[DB ERROR] " . mysqli_error($db_conn));
    }

    $search_pattern = $order_id . "-%"; // order_id로 시작하는 order_item_code 찾기
    mysqli_stmt_bind_param($stmt, "s", $search_pattern);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    mysqli_stmt_bind_result($stmt, $order_item_code);

    if (mysqli_stmt_fetch($stmt)) {
        mysqli_stmt_close($stmt);

        // 기존 order_item_code에서 숫자 부분 추출
        $order_item_code_arr = explode("-", $order_item_code);
        $last_num = intval($order_item_code_arr[1]) + 1; // 숫자를 1 증가

        // 10 미만이면 "01", "02" 형식 유지
        return sprintf("%s-%02d", $order_id, $last_num);
    } else {
        mysqli_stmt_close($stmt);
        return $order_id . "-01"; // 기존 데이터가 없으면 "order_id-01" 반환
    }
}


?>

