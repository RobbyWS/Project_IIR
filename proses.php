<?php
require 'simple_html_dom.php';

/* ========================================
   FUNGSI REQUEST HTML (ANTI BLOKIR DASAR)
=========================================*/
function getHTML($url){
    $headers = [
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
        "Accept-Language: en-US,en;q=0.9",
    ];

    $ch = curl_init();
    curl_setopt_array($ch,[
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 20
    ]);

    $resp = curl_exec($ch);
    curl_close($ch);

    if(!$resp) return null;
    if(stripos($resp,"unusual traffic")!==false) return "CAPTCHA";

    return str_get_html($resp);
}

/* ========================================
   SMART TRIM (UNTUK TABEL)
=========================================*/
function smart_trim($text,$max=120){
    $text = trim(preg_replace('/\s+/',' ',$text));
    return (strlen($text)<= $max)
        ? htmlspecialchars($text)
        : '<span title="'.htmlspecialchars($text).'">'.substr($text,0,$max).'...</span>';
}

/* ========================================
   SITASI LEVEL 1 (HASIL PENCARIAN)
=========================================*/
function extract_citation($node){
    foreach($node->find('.gs_fl a') as $a){
        $href = $a->href ?? "";
        $txt = trim($a->plaintext);

        // "Cited by X" (atau bahasa lain)
        if(preg_match('/(Cited by|Dikutip oleh)\s+([0-9]+)/i',$txt,$m)){
            return intval($m[2]);
        }

        // anchor angka saja
        if(preg_match('/^[0-9]+$/', $txt)){
            return intval($txt);
        }
    }
    return 0;
}

/* ========================================
   SITASI LEVEL 2 (HALAMAN DETAIL)
=========================================*/
function extract_citation_article($html){
    if(!$html) return 0;

    foreach($html->find("div.gsc_oci_field") as $f){
        $label = trim($f->plaintext);
        if(preg_match('/(Cited by|Dikutip oleh)/i',$label)){
            $val = $f->next_sibling();
            if($val){
                if(preg_match('/\d+/', trim($val->plaintext), $m))
                    return intval($m[0]);
            }
        }
    }

    // fallback: periksa semua value block apakah ada angka
    foreach($html->find("div.gsc_oci_value") as $v){
        $t = trim($v->plaintext);
        if(preg_match('/\b([0-9]{1,6})\b/',$t,$m)) return intval($m[1]);
    }

    return 0;
}

/* ========================================
   PARSE META (JURNAL + TAHUN) — DIPERTAHANKAN
=========================================*/
function parse_meta($meta){
    $out=['journal'=>'-','year'=>'-'];

    if(preg_match('/\b(19|20)\d{2}\b/', $meta, $y)) {
        $out['year']=$y[0];
        $meta=str_replace($y[0], '', $meta);
    }

    $parts = explode("-", $meta);
    $journal = $parts[1] ?? $meta;

    $journal = preg_replace('/[,.…]+/u', '', $journal);
    $journal = preg_replace('/^[\s\x{00A0}\x{200B}-\x{200D}\x{202F}\x{205F}\x{3000}]+/u','',$journal);
    $journal = trim($journal);

    $out['journal']=$journal;
    return $out;
}

/* ========================================
   SIMILARITY
=========================================*/
function jaccard_similarity($a,$b){
    $to = fn($t)=>array_unique(array_filter(explode(" ",strtolower(preg_replace('/[^a-z0-9 ]/i',' ',$t)))));
    $A=$to($a); $B=$to($b);
    if(!$A||!$B) return 0;
    return count(array_intersect($A,$B)) / count(array_unique(array_merge($A,$B)));
}

/* ========================================
   GET AUTHOR FROM GOOGLE CITATION PAGE (FALLBACK)
=========================================*/
function getAuthorFromCitationPage($url){
    $html = getHTML($url);
    if(!$html || $html==="CAPTCHA") return "-";

    $clean = fn($t)=>trim(preg_replace('/\s+/',' ',$t));

    // cari label "Pengarang" / "Authors"
    foreach($html->find("div.gsc_oci_field") as $field){
        $label = strtolower($clean($field->plaintext));
        if($label === "pengarang" || strpos($label,'author')!==false){
            $val = $field->next_sibling();
            if($val) return $clean($val->plaintext);
        }
    }

    // fallback: blok pertama dengan banyak koma (list nama)
    foreach($html->find("div.gsc_oci_value") as $block){
        $text = $clean($block->plaintext);
        if(substr_count($text, ",") >= 1){
            return $text;
        }
    }

    return "-";
}

