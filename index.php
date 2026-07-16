<?php
session_start();
// Kama mtumiaji ameshajilogi, mpeleke moja kwa moja kwenye dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: " . ($_SESSION['role'] == 'admin' ? "dashboard_chaguo.php" : "user_dashboard.php"));
    exit();
}
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <title>Login System</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16.png">
    <link rel="apple-touch-icon" sizes="192x192" href="favicon-192.png">
    <style>    
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'DM Sans', sans-serif; }
        body { display: flex; justify-content: center; align-items: center; min-height: 100vh; background-image: linear-gradient(rgba(0,0,0,0.5)),url(beach5.jpg); background-repeat: no-repeat; background-position: center; background-size: cover; width: 100%; overflow: hidden; }

        .ring { position: relative; width: 500px; height: 500px; display: flex; justify-content: center; align-items: center; }
        .ring i { position: absolute; inset: 0;border: 2px solid var(--clr); transition: 0.5s;will-change: transform; }
        .ring i:nth-child(1){ border-radius: 38% 62% 63% 37% / 41% 44% 56% 59%; animation: animate 6s linear infinite; }
        .ring i:nth-child(2){ border-radius: 41% 44% 56% 59%/38% 62% 63% 37%; animation: animate 4s linear infinite; }
        .ring i:nth-child(3){ border-radius: 41% 44% 56% 59%/38% 62% 63% 37%; animation: animate2 10s linear infinite; }
        
        @keyframes animate { 0%{ transform: rotate(0deg); } 100%{ transform: rotate(360deg); } }
        @keyframes animate2{ 0%{ transform: rotate(360deg); } 100%{ transform: rotate(0deg); } }
        
        .login { position: relative; width: 350px; width: 300px; display: flex; justify-content: center; align-items: center; flex-direction: column; gap: 20px; }
        .login h2 { font-size: 2em; color: #fff; }
        .inputBx { position: relative; width: 100%; }
        .inputBx input { width: 100%; padding: 12px 40px 12px 20px; background: transparent; border: 2px solid #fff; border-radius: 40px; font-size: 1.1em; color: #fff; outline: none; }
        button { width: 100%; cursor: pointer; padding: 12px; border-radius: 40px; border: 2px solid #fff; background: #fff; font-weight: bold; font-size: 1em; }
        .links { width: 100%; display: flex; justify-content: space-between; padding: 0 20px; color: #fff; font-size: 14px; }
        .links span { cursor: pointer; }
        .hidden { display: none; }
        .toggle-password { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; width: 20px; height: 20px; fill: #fff; }
 
       .footer{bottom: 4px;text-align:center;padding:20px;font-size:11px;color:rgba(255,255,255,0.35);right: 0;left: 0;  z-index: 5;position: fixed;font-family:'Space Mono',monospace}

/* Inaficha jicho la kivinjari (Browser Native Password Toggle) */
input::-ms-reveal,
input::-ms-clear {
    display: none;
}

input::-webkit-credentials-auto-fill-button {
    visibility: hidden;
    position: absolute;
    right: 0;
}
.msg-box {
    background: rgba(255, 255, 255, 0.3); 
    backdrop-filter: blur(15px); 
    padding: 15px; 
    border-radius: 15px; 
    border: 1px solid rgba(255, 255, 255, 0.4); 
    color: #fa0707; 
    text-align: center; 
    margin-bottom: 20px;
    font-size: 14px;
    
    /* HAPA NDIPO UPACHA WA KUIFANYA IWE MBELE */
    position: absolute; /* Inaiweka juu ya vitu vingine */
    top: -20px;         /* Inaipeleka juu ya fomu */
    width: 100%;
    z-index: 10;        /* Inahakikisha iko juu ya kila kitu */
    box-shadow: 0 4px 20px rgba(0,0,0,0.5);
    
    /* Animation ndogo ya kufifia (Fade in) */
    animation: fadeIn 0.5s ease-in-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
        /* Media Queries kwa ajili ya Simu na Tablet */
        @media (max-width: 480px) {
            .ring { width: 400px; height: 400px; }
            .login { width: 300px; }
            .login h2 { font-size: 1.5em; }
        }
        @media (min-width: 481px) and (max-width: 768px) {
            .ring { width: 400px; height: 400px; }
            .login { width: 300px; }
        }
         </style>
</head>
<body>

<div class="ring">
        <i style="--clr:#07f70f;"></i>
        <i style="--clr:#3fc7fd;"></i>
        <i style="--clr:#1a34b7;"></i>


    <?php if (isset($_GET['msg'])): ?>
        <div class="msg-box"><?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>

    <form action="process_engine.php" method="POST" class="login" id="loginForm">
        <input type="hidden" name="action" value="login">
        <h2>Login</h2>
        <div class="inputBx"><input type="text" name="username" placeholder="Username" required></div>
<div class="inputBx">
        <input type="password" name="password" placeholder="Password" required> 
        <svg class="toggle-password" onclick="togglePass(this)" viewBox="0 0 24 24">
            <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
        </svg>
    </div>        <button type="submit">Sign in</button>
        <div class="links">
            <span onclick="showForm('forgotForm')" style="color: #ff4d4d;">Forgot Password?</span> | 
            <span onclick="showForm('signupForm')">Signup</span>
        </div>
    </form>

    <form action="process_engine.php" method="POST" class="login hidden" id="signupForm">
        <input type="hidden" name="action" value="signup">
        <h2>Signup</h2>
        <div class="inputBx"><input type="text" name="username" placeholder="Username" required></div>
        <div class="inputBx"><input type="email" name="email" placeholder="Email" required></div>
        <div class="inputBx"><input type="tel" name="phone" placeholder="Phone" required></div>
<div class="inputBx">
        <input type="password" name="password" placeholder="Password" required> 
        <svg class="toggle-password" onclick="togglePass(this)" viewBox="0 0 24 24">
            <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
        </svg>
    </div>        <button type="submit">Register</button>
        <div class="links"><span onclick="showForm('loginForm')">Back to Login</span></div>
    </form>

    <form action="process_engine.php" method="POST" class="login hidden" id="forgotForm">
        <input type="hidden" name="action" value="reset">
        <h2>Reset Password</h2>
        <div class="inputBx"><input type="text" name="username" placeholder="Username" required></div>
<div class="inputBx">
        <input type="password" name="password" placeholder="New Password" required> 
        <svg class="toggle-password" onclick="togglePass(this)" viewBox="0 0 24 24">
            <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
        </svg>
    </div>        <button type="submit">Update</button>
        <div class="links"><span onclick="showForm('loginForm')">Back to Login</span></div>
    </form>
</div>

<script>
    function showForm(formId) {
        document.getElementById('loginForm').classList.add('hidden');
        document.getElementById('signupForm').classList.add('hidden');
        document.getElementById('forgotForm').classList.add('hidden');
        document.getElementById(formId).classList.remove('hidden');
    }
       function togglePass(iconElement) {
    // Inatafuta input iliyo kwenye container moja na hii icon
    const container = iconElement.parentElement;
    const input = container.querySelector('input');
    
    if (input.type === "password") {
        input.type = "text";
        iconElement.style.fill = "#3fc7fd"; // Rangi ikifunguka
    } else {
        input.type = "password";
        iconElement.style.fill = "#fff"; // Rangi ikiwa imefungwa
    }
}
</script>

<footer class="footer">© <?php echo date('Y'); ?> Tech 5G Wi-Fi Billing System &nbsp;·&nbsp; Haki zote zimehifadhiwa</footer>

</body>
</html>