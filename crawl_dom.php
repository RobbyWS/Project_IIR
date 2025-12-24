<?php
require_once 'simple_html_dom.php';

/* =========================
   INPUT FORM (YOUR ORIGINAL - DO NOT CHANGE)
========================= */
$author  = trim($_POST['penulis'] ?? '');
$keyword = trim($_POST['keyword'] ?? '');
$limit   = (int)($_POST['jumlahData'] ?? 5);

if ($author === '' && $keyword === '') {
    die("Penulis atau keyword harus diisi");
}

/* =========================
   GOOGLE SCHOLAR SEARCH (YOUR ORIGINAL)
========================= */
$query = trim($author . ' ' . $keyword);
$url = "https://scholar.google.com/scholar?q=" . urlencode($query) . "&hl=en&as_sdt=0,5";

$res = extract_html($url);

if ($res['code'] !== 200) {
    die("Gagal mengakses Google Scholar. HTTP Code: " . $res['code']);
}

if (isBotDetected($res['message'], $res['code'])) {
    die("<b>Google Scholar mendeteksi aktivitas bot.</b><br>Tunggu beberapa menit.");
}

/* =========================
   FUNCTION TO CRAWL DETAIL PAGES
   (Extracts authors, date, journal from detail page)
========================= */
function crawl_detail_page($detail_url) {
    // Add delay to avoid rapid requests
    sleep(rand(3, 5));
    
    $result = extract_html($detail_url);
    
    if ($result['code'] !== 200) {
        return ['error' => 'HTTP ' . $result['code']];
    }
    
    // Check for CAPTCHA
    if (strpos(strtolower($result['message']), 'captcha') !== false || 
        strpos(strtolower($result['message']), 'unusual traffic') !== false) {
        return ['error' => 'CAPTCHA detected'];
    }
    
    $dom = new simple_html_dom();
    @$dom->load($result['message']);
    
    $details = [];
    
    // 1. Try to get structured data from the detail page
    // Look for rows with label-value pairs
    foreach ($dom->find('.gsc_oci_table .gsc_oci_value') as $value_div) {
        $label_div = $value_div->prev_sibling();
        
        if ($label_div && $label_div->class == 'gsc_oci_field') {
            $label = strtolower(trim($label_div->plaintext));
            $value = trim($value_div->plaintext);
            
            if (strpos($label, 'author') !== false) {
                $details['authors'] = $value;
            } elseif (strpos($label, 'publication date') !== false || strpos($label, 'date') !== false) {
                $details['pub_date'] = $value;
            } elseif (strpos($label, 'journal') !== false || strpos($label, 'source') !== false || strpos($label, 'conference') !== false) {
                $details['journal'] = $value;
            }
        }
    }
    
    // 2. Fallback: Try alternative selectors
    if (empty($details['authors'])) {
        // Look for authors in other parts of the page
        foreach ($dom->find('div') as $div) {
            $text = strtolower(trim($div->plaintext));
            if (strpos($text, 'authors') === 0 && strlen($text) > 10) {
                $details['authors'] = trim($div->plaintext);
                break;
            }
        }
    }
    
    $dom->clear();
    return $details;
}

/* =========================
   PARSING SEARCH RESULTS & CRAWLING DETAIL PAGES
========================= */
echo "<h2>🔍 Hasil Pencarian untuk: " . htmlspecialchars($query) . "</h2>";

