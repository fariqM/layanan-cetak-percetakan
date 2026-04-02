<?php
session_start();
require_once 'koneksi.php';

// Jika sudah login, tendang kembali ke index (dashboard)
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
$error = "";

if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $valid_password = false;
        
        // Verifikasi standar Bcrypt
        if (password_verify($password, $user['password'])) {
            $valid_password = true;
        } 
        // Verifikasi transisi dari MD5 (Untuk akun super-admin pertama kali)
        elseif (md5($password) === $user['password']) {
            $valid_password = true;
            // Upgrade otomatis ke enkripsi Bcrypt yang lebih aman
            $new_hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = :hash WHERE id_user = :id")
                ->execute([':hash' => $new_hash, ':id' => $user['id_user']]);
        }

        if ($valid_password) {
            // Berikan tiket masuk (Session)
            $_SESSION['user_id'] = $user['id_user'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
            $_SESSION['role'] = $user['role'];
            
            header("Location: index.php");
            exit;
        } else {
            $error = "Password yang Anda masukkan salah.";
        }
    } else {
        $error = "Username tidak ditemukan.";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Aplikasi KTM</title>
    <link rel="icon" href="assets/uinsa.png" type="image/png">
    <style>
        /* 1. Body menggunakan Flexbox Column */
        body { 
            font-family: 'Segoe UI', Arial, sans-serif; 
            background-color: #f4f6f9; 
            margin: 0; 
            display: flex;
            flex-direction: column;
            min-height: 100vh; /* Memastikan minimal setinggi layar */
        }
        
        /* 2. Wrapper bertugas menengahkan kotak login & mendorong footer ke bawah */
        .login-wrapper {
            flex: 1; /* Mengisi sisa ruang kosong */
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px; /* Jarak aman untuk layar HP */
        }

        .login-box { 
            background: white; 
            padding: 40px 30px; 
            border-radius: 12px; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.05); 
            width: 100%; 
            max-width: 360px; 
            text-align: center; 
            box-sizing: border-box;
        }

        .login-logo {
            width: 120px; 
            height: auto;
            margin-bottom: 15px;
        }

        .login-box h2 { 
            margin: 0 0 25px; 
            color: #333; 
            font-size: 20px;
            font-weight: 700;
        }

        .form-group { 
            margin-bottom: 18px; 
            text-align: left; 
        }

        .form-group label { 
            display: block; 
            margin-bottom: 6px; 
            color: #555; 
            font-size: 13.5px; 
            font-weight: 600;
        }

        .form-control { 
            width: 100%; 
            padding: 12px; 
            border: 1px solid #ced4da; 
            border-radius: 6px; 
            box-sizing: border-box; 
            font-size: 15px;
            transition: 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: #0056b3;
            box-shadow: 0 0 0 3px rgba(0,86,179,0.1);
        }

        .btn-login { 
            width: 100%; 
            padding: 14px; 
            background-color: #0056b3; 
            color: white; 
            border: none; 
            border-radius: 6px; 
            font-weight: bold; 
            font-size: 15px;
            cursor: pointer; 
            transition: 0.2s; 
            margin-top: 10px;
        }

        .btn-login:hover { 
            background-color: #004494; 
            transform: translateY(-1px);
        }

        .alert { 
            background-color: #f8d7da; 
            color: #721c24; 
            padding: 12px; 
            border-radius: 6px; 
            margin-bottom: 20px; 
            font-size: 13.5px; 
            border: 1px solid #f5c6cb;
        }

        /* 3. Footer menempel di bawah tanpa absolute */
        .login-footer {
            text-align: center;
            color: #6c757d;
            font-size: 13px;
            padding: 20px 10px;
            margin-top: auto; /* Mendorong otomatis ke batas bawah layar */
        }
    </style>
</head>
<body>
    
    <div class="login-wrapper">
        <div class="login-box">
            <img src="assets/uinsa_press.png" alt="Logo UINSA Press" class="login-logo" onerror="this.src='assets/uinsa.png'">
            <h2>Aplikasi Layanan KTM</h2>
            
            <?php if ($error) echo "<div class='alert'>$error</div>"; ?>
            
            <form action="login.php" method="POST">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" class="form-control" required autofocus autocomplete="off">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" name="login" class="btn-login">Masuk ke Sistem</button>
            </form>
        </div>
    </div>

    <div class="login-footer">
        &copy; <?= date('Y'); ?> UPT Percetakan - UIN Sunan Ampel Surabaya.<br>Hak Cipta Dilindungi.
    </div>

</body>
</html>