<?php
//JACCARD SIMILARITY (STEMMED)
function jaccardSimilarity($textA, $textB){
    $A = preprocessText($textA);
    $B = preprocessText($textB);

    if(empty($A) || empty($B)) return 0;

    return count(array_intersect($A,$B))
         / count(array_unique(array_merge($A,$B)));
}

//DICE COEFFICIENT (OPSIONAL)
function diceSimilarity($textA, $textB){
    $A = preprocessText($textA);
    $B = preprocessText($textB);

    if(empty($A) || empty($B)) return 0;

    return (2 * count(array_intersect($A,$B)))
         / (count($A) + count($B));
}
?>