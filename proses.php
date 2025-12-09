<?php
require 'simple_html_dom.php';

/* ========================================
   Fungsi Request HTML (Anti Blokir Dasar)
=========================================*/
function getHTML($url) {
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

/* ======================= Format Table ======================= */
function smart_trim($text,$max=120){
    $text = trim(preg_replace('/\s+/',' ',$text));
    return (strlen($text)<=$max) ? htmlspecialchars($text)
         : '<span title="'.htmlspecialchars($text).'">'.substr($text,0,$max).'...</span>';
}

/* ===================== Ambil Sitasi ===================== */
function extract_citation($node){
    foreach($node->find('.gs_fl a') as $a){
        if(preg_match('/Cited by ([0-9]+)/i',$a->plaintext,$m)) return intval($m[1]);
        if(preg_match('/Dikutip oleh ([0-9]+)/i',$a->plaintext,$m)) return intval($m[1]);
    }
    return 0;
}

/* ===================== PARSE META (Journal saja, Tahun dipisah) ===================== */
function parse_meta($meta){
    $out=['journal'=>'-','year'=>'-'];

    if(preg_match('/\b(19|20)\d{2}\b/', $meta, $y)) {
        $out['year']=$y[0];
        $meta=str_replace($y[0], '', $meta);
    }

    $parts = explode("-", $meta);
    $journal = $parts[1] ?? $meta;

    $journal = preg_replace('/[,.…]+/u', '', $journal);

    /* ========= FIX HAPUS SPASI DEPAN JURNAL ========= */
    $journal = preg_replace('/^[\s\x{00A0}\x{200B}-\x{200D}\x{202F}\x{205F}\x{3000}]+/u','',$journal);
    $journal = preg_replace('/\s{2,}/',' ', $journal);
    $journal = trim($journal);

    $out['journal']=$journal;
    return $out;
}

/* ===================== Similarity ===================== */
function jaccard_similarity($a,$b){
    $to = fn($t)=>array_unique(array_filter(explode(" ",strtolower(preg_replace('/[^a-z0-9 ]/i',' ',$t)))));
    $A=$to($a); $B=$to($b);
    if(!$A||!$B) return 0;
    return count(array_intersect($A,$B)) / count(array_unique(array_merge($A,$B)));
}

/* ===================== Input ===================== */
$penulis=$_POST['penulis']??'';
$keyword=$_POST['keyword']??'';
$jumlah=max(1,intval($_POST['jumlahData']??5));

$query=urlencode("$penulis $keyword");
$url="https://scholar.google.com/scholar?hl=id&q=$query";
$html=getHTML($url);

if($html==="CAPTCHA"){
    die("<h2>⚠ CAPTCHA - buka manual:<br><a href='$url'>$url</a></h2>");
}
if(!$html) die("Gagal load Google Scholar");

/* ===================== PROSES SCRAPE ===================== */
$results=[];
$count=0;

foreach($html->find('.gs_r.gs_or.gs_scl') as $r){
    if($count>=$jumlah) break;

    $titleNode=$r->find('.gs_rt a',0);
    $title=$titleNode?trim($titleNode->plaintext):'-';
    $link=$titleNode?$titleNode->href:"#";
    $cite=extract_citation($r);

    $metaNode=$r->find('.gs_a',0);
    $parsed= parse_meta($metaNode? $metaNode->plaintext : '-');

    /*  AMBIL PENULIS LENGKAP DARI DETAIL */
    $fullAuthors="-";
    if($link!=="#"){
        $detail=getHTML($link);
        if($detail && $detail!=="CAPTCHA"){
            foreach($detail->find('.gsc_oci_field') as $i=>$f){
                if(trim($f->plaintext)=="Pengarang"){
                    $fullAuthors=trim($detail->find('.gsc_oci_value',$i)->plaintext);
                    break;
                }
            }
        }
        sleep(2);
    }

    $results[]=[
        'judul'=>$title,
        'penulis'=>$fullAuthors,
        'jurnal'=>$parsed['journal'],
        'tahun'=>$parsed['year'],
        'sitasi'=>$cite,
        'link'=>$link,
        'similarity'=>jaccard_similarity($keyword,$title)
    ];
    $count++;
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Hasil Google Scholar</title>
<style>
table{border-collapse:collapse;width:100%}
th,td{border:1px solid #000;padding:8px}
th{background:#eee}
.num{text-align:center}
</style>
</head>
<body>

<a href="index.php">&lt; Back</a>

<h3>Nama Penulis : <?=$penulis?></h3>
<h3>Keyword Artikel : <?=$keyword?></h3>
<h3>Jumlah data = <?=count($results)?></h3>

<table>
<tr>
<th>Judul Artikel</th>
<th>Penulis Lengkap</th>
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
<td><?=$r['jurnal']?></td>
<td class="num"><?=$r['tahun']?></td>
<td class="num"><?=$r['sitasi']?></td>
<td><a href="<?=$r['link']?>" target="_blank">Open</a></td>
<td class="num"><?=number_format($r['similarity'],6)?></td>
</tr>
<?php endforeach; ?>

</table>
</body>
</html>
