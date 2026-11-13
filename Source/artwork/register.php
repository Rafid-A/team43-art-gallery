<?php
require_once __DIR__ . '/functions.php';

if ($user = current_user())
    redirect(home_url($user));

$errors = [];
$values = ['forename' => '', 'surname' => '', 'userid' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    $pdo = db();
    require_account_schema($pdo);

    foreach ($values as $name => $value) $values[$name] = input($name);
    $password = input('password', false);
    $confirm = input('confirm', false);

    foreach (['forename' => 'First name', 'surname' => 'Last name'] as $name => $label) {
        $length = text_length($values[$name]);
        if ($values[$name] === '') $errors[$name] = $label . ' is required.';
        elseif ($length === false || preg_match('/\p{Cc}/u', $values[$name])) $errors[$name] = $label . ' contains characters that cannot be saved.';
        elseif ($length > 32) $errors[$name] = $label . ' must be at most 32 characters.';
    }
    if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/D', $values['userid']))
        $errors['userid'] = 'User ID must be 3 to 32 letters, numbers, dots, dashes or underscores, for example jsmith.';
    // bcrypt reads at most 72 bytes; a longer password would be silently cut.
    if (strlen($password) < 8 || strlen($password) > 72)
        $errors['password'] = 'Password must be 8 to 72 characters.';
    elseif (str_contains($password, "\0"))
        $errors['password'] = 'Password cannot contain NUL (null) bytes. Choose a different password.';
    elseif (!hash_equals($password, $confirm))
        $errors['confirm'] = 'The passwords do not match.';

    if (!$errors) {
        try {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            // The role is fixed here; nothing in the request can choose it.
            $stmt = $pdo->prepare(
                "INSERT INTO art_users (forename, surname, username, password, role, status)
                 VALUES (?, ?, ?, ?, 'client', 'active')"
            );
            $stmt->execute([$values['forename'], $values['surname'], $values['userid'], $passwordHash]);
            flash('Your client account ' . $values['userid'] . ' is ready. Log in to view the collection.');
            redirect('art-login.php');
        } catch (Error $ex) {
            // Hashing can fail without a database error. Never log password data.
            error_log('Registration password hashing failed.');
            $errors['password'] = 'The password could not be secured. Please try a different password or try again later.';
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') $errors['userid'] = 'That user ID is already taken. Choose another.';
            else {
                error_log($ex->getMessage());
                $errors['form'] = 'The account could not be created. Please try again.';
            }
        }
    }
    http_response_code(422);
}

page_start('Create an account');
?>
<section class="login-layout">
    <div class="login-intro">
        <img src="images/art/works/square-medium/019130.jpg"
             alt="A painting of purple irises" width="647" height="500">
    </div>

    <div class="login-form">
        <h1>Create a client account</h1>
        <p>Clients can browse the whole collection and view their profile. Fields marked * are required.</p>
        <?php notices(); ?>
        <?php if ($errors): ?><div class="notice error" role="alert"><p>Please correct the following:</p><ul><?php foreach ($errors as $field => $error): ?><li><a href="#<?= e($field === 'form' ? 'forename' : $field) ?>"><?= e($error) ?></a></li><?php endforeach; ?></ul></div><?php endif; ?>

        <form action="register.php" method="post" data-submitting-label="Creating account…">
            <input type="hidden" name="csrf" value="<?= e(token()) ?>">
            <?php foreach (['forename' => ['First name *', 'given-name', 32, 'For example, Mary'], 'surname' => ['Last name *', 'family-name', 32, 'For example, Cassatt'], 'userid' => ['User ID *', 'username', 32, '3 to 32 letters, numbers, dots, dashes or underscores, for example mcassatt']] as $name => [$label, $autocomplete, $max, $hint]): ?>
            <label for="<?= $name ?>"><?= e($label) ?></label>
            <input id="<?= $name ?>" name="<?= $name ?>" maxlength="<?= $max ?>" value="<?= e($values[$name]) ?>"
                   autocomplete="<?= $autocomplete ?>" required aria-describedby="<?= $name ?>-hint <?= $name ?>-error"
                   <?= $name === 'userid' ? 'pattern="[A-Za-z0-9._\-]{3,32}" autocapitalize="none" spellcheck="false"' : '' ?>
                   <?= isset($errors[$name]) ? 'aria-invalid="true"' : '' ?>>
            <p class="hint" id="<?= $name ?>-hint"><?= e($hint) ?></p>
            <p class="field-error" id="<?= $name ?>-error"><?= e($errors[$name] ?? '') ?></p>
            <?php endforeach; ?>

            <label for="password">Password *</label>
            <input id="password" name="password" type="password" minlength="8" maxlength="72"
                   autocomplete="new-password" required aria-describedby="password-hint password-error"
                   <?= isset($errors['password']) ? 'aria-invalid="true"' : '' ?>>
            <p class="hint" id="password-hint">8 to 72 characters.</p>
            <p class="field-error" id="password-error"><?= e($errors['password'] ?? '') ?></p>

            <label for="confirm">Confirm password *</label>
            <input id="confirm" name="confirm" type="password" minlength="8" maxlength="72"
                   autocomplete="new-password" required aria-describedby="confirm-error"
                   <?= isset($errors['confirm']) ? 'aria-invalid="true"' : '' ?>>
            <p class="field-error" id="confirm-error"><?= e($errors['confirm'] ?? '') ?></p>

            <button class="primary full">Create account</button>
        </form>
        <p>Already have an account? <a href="art-login.php">Log in</a></p>
    </div>
</section>
<?php page_end(); ?>
