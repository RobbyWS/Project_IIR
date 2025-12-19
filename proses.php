<?php
require 'vendor/autoload.php';
require_once 'lib/crawl.php';
require_once 'lib/preprocessing.php';
require_once 'lib/similarity.php';

/* ======================================================
   INPUT USER
====================================================== */
$penulis = $_POST['penulis'] ?? '';
$keyword = $_POST['keyword'] ?? '';
$limit = max(1, intval($_POST['jumlahData'] ?? 5));

/* ======================================================
   PROSES CRAWLING
====================================================== */
$data = crawlScholar($penulis, $keyword, $limit);

/* ======================================================
   PREPROCESSING + SIMILARITY
====================================================== */
$results = [];

foreach ($data as $d) {

    // preprocessing keyword & judul
    $cleanKeyword = preprocessText($keyword);
    $cleanTitle = preprocessText($d['judul']);

    // similarity
    $similarity = jaccardSimilarity(
        implode(' ', $cleanKeyword),
        implode(' ', $cleanTitle)
    );

    $d['similarity'] = $similarity;
    $results[] = $d;
}

/* ======================================================
   HELPER TAMPILAN
====================================================== */
function smart_trim($text, $max = 150)
{
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if (strlen($text) <= $max)
        return htmlspecialchars($text);
    return '<span title="' . htmlspecialchars($text) . '">'
        . htmlspecialchars(substr($text, 0, $max)) . '...</span>';
}

function formatAuthorsMultiline($authors)
{
    if (!$authors || $authors === '-')
        return '-';
    return implode(",<br>", array_map(
        'htmlspecialchars',
        array_map('trim', explode(',', $authors))
    ));
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Hasil Pencarian Artikel Ilmiah</title>
    <style>
        body {
            font-family: Arial;
            margin: 20px
        }

        table {
            border-collapse: collapse;
            width: 100%
        }

        th,
        td {
            border: 1px solid #333;
            padding: 8px;
            vertical-align: top
        }

        th {
            background: #f0f0f0
        }

        .num {
            text-align: center
        }

        .sim-high {
            background: #d4edda
        }

        .sim-mid {
            background: #fff3cd
        }

        .sim-low {
            background: #f8d7da
        }
    </style>
</head>

<body>

    <a href="index.php">&lt; Kembali</a>
    <h2>Hasil Pencarian Artikel Ilmiah</h2>

    <p>
        <b>Penulis:</b> <?= htmlspecialchars($penulis ?: '-') ?><br>
        <b>Keyword:</b> <?= htmlspecialchars($keyword ?: '-') ?><br>
        <b>Jumlah Data:</b> <?= count($results) ?>
    </p>

    <?php if (empty($results)): ?>
        <p><i>Data tidak ditemukan.</i></p>
    <?php else: ?>

        <table>
            <tr>
                <th>No</th>
                <th>Judul</th>
                <th>Penulis</th>
                <th>Jurnal</th>
                <th>Tahun</th>
                <th>Sitasi</th>
                <th>Link</th>
                <th>Similarity</th>
            </tr>

            <?php
            $no = 1;
            foreach ($results as $r):
                $class = $r['similarity'] >= 0.5 ? 'sim-high' :
                    ($r['similarity'] >= 0.2 ? 'sim-mid' : 'sim-low');
                ?>
                <tr class="<?= $class ?>">
                    <td class="num"><?= $no++ ?></td>
                    <td><?= smart_trim($r['judul']) ?></td>
                    <td><?= formatAuthorsMultiline($r['penulis']) ?></td>
                    <td><?= htmlspecialchars($r['jurnal']) ?></td>
                    <td class="num"><?= $r['tahun'] ?></td>
                    <td class="num"><?= $r['sitasi'] ?></td>
                    <td class="num"><a href="<?= $r['link'] ?>" target="_blank">Open</a></td>
                    <td class="num"><?= number_format($r['similarity'], 6) ?></td>
                </tr>
            <?php endforeach; ?>

        </table>
    <?php endif; ?>

    <p style="font-size:12px;color:#555;margin-top:20px">
        <b>Catatan:</b><br>
        - Crawling: Google Scholar + Semantic Scholar API<br>
        - Preprocessing: Deteksi Bahasa, Stemming (Sastrawi & Porter)<br>
        - Similarity: Jaccard Coefficient
    </p>

</body>

</html>