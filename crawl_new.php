<?php
require 'simple_html_dom.php';

/* =========================
   INPUT FORM
========================= */
$author  = trim($_POST['penulis'] ?? '');
$keyword = trim($_POST['keyword'] ?? '');
$limit   = (int)($_POST['jumlahData'] ?? 1);

if ($author === '' && $keyword === '') {
    die('Penulis atau keyword wajib diisi');
}

if ($limit <= 0) $limit = 1;

/* =========================
   REQUEST HTML
========================= */
function get_html(string $url): string|false
{
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                'Accept-Language: en-US,en;q=0.9'
            ]),
            'timeout' => 20
        ]
    ];
    return @file_get_contents($url, false, stream_context_create($opts));
}

/* =========================
   GOOGLE SCHOLAR SEARCH
========================= */
$query = urlencode(trim($author . ' ' . $keyword));
$searchUrl = "https://scholar.google.com/scholar?q={$query}";
$html = get_html($searchUrl);

if (!$html) {
    die('Gagal mengambil halaman Google Scholar');
}

if (strpos($html, 'gs_captcha') !== false) {
    die('Terdeteksi sebagai bot oleh Google Scholar');
}

/* =========================
   PARSE LIST ARTIKEL
========================= */
$dom = new simple_html_dom();
$dom->load($html);

$article = $dom->find('div.gs_r.gs_or.gs_scl', 0);
if (!$article) {
    die('Artikel tidak ditemukan');
}

$link = $article->find('h3.gs_rt a', 0);
if (!$link || empty($link->href)) {
    die('Link artikel tidak ditemukan');
}

$articleUrl = $link->href;
if (!str_starts_with($articleUrl, 'http')) {
    $articleUrl = 'https://scholar.google.com' . $articleUrl;
}

$dom->clear();
unset($dom);

/* =========================
   PANGGIL PYTHON (SELENIUM)
========================= */
$python = '"C:\Users\LENOVO\AppData\Local\Programs\Python\Python313\python.exe"';
$cmd = $python . ' scholar.py ' . escapeshellarg($articleUrl);

$output = shell_exec($cmd . ' 2>&1');
$data = json_decode($output, true);

if (!is_array($data)) {
    echo "<pre>$output</pre>";
    die('Gagal mengambil detail artikel');
}

/* =========================
   OUTPUT
========================= */
echo "<h3>Detail Artikel</h3>";
echo "<b>Judul:</b> " . ($data['Judul'] ?? '-') . "<br>";
echo "<b>Pengarang:</b> " . ($data['Pengarang'] ?? '-') . "<br>";
echo "<b>Tahun:</b> " . ($data['Tahun'] ?? '-') . "<br>";
echo "<b>Jurnal:</b> " . ($data['Jurnal'] ?? '-') . "<br>";
echo "<b>Total Kutipan:</b> " . ($data['Total Sitasi'] ?? '-') . "<br>";
echo "<b>Deskripsi:</b> " . ($data['Deskripsi'] ?? '-') . "<br>";
?>
