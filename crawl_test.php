<?php
include_once('simple_html_dom.php');

#region cara kedua

/* =====================================================
   INPUT & VALIDASI
===================================================== */
$author  = trim($_POST['penulis'] ?? '');
$keyword = trim($_POST['keyword'] ?? '');
$limit   = isset($_POST['jumlahData']) ? (int)$_POST['jumlahData'] : 5;

if ($author === '' && $keyword === '') {
    die('Penulis atau keyword harus diisi');
}

if ($limit <= 0) {
    $limit = 5;
}

/* =====================================================
   QUERY GOOGLE SCHOLAR
===================================================== */
$query = trim($author . ' ' . $keyword);
$scholarUrl = "https://scholar.google.com/scholar?q=" . urlencode($query);

/* =====================================================
   FETCH SCHOLAR PAGE
===================================================== */
$scholarRes = extract_html($scholarUrl);
if ($scholarRes['code'] !== 200) {
    die('Gagal mengakses Google Scholar');
}

if (strpos($scholarRes['message'], 'gs_captcha') !== false) {
    die("Terdeteksi sebagai bot oleh Google!");
}

$scholarDOM = new simple_html_dom();
$scholarDOM->load($scholarRes['message']);

#region function buat author
/**
 * Mengekstrak URL detail sitasi dari baris tabel Google Scholar.
 */
function getViewCitationUrlFromSearchRow($row): ?string
{
    // Menggunakan selector yang lebih spesifik (class dari gambar Anda: gsc_a_at)
    $link = $row->find('a.gsc_a_at', 0);

    if (!$link || empty($link->href)) {
        return null;
    }

    $baseUrl = 'https://scholar.google.com';
    $path = html_entity_decode($link->href);

    // Memastikan path dimulai dengan slash agar URL valid
    return $baseUrl . (strpos($path, '/') === 0 ? '' : '/') . $path;
}



/* =====================================================
   HELPER: AMBIL AUTHOR DARI VIEW ARTICLE
===================================================== */
function getMetaFromViewCitation(string $url): array
{
    $res = extract_html($url);
    if ($res['code'] !== 200) {
        return ['authors'=>[], 'date'=>'-'];
    }

    $dom = new simple_html_dom();
    $dom->load($res['message']);

    $authors = [];
    $date    = '-';

    foreach ($dom->find('div.gs_scl') as $row) {
        $field = $row->find('div.gsc_oci_field', 0);
        $value = $row->find('div.gsc_oci_value', 0);

        if (!$field || !$value) continue;

        $label = strtolower(trim($field->plaintext));
        $text  = trim($value->plaintext);

        if ($label === 'authors') {
            $authors = array_map('trim', explode(',', $text));
        }

        if ($label === 'publication date') {
            $date = normalizeDate($text);
        }
    }

    $dom->clear();
    unset($dom);

    return [
        'authors' => $authors,
        'date'    => $date
    ];
}

function normalizeDate(string $raw): string
{
    // 2020
    if (preg_match('/^\d{4}$/', $raw)) {
        return "01/01/$raw";
    }

    // 2020/02
    if (preg_match('/^(\d{4})\/(\d{1,2})$/', $raw, $m)) {
        return sprintf('01/%02d/%04d', $m[2], $m[1]);
    }

    // 2020-02-15
    if (strtotime($raw)) {
        return date('d/m/Y', strtotime($raw));
    }

    return $raw;
}

/* =====================================================
   HELPER: FALLBACK AUTHOR DARI SNIPPET SCHOLAR
===================================================== */
function getAuthorsFromSnippet($row): array
{
    $meta = $row->find('div.gs_a', 0);
    if (!$meta) return [];

    $raw = explode('-', $meta->plaintext)[0];
    return array_map('trim', explode(',', $raw));
}


/* =====================================================
   HELPER: CEK AUTHOR MATCH
===================================================== */
function isAuthorMatch(array $authors, string $target): bool
{
    $target = strtolower(preg_replace('/[^a-z]/i', '', $target));

    foreach ($authors as $a) {
        $clean = strtolower(preg_replace('/[^a-z]/i', '', $a));

        if (
            strpos($clean, $target) !== false ||
            strpos($target, $clean) !== false
        ) {
            return true;
        }
    }
    return false;
}

#endregion

