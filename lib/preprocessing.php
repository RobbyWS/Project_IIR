<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Register a lightweight PSR-4 autoloader for Wamania\Snowball if Composer didn't map it
spl_autoload_register(function ($class) {
    $prefix = 'Wamania\\Snowball\\';
    $baseDir = __DIR__ . '/../vendor/Wamania/Snowball/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Provide a minimal Joomla StringHelper fallback if the package is not installed
if (!class_exists('Joomla\\String\\StringHelper')) {
    require_once __DIR__ . '/joomla_string_fallback.php';
}

use Sastrawi\Stemmer\StemmerFactory;
use Wamania\Snowball\StemmerFactory as SnowballFactory;

/* ===== Deteksi Bahasa ===== */
function detectLanguage($text){
    if(preg_match('/\b(dan|yang|dari|untuk|pada|dengan)\b/i', $text)){
        return 'id';
    }
    return 'en';
}

/* ===== PREPROCESSING UTAMA ===== */
function preprocessText($text){
    $text = strtolower($text);
    $text = preg_replace('/[^a-z\s]/i', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);

    $lang = detectLanguage($text);

    if($lang === 'id'){
        $factory = new StemmerFactory();
        $stemmer = $factory->createStemmer();
        $text = $stemmer->stem($text);
        $tokens = explode(' ', $text);
    }
    else{
        $factory = new SnowballFactory();
        $stemmer = $factory->create('en');
        $tokens = explode(' ', $text);
        $tokens = array_map(fn($t) => $stemmer->stem($t), $tokens);
    }

    return array_unique(array_filter($tokens));
}
