<?php
require_once dirname(__DIR__, 2) . '/includes/app_init.php';

if (auth_check()) {
    redirect(auth_dashboard_path());
}

$errors = [];
$email  = '';

if (is_post()) {
    csrf_require();
    $email    = post_string('email');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    $errors = validate(['email' => $email, 'password' => $password], [
        'email'    => 'required|email|max:190',
        'password' => 'required|max:255',
    ]);

    if (!$errors) {
        [$ok, $error] = auth_attempt($email, $password);
        if ($ok) {
            redirect(auth_dashboard_path());
        }
        $errors['form'] = $error;
    }
}

$pageTitle = 'Login';
$layout    = 'guest';
require ICARS_ROOT . '/components/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3 text-center">Sign in to <?= e(config('app.name')) ?></h1>

                <?php if (!empty($errors['form'])): ?>
                    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
                <?php endif; ?>

                <form method="post" action="<?= e(url('auth/login.php')) ?>" class="needs-validation" novalidate>
                    <?= csrf_field() ?>
                    <?= form_field('email', 'Email', 'email', $email, $errors['email'] ?? null, 'required|email|max:190', ['maxlength' => 190, 'autofocus' => true, 'autocomplete' => 'username']) ?>
                    <?= form_field('password', 'Password', 'password', '', $errors['password'] ?? null, 'required', ['maxlength' => 255, 'autocomplete' => 'current-password']) ?>
                    <button type="submit" class="btn btn-primary w-100" data-loading-text="Signing in…">Login</button>
                </form>
                <p class="text-center small mt-3 mb-0">No account? <a href="<?= e(url('auth/register.php')) ?>">Register</a></p>
            </div>
        </div>
    </div>
</div>
<?php require ICARS_ROOT . '/components/footer.php'; ?>
