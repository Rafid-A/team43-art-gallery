<?php
require_once __DIR__ . '/library.php';
require_login();

$id = positive_id(get_text('id'));
$fields = artist_edit_fields();
$object = ['fields' => $fields];
$record = rows('SELECT ArtistID, FirstName, LastName, Nationality, YearOfBirth, YearOfDeath FROM Artists WHERE ArtistID=?', [$id])->fetch();
$errors = [];
$values = [];

if (!$record) {
    http_response_code(404);
    page_start('Artist not found');
    echo '<h1>Artist not found</h1><p>It may have been removed.</p><a href="objects.php?type=artists">Return to artists</a>';
    page_end();
    exit;
}

foreach ($fields as $name => $definition) $values[$name] = $record[$name] == 0 ? '' : ($record[$name] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    [$values, $errors] = field_values($object);
    if (!$errors) {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $locked = rows('SELECT ArtistID FROM Artists WHERE ArtistID=? FOR UPDATE', [$id])->fetch();
            if (!$locked) throw new DomainException('The artist no longer exists. Refresh and try again; no record was changed.');
            rows('UPDATE Artists SET FirstName=?, LastName=?, Nationality=?, YearOfBirth=?, YearOfDeath=? WHERE ArtistID=?', [
                $values['FirstName'], $values['LastName'], $values['Nationality'],
                $values['YearOfBirth'] === null ? 0 : (int)$values['YearOfBirth'],
                $values['YearOfDeath'] === null ? 0 : (int)$values['YearOfDeath'], $id
            ]);
            $pdo->commit();
            flash('Updated artist.');
            redirect('objects.php?type=artists&id=' . $id);
        } catch (DomainException $ex) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $errors['form'] = $ex->getMessage();
        } catch (PDOException $ex) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            error_log($ex->getMessage());
            $errors['form'] = 'The artist could not be updated. No records were changed.';
        }
    }
    if ($errors) http_response_code(422);
}

page_start('Edit artist');
?>
<div class="breadcrumbs"><a href="objects.php?type=artists">Artists</a><span>/</span>Edit artist</div>
<section class="page-heading"><div><p class="eyebrow">Library management · Artist #<?= $id ?></p><h1>Edit <?= e(trim(($record['FirstName'] ?? '') . ' ' . $record['LastName'])) ?></h1><p>Only the five selected fields below can be changed. The artist ID and linked paintings remain unchanged.</p></div></section>
<?php if ($errors): ?><div class="notice error" role="alert"><p>Please correct the following:</p><ul><?php foreach ($errors as $field => $error): ?><li><a href="<?= $field === 'form' ? '#artist-form' : '#' . e($field) ?>"><?= e($error) ?></a></li><?php endforeach; ?></ul></div><?php endif; ?>
<form class="add-form" id="artist-form" method="post" action="artist-edit.php?id=<?= $id ?>" data-unsaved-warning data-has-unsaved-values="<?= $errors ? 'true' : 'false' ?>">
<input type="hidden" name="csrf" value="<?= e(token()) ?>"><div class="form-grid">
<div><label for="FirstName">First name</label><input id="FirstName" name="FirstName" maxlength="50" value="<?= e($values['FirstName']) ?>" aria-describedby="FirstName-help FirstName-error" <?= isset($errors['FirstName']) ? 'aria-invalid="true"' : '' ?>><p id="FirstName-help" class="form-note">For example, "Claude" or "Mary".</p><p id="FirstName-error" class="field-error"><?= e($errors['FirstName'] ?? '') ?></p></div>
<div><label for="LastName">Last name *</label><input id="LastName" name="LastName" maxlength="50" required value="<?= e($values['LastName']) ?>" aria-describedby="LastName-help LastName-error" <?= isset($errors['LastName']) ? 'aria-invalid="true"' : '' ?>><p id="LastName-help" class="form-note">Use the artist's recognized family name.</p><p id="LastName-error" class="field-error"><?= e($errors['LastName'] ?? '') ?></p></div>
<div><label for="Nationality">Nationality</label><input id="Nationality" name="Nationality" maxlength="50" value="<?= e($values['Nationality']) ?>" aria-describedby="Nationality-error" <?= isset($errors['Nationality']) ? 'aria-invalid="true"' : '' ?>><p id="Nationality-error" class="field-error"><?= e($errors['Nationality'] ?? '') ?></p></div>
<div><label for="YearOfBirth">Year of birth</label><input id="YearOfBirth" name="YearOfBirth" type="number" min="-10000" max="2100" value="<?= e($values['YearOfBirth']) ?>" aria-describedby="YearOfBirth-help YearOfBirth-error" <?= isset($errors['YearOfBirth']) ? 'aria-invalid="true"' : '' ?>><p id="YearOfBirth-help" class="form-note">Whole year, from -10000 to 2100; leave blank when unknown.</p><p id="YearOfBirth-error" class="field-error"><?= e($errors['YearOfBirth'] ?? '') ?></p></div>
<div><label for="YearOfDeath">Year of death</label><input id="YearOfDeath" name="YearOfDeath" type="number" min="-10000" max="2100" value="<?= e($values['YearOfDeath']) ?>" aria-describedby="YearOfDeath-help YearOfDeath-error" <?= isset($errors['YearOfDeath']) ? 'aria-invalid="true"' : '' ?>><p id="YearOfDeath-help" class="form-note">Whole year; it must be equal to or after birth.</p><p id="YearOfDeath-error" class="field-error"><?= e($errors['YearOfDeath'] ?? '') ?></p></div>
</div><button class="primary">Save artist</button> <a href="objects.php?type=artists&amp;id=<?= $id ?>">Cancel</a></form>
<?php page_end(); ?>