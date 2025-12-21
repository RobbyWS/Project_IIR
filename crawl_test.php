<?php
include_once('simple_html_dom.php');

/* ================= INPUT & VALIDASI ================= */
$author  = trim($_POST['penulis'] ?? '');
$keyword = trim($_POST['keyword'] ?? '');
$limit   = isset($_POST['jumlahData']) ? (int)$_POST['jumlahData'] : 5;

if ($author === '' && $keyword === '') {
    die('Penulis atau keyword harus diisi');
}

if ($limit <= 0) {
    $limit = 5;
}

/* ================= QUERY GOOGLE SCHOLAR ================= */
$query = trim($author . ' ' . $keyword);
$scholarUrl = "https://scholar.google.com/scholar?q=" . urlencode($query);

/* ================= FETCH SCHOLAR PAGE ================= */
$scholarRes = extract_html($scholarUrl);
if ($scholarRes['code'] !== 200) {
    die('Gagal mengakses Google Scholar');
}

if (strpos($scholarRes['message'], 'gs_captcha') !== false) {
    die('Terdeteksi sebagai bot oleh Google!');
}

$scholarDOM = new simple_html_dom();
$scholarDOM->load($scholarRes['message']);

/* ================= HELPER ================= */
function getViewCitationUrlFromSearchRow($row): ?string
{
    $link = $row->find('a.gsc_a_at', 0);
    if (!$link || empty($link->href)) return null;

    $baseUrl = 'https://scholar.google.com';
    $path = html_entity_decode($link->href);

    return $baseUrl . (strpos($path, '/') === 0 ? '' : '/') . $path;
}

/* ================= PARSE RESULT ================= */
$count = 0;

foreach ($scholarDOM->find('tr.gsc_a_tr') as $row) {

    if ($count >= $limit) break;

    $titleNode = $row->find('a.gsc_a_at', 0);
    $judul = $titleNode ? trim($titleNode->plaintext) : 'Tanpa Judul';

    $detailUrl = getViewCitationUrlFromSearchRow($row);

    $authorsList = [];
    $publishDate = '-';

    if ($detailUrl) {
        $temp = extract_html($detailUrl);

        if ($temp['code'] == 200) {
            $doms = str_get_html($temp['message']);

            if ($doms) {
                foreach ($doms->find('div.gs_scl') as $viewArticle) {
                    $fieldNode = $viewArticle->find('div.gsc_oci_field', 0);
                    $valueNode = $viewArticle->find('div.gsc_oci_value', 0);

                    if ($fieldNode && $valueNode) {
                        $label = trim($fieldNode->plaintext);
                        $value = trim($valueNode->plaintext);

                        if (strcasecmp($label, 'Authors') == 0) {
                            $authorsList = array_map('trim', explode(',', $value));
                        }

                        if (strcasecmp($label, 'Publication date') == 0) {
                            $publishDate = $value;
                        }
                    }
                }
                $doms->clear();
                unset($doms);
            }
        }
    }

    echo "<b>Judul</b> : {$judul}<br>";
    echo "<b>Penulis</b> : " . implode(', ', $authorsList) . "<br>";
    echo "<b>Jumlah Penulis</b> : " . count($authorsList) . "<br>";
    echo "<b>Tanggal Publish</b> : {$publishDate}<br><hr>";

    $count++;
    usleep(5);
}

/* ================= CLEAN MEMORY ================= */
$scholarDOM->clear();
unset($scholarDOM);

#endregion

#region cara pertama
// $penulis = isset($_POST['penulis']) ? trim($_POST['penulis']) : '';
// $keyword = isset($_POST['keyword']) ? trim($_POST['keyword']) : '';
// $limit   = isset($_POST['jumlahData']) ? (int)$_POST['jumlahData'] : 5;

// // VALIDASI
// if ($penulis === '' && $keyword === '') {
//     die("Penulis atau keyword harus diisi");
// }

// if ($limit <= 0) {
//     $limit = 5;
// }

// /**
//  * ============================
//  * QUERY GOOGLE SCHOLAR
//  * ============================
//  */
// $query = trim($penulis . ' ' . $keyword);
// $scholarUrl = "https://scholar.google.com/scholar?q=" . urlencode($query);

// /**
//  * ============================
//  * FETCH SCHOLAR
//  * ============================
//  */
// $scholarRes = extract_html($scholarUrl);
// if ($scholarRes['code'] !== 200) {
//     die("Gagal mengakses Google Scholar");
// }

// $scholarDOM = new simple_html_dom();
// $scholarDOM->load($scholarRes['message']);

// $i = 0;

// foreach ($scholarDOM->find('.gs_r.gs_or.gs_scl') as $r) {

//     if ($i >= $limit) break;

//     // ============================
//     // JUDUL & LINK ARTIKEL
//     // ============================
//     $a = $r->find('.gs_rt a', 0);
//     if (!$a) continue;

//     $judul = trim($a->plaintext);
//     $link  = $a->href;

//     // ============================
//     // TEMBAK LINK PUBLISHER
//     // ============================
//     sleep(5); // ANTI BLOCK

//     $articleRes = extract_html($link);
//     if ($articleRes['code'] !== 200) continue;

//     $articleDOM = new simple_html_dom();
//     $articleDOM->load($articleRes['message']);

//     // ============================
//     // TANGGAL PUBLISH RESMI
//     // ============================
//     $tanggal_publish = '-';
//     if ($m = $articleDOM->find('meta[name=citation_publication_date]', 0)) {
//         $tanggal_publish = date('d/m/Y', strtotime($m->content));
//     }

//     // ============================
//     // NAMA PENULIS
//     // ============================
//     $authors = [];
//     foreach ($articleDOM->find('meta[name=citation_author]') as $a) {
//         $authors[] = trim($a->content);
//     }

//     $jumlah_penulis = count($authors);

//     // ============================
//     // OUTPUT
//     // ============================
//     echo "<b>Judul</b> : {$judul}<br>";
//     echo "<b>Tanggal Publish</b> : {$tanggal_publish}<br>";
//     echo "<b>Penulis</b> : " . ($authors ? implode(', ', $authors) : '-') . "<br>";
//     echo "<b>Jumlah Penulis</b> : {$jumlah_penulis}<br>";
//     echo "<b>Link</b> : {$link}<br>";
//     echo "<b>Query</b> : {$query}<br>";
//     echo "<hr>";

//     // CLEAN MEMORY
//     $articleDOM->clear();
//     unset($articleDOM);

//     $i++;
// }

// // CLEAN MEMORY
// $scholarDOM->clear();
// unset($scholarDOM);
#endregion


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
?>