#region function tanggal
function getPublicationDateFromViewArticle($row): string
{
    $viewNode = $row->find('a[href*="view_op=view_citation"]', 0);
    if (!$viewNode) return '-';

    $url = 'https://scholar.google.com' . $viewNode->href;
    $res = extract_html($url);
    if ($res['code'] !== 200) return '-';

    $dom = new simple_html_dom();
    $dom->load($res['message']);

    $dateRaw = '';

    foreach ($dom->find('div.gs_scl') as $r) {
        $field = $r->find('div.gsc_oci_field', 0);
        $value = $r->find('div.gsc_oci_value', 0);

        if (!$field || !$value) continue;

        $label = strtolower(trim($field->plaintext));

        // PRIORITAS: publication date
        if ($label === 'publication date') {
            $dateRaw = trim($value->plaintext);
            break;
        }
    }

    $dom->clear();
    unset($dom);

    if ($dateRaw === '') return '-';

    /* =====================================================
       NORMALISASI FORMAT TANGGAL
    ===================================================== */

    // 2020/02 → 01/02/2020
    if (preg_match('/^(\d{4})\/(\d{1,2})$/', $dateRaw, $m)) {
        return sprintf('01/%02d/%04d', $m[2], $m[1]);
    }

    // 2020-02-15 → 15/02/2020
    if (strtotime($dateRaw)) {
        return date('d/m/Y', strtotime($dateRaw));
    }

    // 2020 → 01/01/2020
    if (preg_match('/^\d{4}$/', $dateRaw)) {
        return '01/01/' . $dateRaw;
    }

    return $dateRaw;
}

#endregion


/* =====================================================
   PARSE RESULT
===================================================== */
$count = 0;

foreach ($scholarDOM->find('div.gs_r.gs_or.gs_scl') as $row) {

    if ($count >= $limit) break;

    $titleNode = $row->find('h3.gs_rt a', 0);
    if (!$titleNode) continue;

    $judul = trim($titleNode->plaintext);
    $link  = $titleNode->href;

    $citeNode = $row->find('a[href*="cites"]', 0);
    $sitasi = $citeNode ? trim($citeNode->plaintext) : '0';

    /* ===============================
       VIEW CITATION
    =============================== */
    //$authors = [];
    // $tanggal = '-';

    // $viewUrl = getViewCitationUrlFromSearchRow($row);

    // if ($viewUrl) {
    //     $meta = getMetaFromViewCitation($viewUrl);
    //     $authors = $meta['authors'];
    //     $tanggal = $meta['date'];
    // }

    // // fallback
    // if (empty($authors)) {
    //     $authors = getAuthorsFromSnippet($row);
    // }

    // if ($author !== '' && !isAuthorMatch($authors, $author)) {
    //     continue;
    // }

   foreach ($scholarDOM->find('tr.gsc_a_tr') as $row) {
    if ($count >= $limit) break;

    // 1. Ambil Judul dari halaman list (Index)
    $titleNode = $row->find('a.gsc_a_at', 0);
    $judul = $titleNode ? trim($titleNode->plaintext) : 'Tanpa Judul';

    // 2. Ambil URL Detail
    $detailUrl = getViewCitationUrlFromSearchRow($row);
    
    // Inisialisasi default agar tidak kosong/error di output
    $authorsList = [];
    $authorsRaw = "-";
    $publishDate = "-";

    if ($detailUrl) {
        $temp = extract_html($detailUrl);

        if ($temp['code'] == '200') {
            $doms = str_get_html($temp['message']);
            
            if ($doms) {
                foreach ($doms->find('div.gs_scl') as $viewArticle) {
                    $fieldNode = $viewArticle->find('div.gsc_oci_field', 0);
                    $valueNode = $viewArticle->find('div.gsc_oci_value', 0);

                    if ($fieldNode && $valueNode) {
                        $label = trim($fieldNode->plaintext);
                        $value = trim($valueNode->plaintext);

                        // Cek Label 'Authors'
                        if (strcasecmp($label, 'Authors') == 0) {
                            $authorsRaw = $value;
                            // Mengubah string penulis menjadi array agar bisa dihitung
                            $authorsList = array_map('trim', explode(',', $value));
                        }
                        
                        // Cek Label 'Publication date'
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
    /* ===============================
       OUTPUT
    =============================== */
    echo "<b>Judul</b> : {$judul}<br>";
    echo "<b>Penulis</b> : " . (is_array($authorsList) ? implode(', ', $authorsList) : $authorsRaw) . "<br>";
    echo "<b>Jumlah Penulis</b> : " . count($authorsList) . "<br>";
    echo "<b>Tanggal Publish</b> : " . $publishDate . "<br><hr>";
    echo "<b>Sitasi</b> : {$sitasi}<br>";
    echo "<b>Link</b> : {$link}<br><hr>";

    $count++;
    usleep(900000);
    }
}


/* =====================================================
   CLEAN MEMORY
===================================================== */
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