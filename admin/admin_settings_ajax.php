<?php
// admin/admin_settings_ajax.php
// ============================================================
//  Personal Settings AJAX Backend
//  Handles: profile info, password, signature, profile photo
//  Access : any logged-in admin (captain / secretary / lupon / kagawad)
//  Scope  : the logged-in admin can only modify their OWN record.
// ============================================================
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$admin_id = (int)$_SESSION['user_id'];
$action   = $_POST['action'] ?? $_GET['action'] ?? '';

/* ---------------- Fetch current admin record ---------------- */
$s = mysqli_prepare($conn,
    "SELECT id, username, email, full_name, admin_role, is_active,
            suffix, birth_date, birth_place, gender, civil_status,
            religion, occupation, profile_image, profile_photo,
            phone_number, signature_path, signature_uploaded_at
     FROM admin WHERE id = ?");
mysqli_stmt_bind_param($s, "i", $admin_id);
mysqli_stmt_execute($s);
$me = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
mysqli_stmt_close($s);

if (!$me) {
    echo json_encode(['success' => false, 'message' => 'Admin not found.']);
    exit;
}

/* ============================================================
   Helper: safe delete of a file relative to project root
   ============================================================ */
function br_safe_unlink($rel) {
    if (!$rel) return;
    $abs = __DIR__ . '/../' . $rel;
    if (is_file($abs)) @unlink($abs);
}

/* ============================================================
   Helper: sanitize a filename (keep extension)
   ============================================================ */
function br_sanitize_ext($name, $allowed) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($ext, $allowed, true) ? $ext : null;
}

/* ============================================================
   GET: get_profile
   ============================================================ */
if ($action === 'get_profile') {
    // Never send password hash back to the client
    $public = $me;
    unset($public['password']);

    // Rewrite signature/profile photo paths so JS can use them directly
    $base = '/BRITE/'; // adjust if your app is under a different subpath

    echo json_encode([
        'success'       => true,
        'profile'       => $public,
        'signature_url' => !empty($me['signature_path']) ? $base . $me['signature_path'] : null,
        'photo_url'     => !empty($me['profile_image'])  ? $base . $me['profile_image']  : null,
    ]);
    exit;
}

/* ============================================================
   POST: update_profile
   ============================================================ */
if ($action === 'update_profile') {
    $full_name    = trim($_POST['full_name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $suffix       = trim($_POST['suffix'] ?? '');
    $birth_date   = trim($_POST['birth_date'] ?? '');
    $birth_place  = trim($_POST['birth_place'] ?? '');
    $gender       = trim($_POST['gender'] ?? '');
    $civil_status = trim($_POST['civil_status'] ?? '');
    $religion     = trim($_POST['religion'] ?? '');
    $occupation   = trim($_POST['occupation'] ?? '');

    if ($full_name === '') {
        echo json_encode(['success' => false, 'message' => 'Full name is required.']);
        exit;
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'A valid email is required.']);
        exit;
    }

    // Whitelist enum values
    $allowedGender = ['male','female','other',''];
    if (!in_array($gender, $allowedGender, true)) $gender = '';

    $allowedCivil = ['single','married','widowed','divorced','separated',''];
    if (!in_array($civil_status, $allowedCivil, true)) $civil_status = '';

    // Birthdate can be empty or a Y-m-d string
    if ($birth_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
        $birth_date = '';
    }

    // Email uniqueness (other admins)
    $chk = mysqli_prepare($conn, "SELECT id FROM admin WHERE email = ? AND id != ? LIMIT 1");
    mysqli_stmt_bind_param($chk, "si", $email, $admin_id);
    mysqli_stmt_execute($chk);
    $dupe = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
    mysqli_stmt_close($chk);
    if ($dupe) {
        echo json_encode(['success' => false, 'message' => 'That email is already used by another account.']);
        exit;
    }

    $up = mysqli_prepare($conn,
        "UPDATE admin SET
            full_name = ?,
            email = ?,
            phone_number = ?,
            suffix = ?,
            birth_date = ?,
            birth_place = ?,
            gender = ?,
            civil_status = ?,
            religion = ?,
            occupation = ?
         WHERE id = ?");
    if (!$up) {
        echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . mysqli_error($conn)]);
        exit;
    }
    mysqli_stmt_bind_param($up, "ssssssssssi",
        $full_name, $email, $phone_number,
        $suffix, $birth_date, $birth_place,
        $gender, $civil_status, $religion, $occupation,
        $admin_id);
    $ok = mysqli_stmt_execute($up);
    $err = $ok ? null : mysqli_stmt_error($up);
    mysqli_stmt_close($up);

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $err]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Profile updated.',
        'profile' => [
            'full_name'    => $full_name,
            'email'        => $email,
            'phone_number' => $phone_number,
        ],
    ]);
    exit;
}

/* ============================================================
   POST: change_password
   ============================================================ */
if ($action === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($current === '' || $new === '' || $confirm === '') {
        echo json_encode(['success' => false, 'message' => 'All password fields are required.']);
        exit;
    }
    if ($new !== $confirm) {
        echo json_encode(['success' => false, 'message' => 'New password and confirmation do not match.']);
        exit;
    }
    if (strlen($new) < 8) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters.']);
        exit;
    }

    // Verify current password
    $s = mysqli_prepare($conn, "SELECT password FROM admin WHERE id = ?");
    mysqli_stmt_bind_param($s, "i", $admin_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);

    if (!$row || !password_verify($current, $row['password'])) {
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
        exit;
    }

    $hash = password_hash($new, PASSWORD_BCRYPT);

    $up = mysqli_prepare($conn, "UPDATE admin SET password = ? WHERE id = ?");
    mysqli_stmt_bind_param($up, "si", $hash, $admin_id);
    $ok  = mysqli_stmt_execute($up);
    $err = $ok ? null : mysqli_stmt_error($up);
    mysqli_stmt_close($up);

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Failed to update password: ' . $err]);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
    exit;
}

