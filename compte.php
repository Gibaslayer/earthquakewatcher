<?php
/**
 * compte.php - User area: sign in, sign up, sign out, profile update and favourites list.
 *
 * Database usage (SQL):
 *   SELECT  - sign-in lookup, favourites list
 *   INSERT  - sign-up (utilisateurs)
 *   UPDATE  - profile name and password (utilisateurs)
 *   DELETE  - removing a favourite is done by api.php
 *
 * API usage: the favourite buttons on this page call api.php (see README and api.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/lang.php';

$pdo     = getPDO();
$error   = null;
$success = null;
$old     = ['email' => '', 'nom' => ''];   // values to show again after an error

if (!$pdo) {
    $error = t('db_err');
}
currentUserId($pdo);   // clears the session if the account no longer exists

/**
 * Returns the path of the failed-login counter file for an e-mail address.
 * The file name is a SHA-1 hash, so no personal data is written to disk.
 *
 * @param string $email E-mail address typed in the sign-in form.
 * @return string Full path of the counter file in the system temporary folder.
 */
function loginThrottleFile(string $email): string {
    return sys_get_temp_dir() . '/ew_login_' . sha1(mb_strtolower(trim($email))) . '.json';
}

/**
 * Stores the user in the session after a successful sign-in or sign-up.
 * The session id is regenerated to prevent session fixation.
 *
 * @param int    $id    Database id of the user.
 * @param string $name  Display name.
 * @param string $email E-mail address.
 */
function loginSession(int $id, string $name, string $email): void {
    session_regenerate_id(true);
    $_SESSION['user_id']    = $id;
    $_SESSION['user_name']  = $name;
    $_SESSION['user_email'] = $email;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    {
        try {
        $action = $_POST['form_action'] ?? '';

        // Sign out
        if ($action === 'logout') {
            unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_email']);
            session_regenerate_id(true);
            header('Location: compte.php');
            exit;
        }

        // INSERT: sign-up
        if ($action === 'register') {
            $name  = trim((string)($_POST['nom'] ?? ''));
            $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
            $email = $email ? mb_strtolower($email) : false;   // SQLite compares case-sensitively
            $pwd   = (string)($_POST['pwd'] ?? '');
            $old   = ['email' => (string)($_POST['email'] ?? ''), 'nom' => $name];
            $name  = mb_substr($name, 0, 80);
            if ($name === '') {
                $error = t('register_err_name');
            } elseif (!$email) {
                $error = t('login_err');
            } elseif (strlen($pwd) < 6) {
                $error = t('register_err_len');
            } elseif (strlen($pwd) > 72) {      // BCRYPT ignores anything after 72 bytes
                $error = t('pwd_too_long');
            } else {
                try {
                    $pdo->prepare('INSERT INTO utilisateurs (nom, email, mot_de_passe) VALUES (:n, :e, :p)')
                        ->execute([':n' => $name, ':e' => $email, ':p' => password_hash($pwd, PASSWORD_BCRYPT)]);
                    loginSession((int)$pdo->lastInsertId(), $name, $email);
                    header('Location: compte.php');
                    exit;
                } catch (PDOException $ex) {
                    $error = t('register_err_mail');   // e-mail already used (UNIQUE constraint)
                }
            }
        }

        // SELECT: sign-in
        if ($action === 'login') {
            $old['email'] = (string)($_POST['email'] ?? '');
            // Brute-force protection: 5 failures for the same e-mail = 60 s pause.
            // The counter is stored on the server, so clearing cookies does not bypass it.
            $throttle = loginThrottleFile($old['email']);
            $state    = is_file($throttle) ? (json_decode((string)file_get_contents($throttle), true) ?: []) : [];
            if (($state['until'] ?? 0) > time()) {
                $error = t('login_throttle');
            } else {
                $st = $pdo->prepare('SELECT id, nom, email, mot_de_passe FROM utilisateurs WHERE email = :e LIMIT 1');
                $st->execute([':e' => mb_strtolower(trim($old['email']))]);
                $user = $st->fetch();
                if ($user && password_verify((string)($_POST['pwd'] ?? ''), $user['mot_de_passe'])) {
                    @unlink($throttle);
                    loginSession((int)$user['id'], $user['nom'], $user['email']);
                    header('Location: compte.php');
                    exit;
                }
                $fails = ($state['fails'] ?? 0) + 1;
                $state = ['fails' => $fails >= 5 ? 0 : $fails, 'until' => $fails >= 5 ? time() + 60 : 0];
                file_put_contents($throttle, json_encode($state));
                $error = t('login_err');
            }
        }

        // UPDATE: name and/or password
        if ($action === 'update_profile' && !empty($_SESSION['user_id'])) {
            $name   = trim((string)($_POST['nom'] ?? ''));
            $newPwd = (string)($_POST['new_pwd'] ?? '');
            if ($name === '') {
                $error = t('register_err_name');
            } elseif ($newPwd !== '' && strlen($newPwd) < 6) {
                $error = t('register_err_len');
            } elseif (strlen($newPwd) > 72) {
                $error = t('pwd_too_long');
            } else {
                $name = mb_substr($name, 0, 80);
                if ($newPwd !== '') {
                    $pdo->prepare('UPDATE utilisateurs SET nom = :n, mot_de_passe = :p WHERE id = :id')
                        ->execute([':n' => $name, ':p' => password_hash($newPwd, PASSWORD_BCRYPT), ':id' => $_SESSION['user_id']]);
                } else {
                    $pdo->prepare('UPDATE utilisateurs SET nom = :n WHERE id = :id')
                        ->execute([':n' => $name, ':id' => $_SESSION['user_id']]);
                }
                $_SESSION['user_name'] = $name;
                $success = t('profile_updated');
            }
        }
        } catch (PDOException $ex) {
            $error = t('db_err');   // database locked or read-only
        }
    }
}

