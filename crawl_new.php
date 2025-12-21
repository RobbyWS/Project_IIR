<?php
$author  = trim($_POST['penulis'] ?? '');
$keyword = trim($_POST['keyword'] ?? '');
$limit   = isset($_POST['jumlahData']) ? (int)$_POST['jumlahData'] : 5;

if ($author === '' && $keyword === '') {
    die('Penulis atau keyword harus diisi');
}

if ($limit <= 0) {
    $limit = 5;
}


$query = trim($author . ' ' . $keyword);
$scholarUrl = "https://scholar.google.com/scholar?q=" . urlencode($query);
$scholarRes = extract_html($scholarUrl);
$scholarDOM = new simple_html_dom();
$scholarDOM->load($scholarRes['message']);

if (strpos($scholarRes['message'], 'gs_captcha') !== false) {
    die('Terdeteksi sebagai bot oleh Google!');
}

#region function

#endregion


$count = 0;

if($scholarDOM['code']=='200'){
    foreach ($scholarDOM->find('tr.gsc_a_tr') as $row) {
        if ($count >= $limit) break;

        //judul artikel
        $titleNode = $row->find('a.gsc_a_at', 0);
        $judul = $titleNode ? trim($titleNode->plaintext) : 'Tanpa Judul';

        //sitasi
        $sitasi =  $row->find('td.gsc_a_c', 0);

        //jurnal



        //penulis



        //tanggal publish




        echo "<b>Judul</b> : {$judul}<br>";
        echo "<b>Sitasi</b> : {$sitasi}<br>";
    }
}

?>