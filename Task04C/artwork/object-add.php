<?php
require_once __DIR__ . '/library.php';
require_login();
$type = get_text('type');
$object = library_objects()[$type] ?? null;
if (!$object) { http_response_code(404); exit('Collection not found.'); }
$errors = $values = [];
if ($type === 'painting-genres' && positive_id(get_text('painting'))) $values['PaintingID'] = positive_id(get_text('painting'));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    [$values, $errors] = field_values($object);
    if (isset($object['gate'])) $errors['form'] = $object['gate'];
    if (!$errors) {
        try {
            $id = add_object($type, $values);
            flash('Added ' . $object['one'] . '.');
            redirect('objects.php?' . http_build_query(['type' => $type, 'id' => $id, 'manage' => 1]));
        } catch (DomainException $ex) { $errors['form'] = $ex->getMessage(); }
        catch (PDOException $ex) {
            error_log($ex->getMessage());
            $errors['form'] = $ex->getCode() === '23000' ? 'That value already exists. Choose a unique name or assignment.' : 'The record could not be added. Please try again.';
        }
    }
    if ($errors) http_response_code(422);
}
$title = !empty($object['relationship']) ? 'Assign genre' : 'Add ' . $object['one'];
page_start($title);
?>
<div class="breadcrumbs"><a href="manage.php">Manage library</a><span>/</span><a href="objects.php?type=<?= e($type) ?>&amp;manage=1"><?= e($object['label']) ?></a></div>
<section class="page-heading"><div><p class="eyebrow">Grow the collection</p><h1><?= e($title) ?></h1><p>Fields marked * are required. Record IDs are generated and cannot be edited.</p></div></section>
<?php if (isset($object['gate'])): ?><p class="notice" role="status"><?= e($object['gate']) ?></p>
<?php else: if ($errors): ?><div class="notice error" role="alert"><p>Please correct the following:</p><ul><?php foreach ($errors as $field => $error): ?><li><a href="#<?= e($field) ?>"><?= e($error) ?></a></li><?php endforeach; ?></ul></div><?php endif; ?>
<form class="add-form" id="form" method="post" action="object-add.php?type=<?= e($type) ?>" data-unsaved-warning data-has-unsaved-values="<?= $errors ? 'true' : 'false' ?>">
<input type="hidden" name="csrf" value="<?= e(token()) ?>"><div class="form-grid">
<?php foreach ($object['fields'] as $name => $definition): [$label, $max] = $definition; $kind = $definition[2] ?? 'text'; $required = in_array($kind, ['required', 'painting', 'genre'], true); $value = $values[$name] ?? ''; ?>
<div class="<?= $kind === 'textarea' ? 'wide' : '' ?>"><label for="<?= e($name) ?>"><?= e($label) ?><?= $required ? ' *' : '' ?></label>
<?php if (in_array($kind, ['painting', 'genre'], true)): $options = $kind === 'painting' ? rows('SELECT PaintingID AS id, Title AS label FROM Paintings ORDER BY Title')->fetchAll() : rows('SELECT GenreID AS id, GenreName AS label FROM Genres ORDER BY GenreName')->fetchAll(); ?>
<select id="<?= e($name) ?>" name="<?= e($name) ?>" required aria-describedby="<?= e($name) ?>-error" <?= isset($errors[$name]) ? 'aria-invalid="true"' : '' ?>><option value="">Choose <?= e(strtolower($label)) ?></option><?php foreach ($options as $option): ?><option value="<?= (int)$option['id'] ?>" <?= (string)$value === (string)$option['id'] ? 'selected' : '' ?>><?= e($option['label']) ?> · #<?= (int)$option['id'] ?></option><?php endforeach; ?></select>
<?php elseif ($kind === 'textarea'): ?><textarea id="<?= e($name) ?>" name="<?= e($name) ?>" rows="6" maxlength="<?= $max ?>" aria-describedby="<?= e($name) ?>-error" <?= isset($errors[$name]) ? 'aria-invalid="true"' : '' ?>><?= e($value) ?></textarea>
<?php else: ?><input id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>" type="<?= $kind === 'url' ? 'url' : 'text' ?>" <?= $max ? 'maxlength="' . $max . '"' : '' ?> <?= $required ? 'required' : '' ?> aria-describedby="<?= e($name) ?>-error" <?= isset($errors[$name]) ? 'aria-invalid="true"' : '' ?>><?php endif; ?>
<p class="field-error" id="<?= e($name) ?>-error"><?= e($errors[$name] ?? '') ?></p></div><?php endforeach; ?></div>
<button class="primary"><?= e($title) ?></button> <a href="objects.php?type=<?= e($type) ?>&amp;manage=1">Cancel</a></form><?php endif; page_end(); ?>
