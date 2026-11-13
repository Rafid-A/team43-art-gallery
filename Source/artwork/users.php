<?php
require_once __DIR__ . '/library.php';
require_admin();
$me = current_user();

// The actor and every active administrator are locked before the target row,
// so concurrent changes cannot remove the last administrator or act on a
// stale role. An administrator cannot change their own account here.
function change_account($actor, $username, $action, $value)
{
    $changes = ['role' => ['client', 'admin'], 'status' => ['active', 'disabled'], 'delete' => ['']];
    if (!isset($changes[$action]) || !in_array($value, $changes[$action], true))
        throw new DomainException('Choose a valid account change. Nothing was changed.');

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $admins = rows("SELECT username FROM art_users WHERE role = 'admin' AND status = 'active' ORDER BY username FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array($actor, $admins, true))
            throw new DomainException('Your account is no longer an active administrator. Nothing was changed.');
        $account = rows('SELECT username, role, status FROM art_users WHERE username = ? FOR UPDATE', [$username])->fetch();
        if (!$account) throw new DomainException('That account no longer exists. Nothing was changed.');
        if ($account['username'] === $actor)
            throw new DomainException('You cannot change or delete your own account. Ask another administrator.');

        $activeAdmin = $account['role'] === 'admin' && $account['status'] === 'active';
        $removesAdmin = $action === 'delete' || ($action === 'role' && $value === 'client') || ($action === 'status' && $value === 'disabled');
        if ($activeAdmin && $removesAdmin && count($admins) < 2)
            throw new DomainException('At least one active administrator must remain. Nothing was changed.');

        $name = $account['username'];
        if ($action === 'delete') {
            rows('DELETE FROM art_users WHERE username = ?', [$name]);
            $message = 'Deleted the account ' . $name . '.';
        } elseif ($account[$action] === $value) {
            $message = 'No change: ' . $name . ' is already ' . ($action === 'role' ? 'a' . ($value === 'admin' ? 'n administrator' : ' client') : $value) . '.';
        } else {
            rows("UPDATE art_users SET $action = ? WHERE username = ?", [$value, $name]);
            $message = $action === 'role'
                ? $name . ' is now ' . ($value === 'admin' ? 'an administrator' : 'a client') . '.'
                : ($value === 'disabled' ? 'Disabled ' . $name . '. Their open sessions end on their next request.' : 'Enabled ' . $name . '.');
        }
        $pdo->commit();
        return $message;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    try {
        flash(change_account($me['username'], input('username'), input('action'), input('value')));
    } catch (DomainException $ex) {
        flash($ex->getMessage(), 'error');
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        flash('The account could not be changed. Nothing was saved.', 'error');
    }
    redirect('users.php');
}

$deleting = null;
if (get_text('delete') !== '') {
    $deleting = rows('SELECT forename, surname, username, role FROM art_users WHERE username = ?', [get_text('delete')])->fetch();
    if (!$deleting) http_response_code(404);
}
$accounts = rows("SELECT forename, surname, username, role, status FROM art_users ORDER BY role = 'admin' DESC, username")->fetchAll();
page_start('Accounts');
?>
<div class="breadcrumbs"><a href="admin.php">Administration</a><span>/</span>Accounts</div>
<?php if (get_text('delete') !== ''): ?>
<section class="confirmation"><p class="eyebrow">Account management</p><h1><?= $deleting ? 'Delete this account?' : 'Account not found' ?></h1>
<?php if ($deleting && $deleting['username'] === $me['username']): ?><p>You cannot delete your own account. Ask another administrator.</p><a href="users.php">Return to accounts</a>
<?php elseif ($deleting): ?><h2><?= e($deleting['username']) ?> · <?= e($deleting['forename'] . ' ' . $deleting['surname']) ?></h2>
<p>This <?= e(strtolower(role_label($deleting['role']))) ?> account will be removed and its open sessions end on their next request. Gallery records are not changed. To keep the account but block sign-in, disable it instead.</p>
<form action="users.php" method="post"><input type="hidden" name="csrf" value="<?= e(token()) ?>"><input type="hidden" name="username" value="<?= e($deleting['username']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="value" value=""><button class="danger">Delete account</button> <a href="users.php">Keep account</a></form>
<?php else: ?><p>It may already have been deleted.</p><a href="users.php">Return to accounts</a><?php endif; ?></section>
<?php else: ?>
<section class="page-heading"><div><p class="eyebrow">Account management</p><h1>Accounts</h1><p>New registrations are always clients. Promote a client to give them administrator access. You cannot change your own account, and at least one active administrator always remains.</p></div></section>
<?php notices(); ?>
<div class="directory">
<?php foreach ($accounts as $a): $self = $a['username'] === $me['username']; ?>
<div class="directory-row"><div><h2><?= e($a['username']) ?><?= $self ? ' <small>(you)</small>' : '' ?></h2><p><?= e($a['forename'] . ' ' . $a['surname']) ?> · <?= e(role_label($a['role'])) ?><?= $a['status'] === 'disabled' ? ' · Disabled' : '' ?></p></div>
<?php if (!$self): ?><span class="account-actions">
<form action="users.php" method="post"><input type="hidden" name="csrf" value="<?= e(token()) ?>"><input type="hidden" name="username" value="<?= e($a['username']) ?>"><input type="hidden" name="action" value="role"><input type="hidden" name="value" value="<?= $a['role'] === 'admin' ? 'client' : 'admin' ?>"><button class="quiet"><?= $a['role'] === 'admin' ? 'Make client' : 'Make administrator' ?><span class="visually-hidden"> <?= e($a['username']) ?></span></button></form>
<form action="users.php" method="post"><input type="hidden" name="csrf" value="<?= e(token()) ?>"><input type="hidden" name="username" value="<?= e($a['username']) ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="value" value="<?= $a['status'] === 'active' ? 'disabled' : 'active' ?>"><button class="quiet"><?= $a['status'] === 'active' ? 'Disable' : 'Enable' ?><span class="visually-hidden"> <?= e($a['username']) ?></span></button></form>
<a href="users.php?<?= e(http_build_query(['delete' => $a['username']])) ?>">Delete<span class="visually-hidden"> <?= e($a['username']) ?></span></a>
</span><?php endif; ?></div>
<?php endforeach; ?>
</div>
<?php endif; page_end(); ?>
