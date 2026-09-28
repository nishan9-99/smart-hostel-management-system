<?php
/* All list queries use a prepared count and bind LIMIT/OFFSET. */
function page_window($conn, $countSql, $types, $params) {
    $stmt = $conn->prepare($countSql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute(); $total = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
    $pages = max(1, (int)ceil($total / 20));
    $requested = filter_var($_GET['page'] ?? null, FILTER_VALIDATE_INT);
    $page = min(max(1, $requested ?: 1), $pages);
    return [$total, $pages, $page, ($page - 1) * 20];
}
function page_links($page, $pages, $total) {
    if ($pages <= 1) return;
    echo '<nav class="tabs" aria-label="Pagination"><span class="tab">' . (int)$total . ' records</span>';
    foreach ([[max(1, $page - 1), 'Previous'], [$page, (string)$page], [min($pages, $page + 1), 'Next']] as [$number, $label]) {
        if (($label === 'Previous' && $page === 1) || ($label === 'Next' && $page === $pages)) continue;
        $params = $_GET; unset($params['page']); $params['page'] = $number;
        echo '<a class="tab' . ($number === $page ? ' active' : '') . '" href="?' . safe(http_build_query($params)) . '">' . safe($label) . '</a>';
    }
    echo '<span class="tab">of ' . (int)$pages . '</span></nav>';
}
