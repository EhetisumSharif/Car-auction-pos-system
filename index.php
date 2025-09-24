<?php
// PHP login logic remains completely unchanged.
// The file includes a database connection and handles the user session.
include('db_connect.php');
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Car Auction & Trading Login</title>
  <link rel="icon" type="image/jpeg" href="pic/logo.jpg">

  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Pacifico&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />

  <style>
    /* Custom CSS to handle the background image and centered content */
    body {
      font-family: 'Inter', sans-serif;
      background-image: url('pic/Background.jpg');
      background-size: cover;
      background-position: center;
      background-repeat: no-repeat;
      background-attachment: fixed;
      /* Use flexbox for centering the login box */
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      margin: 0;
      overflow: hidden; /* Prevent horizontal scroll */
    }

    .message-box {
      position: fixed;
      top: 20px;
      right: 20px;
      background-color: #f44336; /* Red for errors */
      color: white;
      padding: 15px;
      border-radius: 8px;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      z-index: 1000;
      transition: all 0.5s ease-in-out;
      opacity: 0;
      transform: translateY(-20px);
    }

    .message-box.show {
        opacity: 1;
        transform: translateY(0);
    }
  </style>
</head>
<body class="bg-gray-100 font-inter">

<?php
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  $un = trim($_POST['username']);
  $ps = trim($_POST['password']);

  $q = $db->prepare("SELECT * FROM users WHERE username = ?");
  $q->execute([$un]);
  $user = $q->fetch(PDO::FETCH_ASSOC);

  if ($user && $ps === $user['password']) {
    $_SESSION['un'] = $un;
    header("Location: home.php");
    exit();
  } else {
    // Show a styled error message
    echo '<div id="messageBox" class="message-box">Wrong Username or Password</div>';
  }
}
?>

<div class="flex flex-col items-center justify-center p-8 lg:p-12">
    <div class="bg-gray-100/90 p-8 rounded-xl shadow-2xl w-full max-w-md mx-4 transition-all duration-300 transform hover:scale-105">
      <img src="pic/logo.jpg" alt="Company Logo" class="mx-auto h-24 w-24 mb-4 rounded-full shadow-lg">
      <h2 class="text-4xl font-bold text-center text-gray-800 mb-2">Welcome</h2>
      <p class="text-center text-gray-600 mb-8 text-sm">Sign in to Car Auction & Trading Management System</p>

      <form action="" method="post" class="space-y-6">
        <div>
          <label for="username" class="block text-gray-700 text-sm font-medium mb-2">
            <i class="fas fa-user-circle mr-2"></i>Username
          </label>
          <input
            type="text"
            id="username"
            name="username"
            class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200"
            placeholder="Enter your username"
            required
          >
        </div>
        <div>
          <label for="password" class="block text-gray-700 text-sm font-medium mb-2">
            <i class="fas fa-lock mr-2"></i>Password
          </label>
          <input
            type="password"
            id="password"
            name="password"
            class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200"
            placeholder="Enter your password"
            required
          >
        </div>
        <button
          type="submit"
          class="w-full bg-blue-600 text-white py-3 rounded-xl font-semibold hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition duration-200 shadow-md transform hover:scale-105"
        >
          Login
        </button>
      </form>
    </div>
  </div>

<script>
    // A small script to show the message box with a fade-in effect
    window.onload = function() {
        const messageBox = document.getElementById('messageBox');
        if (messageBox) {
            setTimeout(() => {
                messageBox.classList.add('show');
            }, 100);
        }
    };
</script>

</body>
</html>