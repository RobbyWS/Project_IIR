<?php
require 'simple_html_dom.php';

/* ======================================================
   HTTP REQUEST (ANTI BLOKIR DASAR)
====================================================== */
function getHTML($url){
    $headers = [
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
        "Accept-Language: en-US,en;q=0.9"
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

/* ======================================================
   METADATA PARSER (JURNAL + TAHUN)
====================================================== */
function parse_meta($meta){
    $out=['journal'=>'-','year'=>'-'];

    if(preg_match('/\b(19|20)\d{2}\b/', $meta, $y)){
        $out['year']=$y[0];
        $meta=str_replace($y[0],'',$meta);
    }

    $parts = explode('-', $meta);
    $journal = trim($parts[1] ?? $meta);
    $journal = preg_replace('/[,.…]+/u','',$journal);

    $out['journal']=$journal ?: '-';
    return $out;
}

/* ======================================================
   SEMANTIC SCHOLAR API (PRIMARY SOURCE)
====================================================== */
function semanticScholar($title){
    $q = urlencode(trim($title));
    if(!$q) return ['authors'=>'-','citations'=>0];

    $url = "https://api.semanticscholar.org/graph/v1/paper/search"
         . "?query={$q}&limit=1&fields=title,authors,citationCount";

    $ch = curl_init();
    curl_setopt_array($ch,[
        CURLOPT_URL=>$url,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>10,
        CURLOPT_HTTPHEADER=>[
            "User-Agent: SemanticScholarClient/1.0"
        ]
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if(!$resp) return ['authors'=>'-','citations'=>0];
    $json = json_decode($resp,true);

    if(empty($json['data'][0])) return ['authors'=>'-','citations'=>0];

    $p = $json['data'][0];
    $authors = array_map(fn($a)=>$a['name']??'', $p['authors']??[]);
    $authors = implode(', ',array_filter($authors)) ?: '-';

    return [
        'authors'   => $authors,
        'citations' => intval($p['citationCount'] ?? 0)
    ];

}

/* ======================================================
   FALLBACK: AUTHOR FROM SCHOLAR DETAIL
====================================================== */
function getAuthorsFromScholar($html){
    foreach($html->find('div.gsc_oci_field') as $field){
        if(strtolower(trim($field->plaintext)) == 'authors'){
            $value = $field->next_sibling();
            if($value && $value->class == 'gsc_oci_value'){
                return trim($value->plaintext);
            }
        }
    }
    return '-';
}


/* ======================================================
   SIMILARITY (IR COMPONENT)
====================================================== */
function jaccard($a,$b){
    $tok = fn($t)=>array_unique(
        array_filter(explode(" ",strtolower(
            preg_replace('/[^a-z0-9 ]/i',' ',$t)
        )))
    );
    $A=$tok($a); $B=$tok($b);
    if(!$A||!$B) return 0;
    return count(array_intersect($A,$B)) / count(array_unique(array_merge($A,$B)));
}

/* ======================================================
   SMART TRIM (UNTUK TAMPILAN TABEL)
====================================================== */
function smart_trim($text, $max = 120){
    $text = trim(preg_replace('/\s+/',' ', $text));

    if(strlen($text) <= $max){
        return htmlspecialchars($text);
    }

    return '<span title="'.htmlspecialchars($text).'">'
         . htmlspecialchars(substr($text, 0, $max))
         . '...</span>';
}

function parseAuthorsFromGsA($text){
    if(!$text) return '-';

    // Contoh input:
    // "A Silitonga, A Shamsuddin, T Mahlia - Energy, 2020"

    $parts = explode('-', $text);
    $authors = trim($parts[0] ?? '-');

    // rapikan spasi & karakter aneh
    $authors = preg_replace('/\s+/',' ',$authors);
    $authors = trim($authors, " ,.");

    return $authors ?: '-';
}

function normalizeAuthors($authors){
    if(!$authors || $authors === '-') return '-';

    // pisahkan koma
    $list = array_map('trim', explode(',', $authors));

    $clean = [];
    foreach($list as $a){

        // buang karakter aneh
        $a = preg_replace('/\s+/',' ', $a);
        $a = trim($a);

        // contoh: TMI Mahlia → Teuku Meurah Indra Mahlia (jika terdeteksi)
        if(preg_match('/^[A-Z]{2,3}\s+[A-Z][a-z]+$/', $a)){
            // biarkan tapi rapikan
            $clean[] = $a;
        }
        else{
            $clean[] = $a;
        }
    }

    return implode(', ', $clean);
}



/* ======================================================
   INPUT
====================================================== */
$penulis = $_POST['penulis'] ?? '';
$keyword = $_POST['keyword'] ?? '';
$limit   = max(1,intval($_POST['jumlahData'] ?? 5));

$q = urlencode(trim("$penulis $keyword"));
$url = "https://scholar.google.com/scholar?hl=id&q=$q";

/* ======================================================
   GOOGLE SCHOLAR = INDEX DISCOVERY ONLY
====================================================== */
$html = getHTML($url);
if($html==="CAPTCHA") die("⚠ CAPTCHA Google Scholar");
if(!$html) die("Gagal memuat Scholar");

$results=[];
$count=0;


foreach($html->find('.gs_r.gs_or.gs_scl') as $r){

    if($count >= $limit) break;

    // DEFAULT VALUES
    $meta = ['journal'=>'-','year'=>'-'];
    $cites = 0;
    $authors = '-';

    $t = $r->find('.gs_rt a',0);
    if(!$t) continue;

    $title = trim($t->plaintext);
    $link  = $t->href;

    // META
    $metaNode = $r->find('.gs_a',0);
    $metaText = $metaNode ? $metaNode->plaintext : '-';
    $meta = parse_meta($metaText);

   // DEFAULT
   // ================= AUTHOR =================
    $authors = '-';
    $cites   = 0;

    // 1️⃣ Semantic Scholar (PRIMARY – FULL NAME)
    $sem = semanticScholar($title);
    if(!empty($sem['authors']) && $sem['authors'] !== '-'){
        $authors = normalizeAuthors($sem['authors']);
        $cites   = $sem['citations'];
    }
    else{
        // 2️⃣ Scholar Detail Page (SECONDARY)
        if($link){
            $full = (strpos($link,'http')===0)
                ? $link
                : "https://scholar.google.com".$link;

            $detailHTML = getHTML($full);
            if($detailHTML && $detailHTML !== "CAPTCHA"){
                $raw = getAuthorsFromScholar($detailHTML);
                $authors = normalizeAuthors($raw);
            }
            usleep(400000);
        }
    }




    $results[]=[
        'judul'=>$title,
        'penulis'=>$authors,
        'jurnal'=>$meta['journal'],
        'tahun'=>$meta['year'],
        'sitasi'=>$cites,
        'link'=>$link,
        'similarity'=>jaccard($keyword,$title)
    ];


    echo "<pre>";
print_r($authors);
echo "</pre>";


    $count++;
}

function formatAuthorsMultiline($authors){
    if(!$authors || $authors === '-') return '-';

    $list = array_map('trim', explode(',', $authors));
    $list = array_filter($list);

    return implode(",<br>", array_map('htmlspecialchars', $list));
}


/* ======================================================
   OUTPUT (SIAP DITABELKAN)
====================================================== */
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Hasil Pencarian Artikel Ilmiah</title>

<style>
body{
    font-family: Arial, sans-serif;
    margin:20px;
}
table{
    border-collapse:collapse;
    width:100%;
    font-size:14px;
}
th,td{
    border:1px solid #333;
    padding:8px;
    vertical-align:top;
}
th{
    background:#f0f0f0;
    text-align:center;
}
.num{
    text-align:center;
}
.sim-high{background:#d4edda}
.sim-mid{background:#fff3cd}
.sim-low{background:#f8d7da}
small{color:#555}
.header-info{
    background:#f9f9f9;
    border:1px solid #ccc;
    padding:10px;
    margin-bottom:15px;
}
.footer{
    margin-top:20px;
    font-size:12px;
    color:#555;
}
</style>
</head>

<body>

<a href="index.php">&lt; Kembali</a>

<h2>Hasil Pencarian Artikel Ilmiah</h2>

<div class="header-info">
<b>Nama Penulis (Input):</b> <?=htmlspecialchars($penulis ?: '-')?><br>
<b>Kata Kunci:</b> <?=htmlspecialchars($keyword ?: '-')?><br>
<b>Jumlah Data:</b> <?=count($results)?><br>
<b>Sumber:</b> Google Scholar (Discovery) + Semantic Scholar API (Metadata)
</div>

<?php if(empty($results)): ?>
<p><i>Tidak ditemukan data yang sesuai.</i></p>
<?php else: ?>

<table>
<tr>
<th>No</th>
<th>Judul Artikel</th>
<th>Penulis</th>
<th>Nama Jurnal</th>
<th>Tahun</th>
<th>Jumlah Sitasi</th>
<th>Link</th>
<th>Similarity<br><small>(Jaccard)</small></th>
</tr>

<?php 
$no=1;
foreach($results as $r): 
    $simClass = $r['similarity'] >= 0.5 ? 'sim-high' :
               ($r['similarity'] >= 0.2 ? 'sim-mid' : 'sim-low');
?>
<tr class="<?=$simClass?>">
<td class="num"><?=$no++?></td>

<td>
    <?=smart_trim($r['judul'],160)?><br>
    <small title="Judul lengkap">
        <?=htmlspecialchars($r['judul'])?>
    </small>
</td>

<td><?= formatAuthorsMultiline($r['penulis']) ?></td>

<td><?=htmlspecialchars($r['jurnal'])?></td>

<td class="num"><?=htmlspecialchars($r['tahun'])?></td>

<td class="num"><?=htmlspecialchars($r['sitasi'])?></td>

<td class="num">
    <a href="<?=htmlspecialchars($r['link'])?>" target="_blank">Open</a>
</td>

<td class="num">
    <?=number_format($r['similarity'],6)?>
</td>
</tr>
<?php endforeach; ?>

</table>
<?php endif; ?>

<div class="footer">
<b>Catatan Metodologi:</b><br>
- Google Scholar digunakan sebagai <i>discovery layer</i> (judul & link).<br>
- Metadata penulis dan sitasi diperoleh dari <b>Semantic Scholar API</b>.<br>
- Nilai similarity dihitung menggunakan <b>Jaccard Similarity</b> antara keyword dan judul artikel.<br>
- Sistem ini dirancang sebagai simulasi <i>Academic Information Retrieval System</i>.
</div>

</body>
</html>

