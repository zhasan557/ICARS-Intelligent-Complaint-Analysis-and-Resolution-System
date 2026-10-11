<?php
require_once dirname(__DIR__, 2) . '/includes/app_init.php';

if (auth_check()) {
    redirect(auth_dashboard_path());
}

$errors = [];
$in     = ['full_name' => '', 'email' => '', 'phone' => ''];

if (is_post()) {
    csrf_require();
    $in = user_clean_input($_POST);
    $errors = user_validate($in, 'register');

    if (!$errors) {
        // Public registration can ONLY create ordinary users; any posted role/is_active is ignored.
        $data = array_merge($in, ['role' => ROLE_USER, 'is_active' => true, 'department_id' => null, 'job_title' => '']);
        try {
            $id = user_create($data);
            auth_login_user(user_find($id));
            flash_set('success', 'Welcome to ' . config('app.name') . ', ' . strtok($data['full_name'], ' ') . '! Your account has been created.');
            redirect(auth_dashboard_path());
        } catch (ValidationException $ex) {
            $errors = $ex->errors;
        }
    }
}

$pageTitle = 'Create account';
$layout    = 'guest';
require ICARS_ROOT . '/components/header.php';
$min = (int)config('security.password_min_length', 8);
?>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3 text-center">Create your account</h1>
                <form method="post" action="<?= e(url('auth/register.php')) ?>" class="needs-validation" novalidate>
                    <?= csrf_field() ?>
                    <?= form_field('full_name', 'Full name', 'text', $in['full_name'], $errors['full_name'] ?? null, 'required|min:2|max:120|name', ['maxlength' => 120, 'autocomplete' => 'name', 'autofocus' => true]) ?>
                    <?= form_field('email', 'Email', 'email', $in['email'], $errors['email'] ?? null, 'required|email|max:190', ['maxlength' => 190, 'autocomplete' => 'email']) ?>
                    <?= form_field('phone', 'Phone (optional)', 'tel', $in['phone'], $errors['phone'] ?? null, 'phone|max:20', ['maxlength' => 20, 'autocomplete' => 'tel']) ?>
                    <?= form_field('password', 'Password', 'password', '', $errors['password'] ?? null, 'required|password|max_bytes:72', ['autocomplete' => 'new-password'], "At least $min characters, with a letter and a number.") ?>
                    <?= form_field('password_confirm', 'Confirm password', 'password', '', $errors['password_confirm'] ?? null, 'required|same:password', ['autocomplete' => 'new-password']) ?>
                    <button type="submit" class="btn btn-primary w-100" data-loading-text="Creating account…">Register</button>
                </form>
                <p class="text-center small mt-3 mb-0">Already registered? <a href="<?= e(url('auth/login.php')) ?>">Login</a></p>
            </div>
        </div>
    </div>
</div>
<?php require ICARS_ROOT . '/components/footer.php'; ?>
