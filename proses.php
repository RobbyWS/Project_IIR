<?php
include_once('simple_html_dom.php');

echo "<b><a href='index.php'>< Back to Home</a></b><br><br>";

echo "
<table border='1' cellpadding='10' cellspacing='0'>
    <tr>
        <th>Judul Artikel</th>
        <th>Penulis</th>
        <th>Tanggal Rilis</th>
        <th>Nama Jurnal</th>
        <th>Jumlah Sitasi</th>
        <th>Link Jurnal</th>
    </tr>
";

if($_POST['source'] == 'scholar') {

    $author  = urlencode($_POST['penulis']);
    $keyword = urlencode($_POST['keyword']);
    $limit   = (int)$_POST['jumlahData'];

    // URL Google Scholar
    $url = "https://scholar.google.com/scholar?q=$author+$keyword";

    // Ambil HTML
    $html = file_get_html($url);

    if(!$html){
        echo "<tr><td colspan='6'>Gagal mengambil data dari Google Scholar.</td></tr>";
    }
    else {

        $i = 0;
        foreach($html->find('.gs_ri') as $item) {

            if ($i >= $limit) break;

            // ==========================
            // 1. Judul + Link
            // ==========================
            $titleEl = $item->find('.gs_rt a', 0);
            $title   = $titleEl ? $titleEl->plaintext : "Tanpa Judul";
            $link    = $titleEl ? $titleEl->href : "#";

            // ==========================
            // 2. Penulis, Jurnal, Tahun/Tanggal
            // ==========================
            $meta = $item->find('.gs_a', 0);
            $metaText = $meta ? $meta->plaintext : "-";

            // Format Contoh Google Scholar:
            // "A Smith, B John - Renewable Energy, 2020"
            $parts = explode(" - ", $metaText);

            $authors = $parts[0] ?? "-";
            $journal = $parts[1] ?? "-";

            // Cari tanggal atau tahun
            preg_match('/\b((19|20)\d{2}|[0-9]{2}\/[0-9]{2}\/[0-9]{4})\b/', $metaText, $dateMatch);
            $releaseDate = $dateMatch[0] ?? "-";

            // ==========================
            // 3. Jumlah Sitasi
            // ==========================
            $citations = 0;
            foreach($item->find('.gs_fl a') as $a) {
                if (strpos($a->plaintext, 'Cited by') !== false) {
                    $citations = intval(str_replace("Cited by ", "", $a->plaintext));
                    break;
                }
            }

            // ==========================
            // OUTPUT TABEL
            // ==========================

            echo "
            <tr>
                <td>$title</td>
                <td>$authors</td>
                <td>$releaseDate</td>
                <td>$journal</td>
                <td>$citations</td>
                <td><a href='$link' target='_blank'>$link</a></td>
            </tr>
            ";

            $i++;
        }
    }
}

echo "</table>";
?>