if ($res['code'] == '200') {
    $html = new simple_html_dom();
    $html->load($res['message']);
    
    $i = 0;
    
    // Try different selectors for search results
    $results = $html->find('.gs_r.gs_or.gs_scl, .gs_ri, .gsc_o_result');
    
    if (empty($results)) {
        echo "<div style='background:#ffebee; padding:15px;'>";
        echo "Tidak ada hasil ditemukan. Coba dengan kata kunci lain.";
        echo "</div>";
        
        // Debug: show what we got
        echo "<div style='background:#f5f5f5; padding:10px; margin:10px 0;'>";
        echo "<b>Debug Info:</b> " . strlen($res['message']) . " characters received";
        echo "</div>";
        
        $html->clear();
        exit;
    }
    
    echo "<div style='background:#e8f5e9; padding:10px; margin:10px 0;'>";
    echo "Ditemukan " . count($results) . " hasil. Menampilkan $limit hasil.";
    echo "</div>";
    
    foreach ($results as $row) {
        if ($i >= $limit) break;
        
        echo "<div style='background:#fff; padding:20px; margin:20px 0; border:1px solid #ddd; border-radius:8px;'>";
        echo "<h3 style='color:#2c3e50;'>Hasil #" . ($i+1) . "</h3>";
        
        // Get basic info from search result
        $titleNode = $row->find('.gs_rt a, h3 a, .gsc_o_at', 0);
        
        if (!$titleNode) {
            echo "<p style='color:red'>Tidak ada judul ditemukan</p>";
            echo "</div>";
            continue;
        }
        
        $judul = trim($titleNode->plaintext);
        $link = $titleNode->href;
        
        // Get citation count
        $citeNode = $row->find('a[href*="cites"], .gs_fl a', 0);
        $sitasi = '0';
        if ($citeNode) {
            $citeText = trim($citeNode->plaintext);
            if (preg_match('/\d+/', $citeText, $matches)) {
                $sitasi = $matches[0];
            }
        }
        
        // Display basic info
        echo "<div style='background:#f8f9fa; padding:15px; border-radius:5px; margin-bottom:15px;'>";
        echo "<h4>" . htmlspecialchars($judul) . "</h4>";
        echo "<p><b>📊 Sitasi:</b> $sitasi</p>";
        echo "<p><b>🔗 Link:</b> <a href='" . htmlspecialchars($link) . "' target='_blank'>" . htmlspecialchars($link) . "</a></p>";
        echo "</div>";
        
        // Now try to get detail page link
        $detail_link = null;
        
        // Try different selectors for detail link
        $detail_selectors = [
            'a[href*="view_citation"]',
            'a[href*="view_op=view_citation"]',
            '.gs_or_btn.gs_or_btnsm',
            '.gs_or_cit'
        ];
        
        foreach ($detail_selectors as $selector) {
            $detailNode = $row->find($selector, 0);
            if ($detailNode) {
                $href = $detailNode->href;
                if (strpos($href, 'http') === 0) {
                    $detail_link = $href;
                } else {
                    $detail_link = 'https://scholar.google.com' . html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
                }
                break;
            }
        }
        
        if ($detail_link) {
            echo "<div style='background:#e8f4fd; padding:15px; border-radius:5px;'>";
            echo "<h4 style='color:#16a085;'>📖 Mengambil detail artikel...</h4>";
            
            // Crawl the detail page
            $details = crawl_detail_page($detail_link);
            
            if (!empty($details) && !isset($details['error'])) {
                echo "<div style='background:#fff; padding:15px; border:1px solid #bdc3c7; border-radius:5px;'>";
                
                // Display authors
                if (isset($details['authors'])) {
                    echo "<p><b>👥 Authors:</b> " . htmlspecialchars($details['authors']) . "</p>";
                } else {
                    // Fallback: try to get authors from search result snippet
                    $authorDiv = $row->find('.gs_a, .gs_auth, .gs_rs', 0);
                    if ($authorDiv) {
                        $authorText = trim($authorDiv->plaintext);
                        $parts = explode(' - ', $authorText);
                        if (count($parts) > 0) {
                            echo "<p><b>👥 Authors (from snippet):</b> " . htmlspecialchars($parts[0]) . "</p>";
                        }
                    } else {
                        echo "<p style='color:#e74c3c;'>❌ Authors tidak ditemukan</p>";
                    }
                }
                
                // Display publication date
                if (isset($details['pub_date'])) {
                    echo "<p><b>📅 Publication Date:</b> " . htmlspecialchars($details['pub_date']) . "</p>";
                } else {
                    echo "<p style='color:#e74c3c;'>❌ Publication date tidak ditemukan</p>";
                }
                
                // Display journal
                if (isset($details['journal'])) {
                    echo "<p><b>🏛️ Journal:</b> " . htmlspecialchars($details['journal']) . "</p>";
                } else {
                    // Fallback: try to get journal from search result
                    $authorDiv = $row->find('.gs_a, .gs_auth', 0);
                    if ($authorDiv) {
                        $authorText = trim($authorDiv->plaintext);
                        $parts = explode(' - ', $authorText);
                        if (count($parts) >= 2) {
                            echo "<p><b>🏛️ Journal (from snippet):</b> " . htmlspecialchars($parts[1]) . "</p>";
                        }
                    } else {
                        echo "<p style='color:#e74c3c;'>❌ Journal tidak ditemukan</p>";
                    }
                }
                
                echo "</div>";
            } else {
                echo "<div style='background:#ffebee; padding:15px; border-radius:5px;'>";
                echo "<p style='color:#c0392b;'><b>⚠ Gagal mengambil detail</b></p>";
                if (isset($details['error'])) {
                    echo "<p>Error: " . htmlspecialchars($details['error']) . "</p>";
                }
                echo "<p>Menampilkan info dari halaman hasil...</p>";
                
                // Show fallback info from search result
                $authorDiv = $row->find('.gs_a, .gs_auth', 0);
                if ($authorDiv) {
                    $authorText = trim($authorDiv->plaintext);
                    $parts = explode(' - ', $authorText);
                    if (count($parts) > 0) {
                        echo "<p><b>👥 Authors:</b> " . htmlspecialchars($parts[0]) . "</p>";
                    }
                    if (count($parts) >= 2) {
                        echo "<p><b>🏛️ Journal/Year:</b> " . htmlspecialchars($parts[1]) . "</p>";
                    }
                }
                echo "</div>";
            }
            
            echo "</div>";
        } else {
            echo "<div style='background:#fff3cd; padding:15px; border-radius:5px;'>";
            echo "<p style='color:#856404;'>⚠ Link detail tidak tersedia untuk artikel ini</p>";
            echo "<p>Menampilkan info yang tersedia dari halaman hasil...</p>";
            
            // Show what we can get from search result
            $authorDiv = $row->find('.gs_a, .gs_auth', 0);
            if ($authorDiv) {
                $authorText = trim($authorDiv->plaintext);
                $parts = explode(' - ', $authorText);
                if (count($parts) > 0) {
                    echo "<p><b>👥 Authors:</b> " . htmlspecialchars($parts[0]) . "</p>";
                }
                if (count($parts) >= 2) {
                    echo "<p><b>🏛️ Journal/Year:</b> " . htmlspecialchars($parts[1]) . "</p>";
                }
            }
            echo "</div>";
        }
        
        echo "</div>";
        
        $i++;
        
        // Add delay between processing results
        if ($i < $limit) {
            sleep(rand(2, 4));
        }
    }
    
    if ($i == 0) {
        echo "<div style='background:#ffebee; padding:20px;'>";
        echo "<h3>Tidak ada hasil yang dapat diproses</h3>";
        echo "<p>Struktur HTML mungkin berubah. Coba dengan pencarian yang berbeda.</p>";
        echo "</div>";
    }
    
    $html->clear();
    
} else {
    echo "<div style='background:#ffebee; padding:20px;'>";
    echo "<h3>Error</h3>";
    echo "<p>HTTP Code: " . $res['code'] . "</p>";
    echo "</div>";
}

/* =========================
   HELPER FUNCTIONS (Keep your original extract_html)
========================= */
function extract_html($url) {
    $response = ['code' => '', 'message' => '', 'status' => false];
    
    $agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    
    $host = parse_url($url, PHP_URL_HOST);
    $scheme = parse_url($url, PHP_URL_SCHEME);
    $referrer = $scheme . '://' . $host; 
    
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => $agent,
        CURLOPT_REFERER => $referrer,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_ENCODING => 'gzip, deflate',
    ]);
    
    $content = curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $response['code'] = $code;
    
    if ($content === false) {
        $response['status'] = false;
        $response['message'] = curl_error($curl);
    } else {
        $response['status'] = true;
        $response['message'] = $content;
    }
    
    curl_close($curl);
    return $response;
}

function isBotDetected($html, $httpCode) {
    if ($httpCode != 200) return true;
    $patterns = ['unusual traffic', 'robot', 'detected', 'sorry', 'captcha'];
    $htmlLower = strtolower($html);
    foreach ($patterns as $p) {
        if (strpos($htmlLower, $p) !== false) return true;
    }
    return false;
}
?>