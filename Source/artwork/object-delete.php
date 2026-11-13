<?php
require_once __DIR__ . '/library.php';
require_login();
$type = get_text('type');
$object = library_objects()[$type] ?? null;
$id = positive_id(get_text('id'));
$context = directory_context($type, $_GET);
[$paintingId, $paintingContext] = painting_action_context($_GET);
$paintingUrl = 'painting.php?' . http_build_query(['id' => $paintingId] + $paintingContext);
$actionContext = $paintingId ? ['type' => $type, 'painting' => $paintingId] + $paintingContext : $context;
if (!$object || !$object['key'] || isset($object['gate'])) { http_response_code(409); page_start('Action unavailable'); echo '<h1>Action unavailable</h1><p>' . e($object['gate'] ?? 'An approved stable record identity is required.') . '</p><a href="manage.php">Manage library</a>'; page_end(); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    try { delete_object($type, $id); flash('Removed ' . $object['one'] . '.'); }
    catch (DomainException $ex) { flash($ex->getMessage(), 'error'); }
    catch (PDOException $ex) { error_log($ex->getMessage()); flash('The record could not be removed. No changes were saved.', 'error'); }
    redirect($paintingId ? $paintingUrl : directory_url($context));
}
$record = rows(object_select($type) . ' WHERE o.' . $object['key'] . '=?', [$id])->fetch();
if (!$record) http_response_code(404);
page_start('Confirm removal', browsing_origin_url($context) ?: 'art-main.php');
?>
<section class="confirmation"><p class="eyebrow">Library management</p><h1><?= $record ? 'Remove this ' . e($object['one']) . '?' : 'Record not found' ?></h1>
<?php if ($record): ?><h2><?= e(object_title($type, $record)) ?></h2><p>Record #<?= $id ?>. <?= !empty($object['relationship']) ? 'Only this assignment will be removed. Both parent records and other assignments are preserved.' : 'Deletion is blocked while artworks or assignments reference this record.' ?></p>
<form method="post" action="object-delete.php?<?= e(http_build_query(['id' => $id] + $actionContext)) ?>"><input type="hidden" name="csrf" value="<?= e(token()) ?>"><button class="danger">Confirm removal</button> <a href="<?= e($paintingId ? $paintingUrl : object_url($type, $record, (bool)$context['manage'], $context)) ?>">Keep record</a></form>
<?php else: ?><a href="<?= e(directory_url($context)) ?>">Return to directory</a><?php endif; ?></section><?php page_end(); ?>
