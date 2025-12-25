<?php
require_once 'simple_html_dom.php';

/* =========================
   INPUT FORM
========================= */
$author  = trim($_POST['penulis'] ?? '');
$keyword = trim($_POST['keyword'] ?? '');
$limit   = (int)($_POST['jumlahData']);

if ($author === '' && $keyword === '') {
    die("Penulis atau keyword harus diisi");
}

#region crawl 
//crawl pada search
$query = trim($author . ' ' . $keyword);
$url = "https://scholar.google.com/scholar?q=" . urlencode($query);

$res = extract_html($url);

if ($res['code'] !== 200) {
    die("Gagal mengakses Google Scholar");
}
//debug bloack 
file_put_contents('debug_scholar.html', $res['message']);
exit;

if($res['code'] == '200'){
    $html = new simple_html_dom();
    $html->load($res['message']);

    $i = 0;

    //ambil link profile penulis
    $profile_nodes = $html->find('h4.gs_rt2 a', 0);

    if (!$profile_nodes) {
        die("Profil penulis tidak ditemukan");
    }

    $profile_url = "https://scholar.google.com" . $profile_nodes->href;
    $profile_result = extract_html($profile_url);

    if ($profile_result['code'] !== 200) {
        die("Gagal membuka halaman profil");
    }

    $profile_dom = new simple_html_dom();
    $profile_dom->load($profile_result['message']);

    //crawled data - LOOP UTAMA
    foreach ($profile_dom->find('tr.gsc_a_tr') as $row) {
        if ($i >= $limit) break;

        $title = trim($row->find('a.gsc_a_at', 0)->plaintext ?? '');
        $cited = trim($row->find('a.gsc_a_ac', 0)->plaintext ?? '0');

        echo "<b>Judul</b>: {$title}<br>";
        echo "<b>Sitasi</b>: {$cited}<br>";

        // Ambil URL detail untuk artikel INI
        $titleNode = $row->find('a.gsc_a_at', 0);
        if ($titleNode) {
            $detail_url = 'https://scholar.google.com' . html_entity_decode($titleNode->href);
            
            sleep(4); //anti block
            
            // Ambil detail artikel
            $detail_res = extract_html($detail_url);
            
            if ($detail_res['code'] === 200) {
                $detail_dom = new simple_html_dom();
                $detail_dom->load($detail_res['message']);
                
                foreach ($detail_dom->find('div.gs_scl') as $detail_row) {
                    $label = trim($detail_row->find('div.gsc_oci_field', 0)->plaintext ?? '');
                    $value = trim($detail_row->find('div.gsc_oci_value', 0)->plaintext ?? '');
                    
                    if ($label == 'Authors') {
                        echo "<b>Penulis</b>: {$value}<br>";
                    }
                    if ($label == 'Publication date') {
                        echo "<b>Tanggal Terbit</b>: {$value}<br>";
                    }
                    if ($label == 'Journal') {
                        echo "<b>Jurnal</b>: {$value}<br>";
                    }
                    sleep(1); // Kurangi delay jika perlu
                }
            }
        }
        
        echo "<hr>";
        $i++;
        sleep(5); //anti block
    }
    $html->clear();
    unset($html);
}
#endregion





#region function
function extract_html($url) {

		$response = array();

		$response['code']='';

		$response['message']='';

		$response['status']=false;	

		$agent = 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.1) Gecko/20061204 Firefox/2.0.0.1';

		// Some websites require referrer

		$host = parse_url($url, PHP_URL_HOST);

		$scheme = parse_url($url, PHP_URL_SCHEME);

		$referrer = $scheme . '://' . $host; 

		$curl = curl_init();

		curl_setopt($curl, CURLOPT_HEADER, false);

		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

		curl_setopt($curl, CURLOPT_URL, $url);


		curl_setopt($curl, CURLOPT_USERAGENT, $agent);

		curl_setopt($curl, CURLOPT_REFERER, $referrer);

		curl_setopt($curl, CURLOPT_COOKIESESSION, 0);

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);

		// allow to crawl https webpages

		curl_setopt($curl,CURLOPT_SSL_VERIFYHOST,0);

		curl_setopt($curl,CURLOPT_SSL_VERIFYPEER,0);

		// the download speed must be at least 1 byte per second

		curl_setopt($curl,CURLOPT_LOW_SPEED_LIMIT, 1);

		// if the download speed is below 1 byte per second for more than 30 seconds curl will give up

		curl_setopt($curl,CURLOPT_LOW_SPEED_TIME, 30);

		$content = curl_exec($curl);

		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		$response['code'] = $code;

		if ($content === false) 

		{

			$response['status'] = false;

			$response['message'] = curl_error($curl);

		}

		else

		{

			$response['status'] = true;

			$response['message'] = $content;

		}

		curl_close($curl);

		return $response;

	}


// function isBotDetected($html, $httpCode) {

//     if ($httpCode != 200) {
//         return true;
//     }

//     $patterns = [
//         'unusual traffic',
//         'robot',
//         'detected',
//         'sorry',
//         'captcha'
//     ];

//     $htmlLower = strtolower($html);

//     foreach ($patterns as $p) {
//         if (strpos($htmlLower, $p) !== false) {
//             return true;
//         }
//     }

//     return false;
// }
#endregion
?>