<?php
include_once('simple_html_dom.php');

$penulis = isset($_POST['penulis']) ? trim($_POST['penulis']) : '';
$keyword = isset($_POST['keyword']) ? trim($_POST['keyword']) : '';
$limit   = (int)$_POST['jumlahData'];
if ($penulis === '' && $keyword === '') {
    die("Penulis atau keyword harus diisi");
}


/* ============================
   QUERY SCHOLAR
============================ */
$query = trim($penulis . ' ' . $keyword);
$url = "https://scholar.google.com/scholar?q=" . urlencode($query);

$res = extract_html($url);
if ($res['code'] !== 200) die("Gagal mengakses Scholar");

$dom = new simple_html_dom();
$dom->load($res['message']);

$i = 0;

foreach ($dom->find('.gs_r.gs_or.gs_scl') as $r) {

    if ($i >= $limit) break;

    // ============================
    // JUDUL
    // ============================
    $a = $r->find('.gs_rt a', 0);
    if (!$a) continue;

    $judul = trim($a->plaintext);

    // ============================
    // JUMLAH SITASI
    // ============================
    $sitasi = 0;
    foreach ($r->find('.gs_fl a') as $fl) {
        if (preg_match('/Cited by\s+(\d+)/i', $fl->plaintext, $m)) {
            $sitasi = (int)$m[1];
            break;
        }
    }

    // ============================
    // LINK VIEW ARTICLE SCHOLAR
    // ============================
   $detailUrl = null;

    $cid = $r->getAttribute('data-cid');
    if ($cid) {
        $detailUrl = "https://scholar.google.com/citations?view_op=view_citation&citation_for_view={$cid}";
    }


    $authors = '-';
    $tanggal_publish = '-';

    // ============================
    // CRAWL DETAIL SCHOLAR
    // ============================
    if ($detailUrl) {

        sleep(5); // anti block

        $detailRes = extract_html($detailUrl);
        if ($detailRes['code'] === 200) {

            $detailDOM = new simple_html_dom();
            $detailDOM->load($detailRes['message']);

            $fields = $detailDOM->find('.gsc_vcd_field');
            $values = $detailDOM->find('.gsc_vcd_value');

            foreach ($fields as $idx => $f) {
                $label = strtolower(trim($f->plaintext));
                $value = trim($values[$idx]->plaintext ?? '');

                if (str_contains($label, 'author')) {
                    $authors = $value;
                }

                if (str_contains($label, 'publication date')) {
                    $tanggal_publish = $value;
                }
            }

            $detailDOM->clear();
            unset($detailDOM);
        }
    }

    // ============================
    // OUTPUT
    // ============================
    echo "<b>Judul</b> : {$judul}<br>";
    echo "<b>Penulis</b> : {$authors}<br>";
    echo "<b>Tanggal Publish</b> : {$tanggal_publish}<br>";
    echo "<b>Jumlah Sitasi</b> : {$sitasi}<br>";
    echo "<hr>";

    $i++;
}

$dom->clear();
unset($dom);

/* ============================
   CURL FETCH
============================ */
function extract_html($url)
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
            "Accept-Language: en-US,en;q=0.9"
        ]
    ]);

    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code'=>$code,'message'=>$html];
}
?>
