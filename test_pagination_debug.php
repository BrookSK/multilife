<?php
// Teste de paginação - debug
$items = range(1, 10); // 10 items
$itemsPerPage = 5;

echo "<h3>Teste de Paginação</h3>";
echo "<p>Total items: " . count($items) . "</p>";
echo "<p>Items per page: $itemsPerPage</p>";
echo "<hr>";

foreach ($items as $idx => $item) {
    $pageIndex = floor($idx / $itemsPerPage);
    
    $styles = [];
    if ($pageIndex !== 0) {
        $styles[] = 'display:none';
    }
    
    $styleAttr = count($styles) > 0 ? ' style="' . implode(';', $styles) . '"' : '';
    
    echo "<div data-page-index='$pageIndex'$styleAttr>";
    echo "Item #$item (idx=$idx, page=$pageIndex)";
    if ($pageIndex !== 0) {
        echo " [HIDDEN]";
    } else {
        echo " [VISIBLE]";
    }
    echo "</div>";
}

echo "<hr>";
echo "<p>Esperado: Items 1-5 visíveis, Items 6-10 escondidos</p>";
?>