/* ========================================
   SEMANTIC SCHOLAR API (PENULIS + SITASI)
   - Gunakan judul untuk mencari satu hasil teratas
=========================================*/
function getSemanticData($title){
    $titleTrim = trim($title);
    if($titleTrim === '' || $titleTrim === '-') return ['authors'=>'-','citations'=>0];

    $q = urlencode($titleTrim);
    $url = "https://api.semanticscholar.org/graph/v1/paper/search?query={$q}&limit=1&fields=title,authors,citationCount";

    // gunakan curl agar kompatibel jika allow_url_fopen mati
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            "User-Agent: Mozilla/5.0 (compatible; SemanticScholarClient/1.0)"
        ]
    ]);
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if(!$resp || $http_code !== 200) {
        return ['authors'=>'-','citations'=>0];
    }

    $json = json_decode($resp, true);
    if(!isset($json['data'][0])) return ['authors'=>'-','citations'=>0];

    $paper = $json['data'][0];

    // Ambil authors
    $authors = '-';
    if(!empty($paper['authors']) && is_array($paper['authors'])){
        $names = array_map(fn($a)=>($a['name'] ?? ''), $paper['authors']);
        $names = array_filter($names);
        if(!empty($names)) $authors = implode(', ', $names);
    }

    $cit = intval($paper['citationCount'] ?? 0);

    return ['authors'=>$authors, 'citations'=>$cit];
}

/* ========================================
   INPUT
=========================================*/
$penulis=$_POST['penulis']??'';
$keyword=$_POST['keyword']??'';
$jumlah=max(1,intval($_POST['jumlahData']??5));

$query=urlencode("$penulis $keyword");
$url="https://scholar.google.com/scholar?hl=id&q=$query";

$html=getHTML($url);

if($html==="CAPTCHA") die("<h2>⚠ CAPTCHA — buka manual:<br><a href='$url'>$url</a></h2>");
if(!$html) die("Gagal load Google Scholar");

/* ========================================
   PROSES SCRAPE (GABUNGKAN)
=========================================*/
$results=[];
$count=0;

foreach($html->find('.gs_r.gs_or.gs_scl') as $r){
    if($count>=$jumlah) break;

    // Judul + link
    $titleNode = $r->find('.gs_rt a',0);
    $title = $titleNode ? trim($titleNode->plaintext) : '-';
    $link = $titleNode ? $titleNode->href : "#";

    // Ambil jurnal + tahun dari hasil (tetap pake kode kamu)
    $metaNode = $r->find('.gs_a',0);
    $parsed = parse_meta($metaNode ? $metaNode->plaintext : '-');

    // =========== PERTAMA: coba Semantic Scholar (recommended) ===========
    $sem = getSemanticData($title);
    $fullAuthors = $sem['authors'];
    $cite = $sem['citations'];

    // jika semantic tidak berhasil (authors == '-') fallback ke scraping google detail
    if(($fullAuthors === '-' || $fullAuthors === '') ){
        if($link && $link !== "#"){
            $fullAuthors = getAuthorFromCitationPage((strpos($link,"http")===0?$link:"https://scholar.google.com".$link));
        } else {
            $fullAuthors = "-";
        }
    }

    // jika semantic tidak memberikan sitasi (0), coba ekstrak dari hasil page (level1) atau detail (level2)
    if($cite === 0){
        $cite = extract_citation($r); // coba ambil dari result item
        if($cite === 0 && $link && $link !== "#"){
            $fullLink = (strpos($link,"http")===0 ? $link : "https://scholar.google.com".$link);
            $detailHtml = getHTML($fullLink);
            if($detailHtml && $detailHtml !== "CAPTCHA"){
                $c2 = extract_citation_article($detailHtml);
                if($c2 > 0) $cite = $c2;
            }
        }
    }

    // safety defaults
    if($fullAuthors === '' ) $fullAuthors = "-";
    if(!is_int($cite)) $cite = intval($cite);

    // jeda kecil agar tidak terlalu agresif
    usleep(300000); // 0.3s

    $results[] = [
        'judul' => $title,
        'penulis' => $fullAuthors,
        'jurnal' => $parsed['journal'],
        'tahun' => $parsed['year'],
        'sitasi' => $cite,
        'link' => $link,
        'similarity' => jaccard_similarity($keyword, $title)
    ];

    $count++;
}

/* ========================================
   OUTPUT HTML
=========================================*/
?>
<!DOCTYPE html>
<html>
<head>
<title>Hasil Google Scholar (gabungan)</title>
<meta charset="utf-8">
<style>
table{border-collapse:collapse;width:100%}
th,td{border:1px solid #000;padding:8px}
th{background:#eee}
.num{text-align:center}
</style>
</head>
<body>

<a href="index.php">&lt; Back</a>

<h3>Nama Penulis (input) : <?=htmlspecialchars($penulis)?></h3>
<h3>Keyword Artikel : <?=htmlspecialchars($keyword)?></h3>
<h3>Jumlah data = <?=count($results)?></h3>

<table>
<tr>
<th>Judul Artikel</th>
<th>Penulis</th>
<th>Nama Jurnal</th>
<th>Tahun</th>
<th>Jumlah Sitasi</th>
<th>Link Jurnal</th>
<th>Nilai Similaritas</th>
</tr>

<?php foreach($results as $r): ?>
<tr>
<td><?=smart_trim($r['judul'],160)?></td>
<td><?=smart_trim($r['penulis'],200)?></td>
<td><?=htmlspecialchars($r['jurnal'])?></td>
<td class="num"><?=htmlspecialchars($r['tahun'])?></td>
<td class="num"><?=htmlspecialchars($r['sitasi'])?></td>
<td><a href="<?=htmlspecialchars($r['link'])?>" target="_blank">Open</a></td>
<td class="num"><?=number_format($r['similarity'],6)?></td>
</tr>
<?php endforeach; ?>

</table>
</body>
</html>
