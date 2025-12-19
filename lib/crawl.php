    <?php
require_once __DIR__ . '/../simple_html_dom.php';

/* ======================================================
   HTTP REQUEST (ANTI BLOKIR DASAR)
====================================================== */
function getHTML($url)
{
    $headers = [
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
        "Accept-Language: en-US,en;q=0.9"
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 20
    ]);

    $resp = curl_exec($ch);
    curl_close($ch);

    if (!$resp)
        return null;
    if (stripos($resp, "unusual traffic") !== false)
        return "CAPTCHA";

    return str_get_html($resp);
}

/* ======================================================
   PARSE META (JURNAL & TAHUN)
====================================================== */
function parse_meta($meta)
{
    $out = ['journal' => '-', 'year' => '-'];

    if (preg_match('/\b(19|20)\d{2}\b/', $meta, $y)) {
        $out['year'] = $y[0];
        $meta = str_replace($y[0], '', $meta);
    }

    $parts = explode('-', $meta);
    $journal = trim($parts[1] ?? $meta);
    $journal = preg_replace('/[,.…]+/u', '', $journal);

    $out['journal'] = $journal ?: '-';
    return $out;
}

/* ======================================================
   SEMANTIC SCHOLAR API (AUTHOR + CITATION)
====================================================== */
function semanticScholar($title)
{
    $q = urlencode(trim($title));
    if (!$q)
        return ['authors' => '-', 'citations' => 0];

    $url = "https://api.semanticscholar.org/graph/v1/paper/search"
        . "?query={$q}&limit=1&fields=title,authors,citationCount";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            "User-Agent: SemanticScholarClient/1.0"
        ]
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if (!$resp)
        return ['authors' => '-', 'citations' => 0];
    $json = json_decode($resp, true);

    if (empty($json['data'][0]))
        return ['authors' => '-', 'citations' => 0];

    $p = $json['data'][0];
    $authors = array_map(fn($a) => $a['name'] ?? '', $p['authors'] ?? []);
    $authors = implode(', ', array_filter($authors)) ?: '-';

    return [
        'authors' => $authors,
        'citations' => intval($p['citationCount'] ?? 0)
    ];
}

/* ======================================================
   NORMALISASI NAMA PENULIS
====================================================== */
function normalizeAuthors($authors)
{
    if (!$authors || $authors === '-')
        return '-';

    $list = array_map('trim', explode(',', $authors));
    $clean = [];

    foreach ($list as $a) {
        $a = preg_replace('/\s+/', ' ', $a);
        $a = trim($a, " ,.");
        if ($a)
            $clean[] = $a;
    }

    return implode(', ', $clean) ?: '-';
}

/* ======================================================
   FUNGSI UTAMA CRAWLING
====================================================== */
function crawlScholar($penulis, $keyword, $limit)
{

    $q = urlencode(trim("$penulis $keyword"));
    $url = "https://scholar.google.com/scholar?hl=id&q=$q";

    $html = getHTML($url);
    if (!$html || $html === "CAPTCHA")
        return [];

    $results = [];
    $count = 0;

    foreach ($html->find('.gs_r.gs_or.gs_scl') as $r) {
        if ($count >= $limit)
            break;

        $t = $r->find('.gs_rt a', 0);
        if (!$t)
            continue;

        $title = trim($t->plaintext);
        $link = $t->href;

        $metaNode = $r->find('.gs_a', 0);
        $metaText = $metaNode ? $metaNode->plaintext : '-';
        $meta = parse_meta($metaText);

        $authors = '-';
        $cites = 0;

        $sem = semanticScholar($title);
        if ($sem['authors'] !== '-') {
            $authors = normalizeAuthors($sem['authors']);
            $cites = $sem['citations'];
        }

        $results[] = [
            'judul' => $title,
            'penulis' => $authors,
            'jurnal' => $meta['journal'],
            'tahun' => $meta['year'],
            'sitasi' => $cites,
            'link' => $link
        ];

        $count++;
        usleep(400000);
    }

    return $results;
}
?>