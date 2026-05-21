<?php
require_once 'config.php';

// Jika sudah login, redirect ke dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] == 'admin') {
        header("Location: dashboard-admin.php");
    } elseif ($_SESSION['role'] == 'atasan') {
        header("Location: dashboard-atasan.php");
    } else {
        header("Location: dashboard-karyawan.php");
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    // Validasi input tidak kosong
    if (empty($username) || empty($password)) {
        $error = 'Username dan password wajib diisi!';
    } else {
        // Gunakan prepared statement untuk keamanan
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            
            // Verifikasi password
            if (password_verify($password, $user['password'])) {
                // Set session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['nama'] = $user['nama'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['foto'] = $user['foto'] ?? 'default.png';
                
                // Redirect berdasarkan role
                if ($user['role'] == 'admin') {
                    header("Location: dashboard-admin.php");
                } elseif ($user['role'] == 'atasan') {
                    header("Location: dashboard-atasan.php");
                } else {
                    header("Location: dashboard-karyawan.php");
                }
                exit;
                
            } else {
                $error = 'Password yang Anda masukkan salah!';
            }
        } else {
            $error = 'Username tidak ditemukan!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Request System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #10b981 0%, #059669 50%, #047857 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            background: rgba(255,255,255,0.95);
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.25);
            width: 100%;
            max-width: 420px;
            padding: 40px;
            backdrop-filter: blur(10px);
            animation: slideUp 0.6s ease-out;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo i {
            font-size: 60px;
            color: #10b981;
        }
        
        .logo h1 {
            color: #1f2937;
            font-size: 24px;
            margin-top: 10px;
            font-weight: 700;
        }
        
        .logo p {
            color: #6b7280;
            font-size: 14px;
            margin-top: 5px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #374151;
            font-weight: 600;
            font-size: 14px;
        }
        
        .input-group {
            position: relative;
        }
        
        .input-group i.icon-left {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 16px;
        }
        
        .input-group input {
            width: 100%;
            padding: 14px 45px 14px 45px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s;
            background: #f9fafb;
        }
        
        .input-group input:focus {
            outline: none;
            border-color: #10b981;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(16,185,129,0.1);
        }
        
        .input-group .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            cursor: pointer;
            font-size: 16px;
            transition: color 0.3s;
            padding: 5px;
        }
        
        .input-group .toggle-password:hover {
            color: #10b981;
        }
        
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(16,185,129,0.3);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .error-msg {
            background: #fef2f2;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-left: 4px solid #dc2626;
            animation: shake 0.5s ease-in-out;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        .loading {
            display: none;
            text-align: center;
            margin-top: 10px;
            color: #6b7280;
            font-size: 14px;
        }
        
        .loading.show {
            display: block;
        }
        
        .footer-text {
            text-align: center;
            margin-top: 25px;
            color: #6b7280;
            font-size: 13px;
        }
        
        @media (max-width: 480px) {
            .login-container { 
                padding: 30px 20px; 
                margin: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <i class="fas fa-building"></i>
            <h1>Request System</h1>
            <p>Sistem Manajemen Perusahaan</p>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="error-msg">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" id="loginForm" onsubmit="showLoading()">
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-group">
                    <i class="fas fa-user icon-left"></i>
                    <input type="text" name="username" id="username" placeholder="Masukkan username" required autocomplete="username">
                </div>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-group">
                    <i class="fas fa-lock icon-left"></i>
                    <input type="password" name="password" id="password" placeholder="Masukkan password" required autocomplete="current-password">
                    <i class="fas fa-eye toggle-password" onclick="togglePassword()" title="Lihat password"></i>
                </div>
            </div>
            <button type="submit" class="btn-login" id="btnLogin">
                <i class="fas fa-sign-in-alt"></i> Masuk
            </button>
            <div class="loading" id="loading">
                <i class="fas fa-spinner fa-spin"></i> Memproses login...
            </div>
        </form>
        
        <div class="footer-text">
            <p>Request System &copy; <?= date('Y') ?></p>
        </div>
    </div>
    
    <script>
        function togglePassword() {
            const pass = document.getElementById('password');
            const icon = document.querySelector('.toggle-password');
            if (pass.type === 'password') {
                pass.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                pass.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        function showLoading() {
            document.getElementById('loading').classList.add('show');
            document.getElementById('btnLogin').disabled = true;
        }
        
        // Enter key support
        document.getElementById('password').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('loginForm').submit();
            }
        });
    </script>
</body>
</html>