$userId    = $_SESSION['user_id'] ?? null;
$favorites = [];
if ($userId && $pdo) {
    try {
        $st = $pdo->prepare('SELECT seisme_id_externe AS id, lieu, magnitude, date_seisme AS date, latitude AS lat, longitude AS lon
                             FROM favoris WHERE utilisateur_id = :u ORDER BY date_ajout DESC, id DESC');
        $st->execute([':u' => $userId]);
        $favorites = $st->fetchAll();
    } catch (PDOException $ex) {
        $error = t('db_err');
    }
}

// Real connection state, shown in the status bar (one lightweight request).
$status = fetchEarthquakes(0.0, 1, $pdo)['status'];

pageHeader('compte', 'nav_account', $status);
?>
    <main class="container">
        <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-ok" role="status"><?= e($success) ?></div><?php endif; ?>

        <?php if (!$userId): ?>
            <div class="two-cols">
                <div class="panel-card">
                    <h2><?= e(t('tab_login')) ?></h2>
                    <form method="POST" action="compte.php" class="stack">
                        <input type="hidden" name="form_action" value="login">
                        <div class="form-group">
                            <label for="l_email"><?= e(t('email_label')) ?></label>
                            <input type="email" id="l_email" name="email" class="form-input" required autocomplete="username" value="<?= e(($_POST['form_action'] ?? '') === 'login' ? $old['email'] : '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="l_pwd"><?= e(t('password_label')) ?></label>
                            <input type="password" id="l_pwd" name="pwd" class="form-input" required autocomplete="current-password">
                        </div>
                        <button type="submit" class="btn-primary"><?= e(t('btn_login')) ?></button>
                    </form>
                </div>

                <div class="panel-card">
                    <h2><?= e(t('tab_register')) ?></h2>
                    <form method="POST" action="compte.php" class="stack">
                        <input type="hidden" name="form_action" value="register">
                        <div class="form-group">
                            <label for="r_nom"><?= e(t('name_label')) ?></label>
                            <input type="text" id="r_nom" name="nom" class="form-input" required maxlength="80" autocomplete="name" value="<?= e($old['nom']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="r_email"><?= e(t('email_label')) ?></label>
                            <input type="email" id="r_email" name="email" class="form-input" required autocomplete="email" value="<?= e(($_POST['form_action'] ?? '') === 'register' ? $old['email'] : '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="r_pwd"><?= e(t('password_label')) ?></label>
                            <input type="password" id="r_pwd" name="pwd" class="form-input" required minlength="6" maxlength="72" autocomplete="new-password">
                        </div>
                        <button type="submit" class="btn-primary"><?= e(t('btn_register')) ?></button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="panel-card row-between">
                <div>
                    <span class="muted small"><?= e(t('user_logged_as')) ?></span>
                    <h2><?= e($_SESSION['user_name']) ?> (<?= e($_SESSION['user_email']) ?>)</h2>
                </div>
                <form method="POST" action="compte.php">
                    <input type="hidden" name="form_action" value="logout">
                    <button type="submit" class="btn-secondary"><?= e(t('nav_logout')) ?></button>
                </form>
            </div>

            <div class="account-layout">
                <div class="panel-card">
                    <h3><?= e(t('profile_title')) ?></h3>
                    <form method="POST" action="compte.php" class="stack">
                        <input type="hidden" name="form_action" value="update_profile">
                        <div class="form-group">
                            <label for="p_nom"><?= e(t('name_label')) ?></label>
                            <input type="text" id="p_nom" name="nom" class="form-input" value="<?= e($_SESSION['user_name']) ?>" required maxlength="80">
                        </div>
                        <div class="form-group">
                            <label for="p_pwd"><?= e(t('new_pwd_opt')) ?></label>
                            <input type="password" id="p_pwd" name="new_pwd" class="form-input" minlength="6" maxlength="72" autocomplete="new-password">
                        </div>
                        <button type="submit" class="btn-primary"><?= e(t('update_profile')) ?></button>
                    </form>
                </div>

                <div class="panel-card">
                    <h3><?= e(t('my_favs_title')) ?> (<span id="favCount"><?= count($favorites) ?></span>)</h3>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th><?= e(t('mag_col')) ?></th>
                                    <th><?= e(t('place_col')) ?></th>
                                    <th><?= e(t('date_col')) ?></th>
                                    <th><?= e(t('action_col')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($favorites)): ?>
                                    <tr><td colspan="4" class="muted center"><?= e(t('no_favs_msg')) ?></td></tr>
                                <?php else: foreach ($favorites as $fav): ?>
                                    <tr data-id="<?= e($fav['id']) ?>">
                                        <td><span class="badge-mag <?= magClass((float)$fav['magnitude']) ?>">M <?= number_format((float)$fav['magnitude'], 1) ?></span></td>
                                        <td class="strong"><?= e($fav['lieu']) ?></td>
                                        <td><?= e($fav['date']) ?></td>
                                        <td>
                                            <button type="button" class="btn-fav btn-fav-action active"
                                                    data-id="<?= e($fav['id']) ?>"
                                                   ><?= e(t('btn_remove_fav')) ?></button>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
<?php pageFooter(); ?>
