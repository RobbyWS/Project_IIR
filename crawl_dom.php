<?php
require_once 'simple_html_dom.php';

/* =========================
   INPUT FORM
========================= */
$author  = trim($_POST['penulis'] ?? '');
$keyword = trim($_POST['keyword'] ?? '');
$limit   = (int)($_POST['jumlahData'] ?? 5);

if ($author === '' && $keyword === '') {
    die("Penulis atau keyword harus diisi");
}

/* =========================
   GOOGLE SCHOLAR SEARCH
========================= */
$query = trim($author . ' ' . $keyword);
$url = "https://scholar.google.com/scholar?q=" . urlencode($query);

$res = extract_html($url);

if ($res['code'] !== 200) {
    die("Gagal mengakses Google Scholar");
}
if (isBotDetected($res['message'], $res['code'])) {
    die(
        "<b>Google Scholar mendeteksi aktivitas bot.</b><br>
        Silakan tunggu beberapa menit atau ganti IP / User-Agent."
    );
}

$html = new simple_html_dom();
$html->load($res['message']);

$i = 0;

/* =========================
   PARSING HASIL SEARCH
========================= */
foreach ($html->find('.gs_r.gs_or.gs_scl') as $row) {

    if ($i >= $limit) break;

    // JUDUL & LINK
    $titleNode = $row->find('.gs_rt a', 0);
    if (!$titleNode) continue;

    $judul = trim($titleNode->plaintext);
    $link  = $titleNode->href;

     // SITASI
    $citeNode = $row->find('a[href*="cites"]', 0);
    $sitasi = $citeNode ? trim($citeNode->plaintext) : '0'; 

    //crawl view article
    foreach ($html->find('tr.gsc_a_tr') as $rows) {
        if ($i >= $limit) break;

        $a = $rows->find('a.gsc_a_at', 0);
        if (!$a) continue;

        $judul = trim($a->plaintext);
        $linkCitation = 'https://scholar.google.com' . $a->href;

        $viewArticle = extract_html($linkCitation);
        $dom = simple_html_dom();
        $dom->load($viewArticle['message']);

        foreach($dom->find('div[class="gs_scl"]') as $detailArticle)
        {
            
            $labelNode = $detailArticle->find('div.gsc_oci_field', 0);
            $valueNode = $detailArticle->find('div.gsc_oci_value', 0);

            if (!$labelNode || !$valueNode) continue;

            $label = strtolower(trim($labelNode->plaintext));
            $value = trim($valueNode->plaintext);

            if ($label === 'authors') 
            {
                echo "<b>Authors:</b> $value<br>";
            }
            else if ($label === 'journal') 
            {
                echo "<b>Nama Journal:</b> $value<br>";
            }
            else if ($label === 'publication date') 
            {
                echo "<b>Tanggal Publish:</b> $value<br>";
            }
    }

        echo "<b>Link Citation:</b> <a href='{$linkCitation}' target='_blank'>{$linkCitation}</a><br>";
        sleep(rand(6, 10));
    }

    echo "<b>Judul</b>: {$judul}<br>";
    echo "<b>Sitasi</b>: {$sitasi}<br>";
    echo "<b>Link</b>: {$link}<br><hr>";

    $i++;
    sleep(rand(3,7));
}

    

$html->clear();
unset($html);


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


function isBotDetected($html, $httpCode) {

    if ($httpCode != 200) {
        return true;
    }

    $patterns = [
        'unusual traffic',
        'robot',
        'detected',
        'sorry',
        'captcha'
    ];

    $htmlLower = strtolower($html);

    foreach ($patterns as $p) {
        if (strpos($htmlLower, $p) !== false) {
            return true;
        }
    }

    return false;
}


?>