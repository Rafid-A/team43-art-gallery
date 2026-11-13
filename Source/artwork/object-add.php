<?php
require_once __DIR__ . '/library.php';
require_admin();
$type = get_text('type');
$object = library_objects()[$type] ?? null;
if (!$object) { http_response_code(404); exit('Collection not found.'); }
$context = directory_context($type, $_GET);
[$paintingId, $paintingContext] = painting_action_context($_GET);
$paintingUrl = 'painting.php?' . http_build_query(['id' => $paintingId] + $paintingContext);
$errors = $values = [];
if (!empty($object['relationship']) && $paintingId) $values['PaintingID'] = $paintingId;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    [$values, $errors] = field_values($object);
    if (isset($object['gate'])) $errors['form'] = $object['gate'];
    if (!$errors) {
        try {
            $id = add_object($type, $values);
            flash('Added ' . $object['one'] . '.');
            if (!empty($object['relationship']) && $paintingId === $values['PaintingID']) redirect($paintingUrl);
            redirect('objects.php?' . http_build_query(['id' => $id] + $context));
        } catch (DomainException $ex) { $errors['form'] = $ex->getMessage(); }
        catch (PDOException $ex) {
            error_log($ex->getMessage());
            $errors['form'] = $ex->getCode() === '23000' ? 'That value already exists. Choose a unique name or assignment.' : 'The record could not be added. Please try again.';
        }
    }
    if ($errors) http_response_code(422);
}
$title = !empty($object['relationship']) ? 'Assign ' . ($type === 'painting-subjects' ? 'subject' : 'genre') : 'Add ' . $object['one'];
page_start($title, browsing_origin_url($context) ?: 'art-main.php');
?>
<div class="breadcrumbs"><a href="manage.php">Manage library</a><span>/</span><a href="<?= e(directory_url($context)) ?>"><?= e($object['label']) ?></a></div>
<section class="page-heading"><div><p class="eyebrow">Grow the collection</p><h1><?= e($title) ?></h1><p>Fields marked * are required. Record IDs are generated and cannot be edited.</p></div></section>
<?php if (isset($object['gate'])): ?><p class="notice" role="status"><?= e($object['gate']) ?></p>
<?php else: if ($errors): ?><div class="notice error" role="alert"><p>Please correct the following:</p><ul><?php foreach ($errors as $field => $error): ?><li><a href="#<?= e($field) ?>"><?= e($error) ?></a></li><?php endforeach; ?></ul></div><?php endif; ?>
<form class="add-form" id="form" method="post" action="object-add.php?<?= e(http_build_query($paintingId ? ['type' => $type, 'painting' => $paintingId] + $paintingContext : $context)) ?>" data-unsaved-warning data-has-unsaved-values="<?= $errors ? 'true' : 'false' ?>">
<input type="hidden" name="csrf" value="<?= e(token()) ?>"><div class="form-grid">
<?php foreach ($object['fields'] as $name => $definition): [$label, $max] = $definition; $kind = $definition[2] ?? 'text'; $required = in_array($kind, ['required', 'painting', 'genre', 'subject'], true); $value = $values[$name] ?? ''; ?>
<div class="<?= $kind === 'textarea' ? 'wide' : '' ?>"><label for="<?= e($name) ?>"><?= e($label) ?><?= $required ? ' *' : '' ?></label>
<?php if (in_array($kind, ['painting', 'genre', 'subject'], true)): $options = $kind === 'painting' ? rows('SELECT PaintingID AS id, Title AS label FROM Paintings ORDER BY Title')->fetchAll() : ($kind === 'genre' ? rows('SELECT GenreID AS id, GenreName AS label FROM Genres ORDER BY GenreName')->fetchAll() : rows('SELECT SubjectID AS id, SubjectName AS label FROM Subjects ORDER BY SubjectName, SubjectID')->fetchAll()); ?>
<select id="<?= e($name) ?>" name="<?= e($name) ?>" required aria-describedby="<?= e($name) ?>-error" <?= isset($errors[$name]) ? 'aria-invalid="true"' : '' ?>><option value="">Choose <?= e(strtolower($label)) ?></option><?php foreach ($options as $option): ?><option value="<?= (int)$option['id'] ?>" <?= (string)$value === (string)$option['id'] ? 'selected' : '' ?>><?= e($option['label']) ?> · #<?= (int)$option['id'] ?></option><?php endforeach; ?></select>
<?php elseif ($kind === 'textarea'): ?><textarea id="<?= e($name) ?>" name="<?= e($name) ?>" rows="6" maxlength="<?= $max ?>" aria-describedby="<?= e($name) ?>-error" <?= isset($errors[$name]) ? 'aria-invalid="true"' : '' ?>><?= e($value) ?></textarea>
<?php else: ?><input id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>" type="<?= $kind === 'url' ? 'url' : 'text' ?>" <?= $max ? 'maxlength="' . $max . '"' : '' ?> <?= $required ? 'required' : '' ?> aria-describedby="<?= e($name) ?>-error" <?= isset($errors[$name]) ? 'aria-invalid="true"' : '' ?>><?php endif; ?>
<p class="field-error" id="<?= e($name) ?>-error"><?= e($errors[$name] ?? '') ?></p></div><?php endforeach; ?></div>
<button class="primary"><?= e($title) ?></button> <a href="<?= $paintingId ? e($paintingUrl) : e(directory_url($context)) ?>">Cancel</a></form><?php endif; page_end(); ?>