/* ============================================================
   POST: upload_signature
   Expects a PNG (possibly with alpha) already cleaned client-side.
   Max 2 MB.
   ============================================================ */
if ($action === 'upload_signature') {
    if (empty($_FILES['signature']) || $_FILES['signature']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file received or upload error.']);
        exit;
    }

    $f = $_FILES['signature'];
    if ($f['size'] > 2 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Signature file must be 2 MB or smaller.']);
        exit;
    }

    $ext = br_sanitize_ext($f['name'], ['png','jpg','jpeg','webp']);
    if ($ext === null) {
        echo json_encode(['success' => false, 'message' => 'Only PNG, JPG, or WEBP signatures are allowed.']);
        exit;
    }

    // Basic MIME check
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
    $mime  = $finfo ? finfo_file($finfo, $f['tmp_name']) : ($f['type'] ?? '');
    if ($finfo) finfo_close($finfo);
    $allowedMime = ['image/png','image/jpeg','image/jpg','image/webp'];
    if (!in_array($mime, $allowedMime, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid image MIME type.']);
        exit;
    }

    $dir = __DIR__ . '/../uploads/signatures';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);

    $safeName = 'signature_admin_' . $admin_id . '_' . time() . '.' . $ext;
    $absPath  = $dir . '/' . $safeName;
    $relPath  = 'uploads/signatures/' . $safeName;

    if (!move_uploaded_file($f['tmp_name'], $absPath)) {
        echo json_encode(['success' => false, 'message' => 'Could not save the signature file.']);
        exit;
    }

    // Delete the previous file (if any)
    br_safe_unlink($me['signature_path']);

    // Persist new path
    $up = mysqli_prepare($conn,
        "UPDATE admin SET signature_path = ?, signature_uploaded_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($up, "si", $relPath, $admin_id);
    $ok  = mysqli_stmt_execute($up);
    $err = $ok ? null : mysqli_stmt_error($up);
    mysqli_stmt_close($up);

    if (!$ok) {
        @unlink($absPath);
        echo json_encode(['success' => false, 'message' => 'DB update failed: ' . $err]);
        exit;
    }

    echo json_encode([
        'success'       => true,
        'message'       => 'Signature uploaded.',
        'signature_url' => '/BRITE/' . $relPath,
        'signature_path'=> $relPath,
    ]);
    exit;
}

/* ============================================================
   POST: remove_signature
   ============================================================ */
if ($action === 'remove_signature') {
    br_safe_unlink($me['signature_path']);

    $up = mysqli_prepare($conn,
        "UPDATE admin SET signature_path = NULL, signature_uploaded_at = NULL WHERE id = ?");
    mysqli_stmt_bind_param($up, "i", $admin_id);
    $ok = mysqli_stmt_execute($up);
    mysqli_stmt_close($up);

    echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Signature removed.' : 'Failed.']);
    exit;
}

/* ============================================================
   POST: upload_profile_photo
   Max 5 MB.
   ============================================================ */
if ($action === 'upload_profile_photo') {
    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file received or upload error.']);
        exit;
    }

    $f = $_FILES['photo'];
    if ($f['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Photo must be 5 MB or smaller.']);
        exit;
    }

    $ext = br_sanitize_ext($f['name'], ['png','jpg','jpeg','webp']);
    if ($ext === null) {
        echo json_encode(['success' => false, 'message' => 'Only PNG, JPG, or WEBP photos are allowed.']);
        exit;
    }

    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
    $mime  = $finfo ? finfo_file($finfo, $f['tmp_name']) : ($f['type'] ?? '');
    if ($finfo) finfo_close($finfo);
    $allowedMime = ['image/png','image/jpeg','image/jpg','image/webp'];
    if (!in_array($mime, $allowedMime, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid image MIME type.']);
        exit;
    }

    $dir = __DIR__ . '/../uploads/profiles';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);

    $safeName = 'profile_admin_' . $admin_id . '_' . time() . '.' . $ext;
    $absPath  = $dir . '/' . $safeName;
    $relPath  = 'uploads/profiles/' . $safeName;

    if (!move_uploaded_file($f['tmp_name'], $absPath)) {
        echo json_encode(['success' => false, 'message' => 'Could not save the photo.']);
        exit;
    }

    br_safe_unlink($me['profile_image']);

    $up = mysqli_prepare($conn,
        "UPDATE admin SET profile_image = ? WHERE id = ?");
    mysqli_stmt_bind_param($up, "si", $relPath, $admin_id);
    $ok  = mysqli_stmt_execute($up);
    $err = $ok ? null : mysqli_stmt_error($up);
    mysqli_stmt_close($up);

    if (!$ok) {
        @unlink($absPath);
        echo json_encode(['success' => false, 'message' => 'DB update failed: ' . $err]);
        exit;
    }

    echo json_encode([
        'success'   => true,
        'message'   => 'Profile photo uploaded.',
        'photo_url' => '/BRITE/' . $relPath,
        'photo_path'=> $relPath,
    ]);
    exit;
}

/* ============================================================
   POST: remove_profile_photo
   ============================================================ */
if ($action === 'remove_profile_photo') {
    br_safe_unlink($me['profile_image']);

    $up = mysqli_prepare($conn,
        "UPDATE admin SET profile_image = NULL WHERE id = ?");
    mysqli_stmt_bind_param($up, "i", $admin_id);
    $ok = mysqli_stmt_execute($up);
    mysqli_stmt_close($up);

    echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Profile photo removed.' : 'Failed.']);
    exit;
}

/* ============================================================
   Fallback
   ============================================================ */
echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);